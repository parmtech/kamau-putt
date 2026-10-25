<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

/** Owner-only password + encrypted TOTP + opaque session foundation. */
final class PdoOwnerAuth
{
    private const SESSION_IDLE_SECONDS = 1800;
    private const SESSION_ABSOLUTE_SECONDS = 43200;

    /** @param callable():int|null $clock Testable Unix clock; production uses time(). */
    public function __construct(
        private readonly PDO $db,
        private readonly string $mfaEncryptionKey,
        private readonly string $rateLimitHmacKey,
        private readonly mixed $clock = null,
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('MySQL/MariaDB required');
        }
        if (!function_exists('sodium_crypto_secretbox') || strlen($mfaEncryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || strlen($rateLimitHmacKey) < 32) {
            throw new \InvalidArgumentException('Authentication keys have invalid length');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function generateTotpSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** First-owner setup is CLI-only and succeeds only while the owner table is empty. */
    public function bootstrapOwner(string $email, string $password, string $totpSecret): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Owner bootstrap is CLI-only');
        }
        $email = self::normalizeEmail($email);
        if (strlen($password) < 15 || strlen($password) > 200) {
            throw new \InvalidArgumentException('Owner password must contain 15 to 200 bytes');
        }
        $secret = self::base32Decode($totpSecret);
        if (strlen($secret) < 20) {
            throw new \InvalidArgumentException('TOTP secret must decode to at least 160 bits');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $this->mfaEncryptionKey);
        $this->transaction(function () use ($email, $password, $nonce, $ciphertext): void {
            $lock = $this->db->query('SELECT id FROM kp_owner_accounts WHERE id = 1 FOR UPDATE');
            if ($lock->fetchColumn() !== false) {
                throw new \RuntimeException('Owner account is already provisioned');
            }
            $stmt = $this->db->prepare('INSERT INTO kp_owner_accounts (id,email,password_hash,totp_nonce,totp_ciphertext) VALUES (1,?,?,?,?)');
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) { throw new \RuntimeException('Could not hash owner password'); }
            $stmt->execute([$email, $passwordHash, $nonce, $ciphertext]);
        });
    }

    /** @return array{authenticated:bool,reason:string,session_token?:string,csrf_token?:string} */
    public function authenticate(string $email, string $password, string $totpCode, string $trustedRemoteAddress): array
    {
        try {
            $email = self::normalizeEmail($email);
        } catch (\InvalidArgumentException) {
            $email = 'invalid@example.invalid';
        }
        if ($password === '' || strlen($password) > 200 || !preg_match('/^\d{6}$/D', $totpCode)) {
            return ['authenticated' => false, 'reason' => 'invalid_credentials'];
        }
        $address = filter_var($trustedRemoteAddress, FILTER_VALIDATE_IP) ? $trustedRemoteAddress : 'unknown';
        $attemptKey = hash_hmac('sha256', $email . "\0" . $address, $this->rateLimitHmacKey);
        $now = $this->now();

        return $this->transaction(function () use ($email, $password, $totpCode, $attemptKey, $now): array {
            $insert = $this->db->prepare('INSERT IGNORE INTO kp_owner_auth_attempts (attempt_key,window_started_epoch) VALUES (?,?)');
            $insert->execute([$attemptKey, $now]);
            $attempt = $this->one('SELECT failure_count,window_started_epoch,blocked_until_epoch FROM kp_owner_auth_attempts WHERE attempt_key=? FOR UPDATE', [$attemptKey]);
            if ($attempt !== null && (int) $attempt['blocked_until_epoch'] > $now) {
                return ['authenticated' => false, 'reason' => 'rate_limited'];
            }

            $account = $this->one('SELECT id,email,password_hash,totp_nonce,totp_ciphertext,last_totp_step FROM kp_owner_accounts WHERE email=? FOR UPDATE', [$email]);
            $hash = $account['password_hash'] ?? self::dummyPasswordHash();
            $passwordValid = password_verify($password, $hash);
            $step = null;
            if ($account !== null && $passwordValid) {
                $secret = sodium_crypto_secretbox_open((string) $account['totp_ciphertext'], (string) $account['totp_nonce'], $this->mfaEncryptionKey);
                if (is_string($secret)) {
                    $step = self::verifyTotp($secret, $totpCode, $now);
                }
            } else {
                // Do a fixed HMAC operation on the denial path to reduce timing clues.
                hash_hmac('sha1', pack('N2', 0, intdiv($now, 30)), str_repeat("\0", 20), true);
            }
            if (!$passwordValid || $account === null || $step === null || $step <= (int) $account['last_totp_step']) {
                $this->recordFailure($attemptKey, $attempt, $now);
                return ['authenticated' => false, 'reason' => 'invalid_credentials'];
            }

            $token = self::base64UrlEncode(random_bytes(32));
            $csrf = self::base64UrlEncode(random_bytes(32));
            $sessionHash = hash('sha256', $token);
            $csrfHash = hash('sha256', $csrf);
            $stmt = $this->db->prepare('INSERT INTO kp_owner_sessions (session_hash,csrf_hash,created_epoch,last_seen_epoch,expires_epoch) VALUES (?,?,?,?,?)');
            $stmt->execute([$sessionHash, $csrfHash, $now, $now, $now + self::SESSION_ABSOLUTE_SECONDS]);
            $this->db->prepare('UPDATE kp_owner_accounts SET last_totp_step=? WHERE id=1')->execute([$step]);
            $this->db->prepare('DELETE FROM kp_owner_auth_attempts WHERE attempt_key=?')->execute([$attemptKey]);
            return ['authenticated' => true, 'reason' => 'ok', 'session_token' => $token, 'csrf_token' => $csrf];
        });
    }

    /** @return array{id:int,email:string}|null */
    public function validateSession(string $token, string $csrfToken): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $csrfToken)) {
            return null;
        }
        $now = $this->now();
        $row = $this->one(
            'SELECT s.session_hash,s.csrf_hash,s.created_epoch,s.last_seen_epoch,s.expires_epoch,a.id,a.email FROM kp_owner_sessions s JOIN kp_owner_accounts a ON a.id=1 WHERE s.session_hash=? AND s.revoked_epoch IS NULL',
            [hash('sha256', $token)]
        );
        if ($row === null || !hash_equals((string) $row['csrf_hash'], hash('sha256', $csrfToken))) {
            return null;
        }
        if ((int) $row['expires_epoch'] <= $now || $now - (int) $row['last_seen_epoch'] > self::SESSION_IDLE_SECONDS || $now - (int) $row['created_epoch'] > self::SESSION_ABSOLUTE_SECONDS) {
            $this->revokeByHash((string) $row['session_hash'], $now);
            return null;
        }
        if ($now - (int) $row['last_seen_epoch'] >= 60) {
            $this->db->prepare('UPDATE kp_owner_sessions SET last_seen_epoch=? WHERE session_hash=? AND revoked_epoch IS NULL')->execute([$now, $row['session_hash']]);
        }
        return ['id' => (int) $row['id'], 'email' => (string) $row['email']];
    }

    public function revokeSession(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            $this->revokeByHash(hash('sha256', $token), $this->now());
        }
    }

    private function recordFailure(string $key, ?array $attempt, int $now): void
    {
        $window = $attempt === null || $now - (int) $attempt['window_started_epoch'] >= 900;
        $count = $window ? 1 : (int) $attempt['failure_count'] + 1;
        $start = $window ? $now : (int) $attempt['window_started_epoch'];
        $blocked = $count < 5 ? 0 : $now + min(900, 60 * (2 ** min(4, $count - 5)));
        $stmt = $this->db->prepare('UPDATE kp_owner_auth_attempts SET failure_count=?,window_started_epoch=?,blocked_until_epoch=? WHERE attempt_key=?');
        $stmt->execute([$count, $start, $blocked, $key]);
    }

    private function revokeByHash(string $hash, int $now): void
    {
        $stmt = $this->db->prepare('UPDATE kp_owner_sessions SET revoked_epoch=? WHERE session_hash=? AND revoked_epoch IS NULL');
        $stmt->execute([$now, $hash]);
    }

    private function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) { throw new \RuntimeException('Nested transactions are unsupported'); }
        $this->db->beginTransaction();
        try { $value = $operation(); $this->db->commit(); return $value; }
        catch (Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $error; }
    }

    private function now(): int
    {
        $value = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($value) || $value < 0) { throw new \RuntimeException('Invalid system clock'); }
        return $value;
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[^\x20-\x7E]/', $email)) {
            throw new \InvalidArgumentException('Valid ASCII email required');
        }
        return $email;
    }

    private static function verifyTotp(string $secret, string $code, int $now): ?int
    {
        $current = intdiv($now, 30);
        $match = null;
        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($step < 0) { continue; }
            if (hash_equals(self::totpCode($secret, $step), $code)) { $match = max($match ?? 0, $step); }
        }
        return $match;
    }

    private static function totpCode(string $secret, int $step): string
    {
        $counter = pack('N2', intdiv($step, 0x100000000), $step % 0x100000000);
        $hash = hash_hmac('sha1', $counter, $secret, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $text): string
    {
        $text = strtoupper(rtrim($text, '='));
        if ($text === '' || !preg_match('/^[A-Z2-7]+$/D', $text)) { throw new \InvalidArgumentException('Invalid base32 TOTP secret'); }
        $bits = '';
        foreach (str_split($text) as $char) { $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT); }
        $output = '';
        foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $output .= chr(bindec($byte)); } }
        return $output;
    }

    private static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $byte) { $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) { $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))]; }
        return $out;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function dummyPasswordHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash('dummy-only-password-value', PASSWORD_DEFAULT);
    }
}
