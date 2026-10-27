<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

/** Encrypted provider key storage and owner-approved lifecycle. No network calls. */
final class PdoCredentialVault
{
    /** @param callable():int|null $clock Testable Unix clock. */
    public function __construct(private readonly PDO $db, private readonly string $encryptionKey, private readonly mixed $clock = null)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL/MariaDB required'); }
        if (!function_exists('sodium_crypto_secretbox') || strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Vault encryption key must be exactly 32 bytes');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Store a provider key encrypted and inactive. The clear key is never returned or logged. */
    public function storePending(string $sessionToken, string $csrfToken, string $provider, string $label, string $secret): array
    {
        $now = $this->now();
        $provider = strtolower(trim($provider));
        $label = trim($label);
        $secret = trim($secret);
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,63}$/D', $provider) || $label === '' || strlen($label) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $label) || strlen($secret) < 16 || strlen($secret) > 2048
            || preg_match('/[^\x21-\x7e]/', $secret)) {
            throw new \InvalidArgumentException('Provider credential fields are invalid');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $this->encryptionKey);
        $fingerprintKey = hash_hmac('sha256', 'kamau-putt/provider-credential-fingerprint/v1', $this->encryptionKey, true);
        $fingerprint = hash_hmac('sha256', $provider . "\0" . $secret, $fingerprintKey);
        $suffix = substr($secret, -4);
        try {
            return $this->transaction(function () use ($sessionToken, $csrfToken, $provider, $label, $nonce, $ciphertext, $fingerprint, $suffix, $now): array {
                $this->assertSession($sessionToken, $csrfToken, $now, false);
                $this->lockOwner();
                $stmt = $this->db->prepare('INSERT INTO kp_provider_credentials (provider_slug,label,fingerprint_hash,secret_nonce,secret_ciphertext,masked_suffix,created_epoch) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$provider, $label, $fingerprint, $nonce, $ciphertext, $suffix, $now]);
                $id = (int) $this->db->lastInsertId();
                $this->appendEvent($id, 'stored_pending', ['label' => $label, 'provider' => $provider], $now);
                return ['id' => $id, 'provider' => $provider, 'label' => $label, 'masked_key' => '••••' . $suffix, 'status' => 'pending', 'test_status' => 'untested'];
            });
        } catch (\PDOException $error) {
            if (($error->errorInfo[0] ?? null) === '23000') { throw new \RuntimeException('Credential already exists', 0, $error); }
            throw $error;
        }
    }

    /** Metadata only. Ciphertext, nonce and fingerprint never leave this service. */
    public function listForOwner(string $sessionToken, string $csrfToken): array
    {
        $this->assertSession($sessionToken, $csrfToken, $this->now(), false);
        $credentials = $this->db->query('SELECT id,provider_slug,label,masked_suffix,created_epoch FROM kp_provider_credentials ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $output = [];
        foreach ($credentials as $credential) {
            $events = $this->eventsFor((int) $credential['id']);
            $latest = end($events);
            $state = self::stateFromEvents($events);
            $output[] = [
                'id' => (int) $credential['id'],
                'provider' => (string) $credential['provider_slug'],
                'label' => (string) $credential['label'],
                'masked_key' => '••••' . (string) $credential['masked_suffix'],
                'status' => $state['status'],
                'test_status' => $state['test_status'],
                'test_report' => $state['test_report'],
                'created_epoch' => (int) $credential['created_epoch'],
                'last_event_epoch' => $latest === false ? null : (int) $latest['occurred_epoch'],
            ];
        }
        return $output;
    }

    /** Trusted provider smoke-test adapter only; no HTTP route exposes this transition. */
    public function recordSmokeTest(int $credentialId, bool $success, string $providerRequestId, int $latencyMs): void
    {
        if ($credentialId < 1 || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $providerRequestId) || $latencyMs < 1 || $latencyMs > 15_000) {
            throw new \InvalidArgumentException('Provider test result is invalid');
        }
        $now = $this->now();
        $this->transaction(function () use ($credentialId, $success, $providerRequestId, $latencyMs, $now): void {
            $this->lockOwner();
            $credential = $this->one('SELECT id FROM kp_provider_credentials WHERE id=?', [$credentialId]);
            if ($credential === null) { throw new \RuntimeException('Credential unavailable'); }
            $state = self::stateFromEvents($this->eventsFor($credentialId));
            if (!in_array($state['status'], ['pending', 'tested'], true)) { throw new \RuntimeException('Credential cannot be tested in its current state'); }
            $type = $success ? 'smoke_test_passed' : 'smoke_test_failed';
            $this->appendEvent($credentialId, $type, [
                'latency_ms' => $latencyMs,
                'provider_request_id' => $providerRequestId,
            ], $now);
        });
    }

    /** Fresh MFA-created session plus exact phrase is required for owner activation. */
    public function activate(string $sessionToken, string $csrfToken, int $credentialId, string $confirmation): void
    {
        if ($confirmation !== 'ACTIVATE CREDENTIAL') { throw new \RuntimeException('Explicit activation confirmation required'); }
        $now = $this->now();
        $this->transaction(function () use ($sessionToken, $csrfToken, $credentialId, $now): void {
            $this->assertSession($sessionToken, $csrfToken, $now, true);
            $this->lockOwner();
            $credential = $this->one('SELECT id FROM kp_provider_credentials WHERE id=? FOR UPDATE', [$credentialId]);
            if ($credential === null) { throw new \RuntimeException('Credential unavailable'); }
            $state = self::stateFromEvents($this->eventsFor($credentialId));
            if ($state['status'] !== 'tested' || $state['test_status'] !== 'passed' || $state['tested_epoch'] < $now - 300) {
                throw new \RuntimeException('A passing provider smoke test is required before activation');
            }
            $this->appendEvent($credentialId, 'activated', [], $now);
        });
    }

    /** Revoke is fail-closed and leaves immutable ciphertext/audit evidence for incident review. */
    public function revoke(string $sessionToken, string $csrfToken, int $credentialId): void
    {
        $now = $this->now();
        $this->transaction(function () use ($sessionToken, $csrfToken, $credentialId, $now): void {
            $this->assertSession($sessionToken, $csrfToken, $now, false);
            $this->lockOwner();
            $credential = $this->one('SELECT id FROM kp_provider_credentials WHERE id=? FOR UPDATE', [$credentialId]);
            if ($credential === null) { throw new \RuntimeException('Credential unavailable'); }
            $state = self::stateFromEvents($this->eventsFor($credentialId));
            if ($state['status'] === 'revoked') { return; }
            $this->appendEvent($credentialId, 'revoked', [], $now);
        });
    }

    /** Trusted provider adapter only; live callers must also honor STOP/outbox fencing. */
    public function workerMetadata(int $credentialId): array
    {
        if ($credentialId < 1) { throw new \InvalidArgumentException('Credential identifier is invalid'); }
        $row = $this->one('SELECT provider_slug FROM kp_provider_credentials WHERE id=?', [$credentialId]);
        if ($row === null) { return ['provider' => '', 'status' => 'unavailable']; }
        $state = self::stateFromEvents($this->eventsFor($credentialId));
        return ['provider' => (string) $row['provider_slug'], 'status' => (string) $state['status']];
    }

    /** Trusted provider adapter only; live callers must also honor STOP/outbox fencing. */
    public function withActiveSecret(int $credentialId, callable $operation): mixed
    {
        $row = $this->one('SELECT provider_slug,secret_nonce,secret_ciphertext FROM kp_provider_credentials WHERE id=?', [$credentialId]);
        if ($row === null || self::stateFromEvents($this->eventsFor($credentialId))['status'] !== 'active') {
            throw new \RuntimeException('Credential is not active');
        }
        $secret = sodium_crypto_secretbox_open((string) $row['secret_ciphertext'], (string) $row['secret_nonce'], $this->encryptionKey);
        if (!is_string($secret)) { throw new \RuntimeException('Credential decryption failed: fail closed'); }
        try { return $operation((string) $row['provider_slug'], $secret); }
        finally { sodium_memzero($secret); }
    }

    public function verifyAudit(): array
    {
        $rows = $this->db->query('SELECT credential_id,actor_owner_id,event_type,detail_json,occurred_epoch,previous_hash,event_hash FROM kp_credential_vault_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $previous = str_repeat('0', 64);
        foreach ($rows as $row) {
            if (!hash_equals($previous, (string) $row['previous_hash'])) { throw new \RuntimeException('Credential vault audit chain is broken'); }
            $detail = json_decode((string) $row['detail_json'], true, 32, JSON_THROW_ON_ERROR);
            $expected = self::eventHash($previous, (int) $row['credential_id'], (int) $row['actor_owner_id'], (string) $row['event_type'], $detail, (int) $row['occurred_epoch']);
            if (!hash_equals($expected, (string) $row['event_hash'])) { throw new \RuntimeException('Credential vault audit event was altered'); }
            $previous = (string) $row['event_hash'];
        }
        return ['events' => count($rows), 'last_hash' => $previous];
    }

    private function appendEvent(int $credentialId, string $type, array $details, int $now): void
    {
        $json = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $row = $this->one('SELECT event_hash FROM kp_credential_vault_events ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $previous = $row === null ? str_repeat('0', 64) : (string) $row['event_hash'];
        $eventHash = self::eventHash($previous, $credentialId, 1, $type, $details, $now);
        $stmt = $this->db->prepare('INSERT INTO kp_credential_vault_events (credential_id,actor_owner_id,event_type,detail_json,occurred_epoch,previous_hash,event_hash) VALUES (?,1,?,?,?,?,?)');
        $stmt->execute([$credentialId, $type, $json, $now, $previous, $eventHash]);
    }

    private function eventsFor(int $credentialId): array
    {
        $stmt = $this->db->prepare('SELECT event_type,detail_json,occurred_epoch FROM kp_credential_vault_events WHERE credential_id=? ORDER BY id');
        $stmt->execute([$credentialId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function stateFromEvents(array $events): array
    {
        $status = 'unavailable';
        $testStatus = 'untested';
        $testedEpoch = 0;
        $testReport = null;
        foreach ($events as $event) {
            switch ($event['event_type']) {
                case 'stored_pending': $status = 'pending'; $testStatus = 'untested'; $testedEpoch = 0; $testReport = null; break;
                case 'smoke_test_passed':
                    $detail = json_decode((string) $event['detail_json'], true, 32, JSON_THROW_ON_ERROR);
                    $status = 'tested'; $testStatus = 'passed'; $testedEpoch = (int) $event['occurred_epoch']; $testReport = $detail;
                    break;
                case 'smoke_test_failed':
                    $detail = json_decode((string) $event['detail_json'], true, 32, JSON_THROW_ON_ERROR);
                    $status = 'pending'; $testStatus = 'failed'; $testedEpoch = (int) $event['occurred_epoch']; $testReport = $detail;
                    break;
                case 'activated': $status = 'active'; break;
                case 'revoked': $status = 'revoked'; break;
            }
        }
        return ['status' => $status, 'test_status' => $testStatus, 'tested_epoch' => $testedEpoch, 'test_report' => $testReport];
    }

    private function assertSession(string $token, string $csrf, int $now, bool $recent): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $csrf)) {
            throw new \RuntimeException('Authenticated owner session required');
        }
        $row = $this->one('SELECT session_hash,csrf_hash,created_epoch,last_seen_epoch,expires_epoch,revoked_epoch FROM kp_owner_sessions WHERE session_hash=? FOR UPDATE', [hash('sha256', $token)]);
        if ($row === null || $row['revoked_epoch'] !== null || (int) $row['expires_epoch'] <= $now
            || $now - (int) $row['last_seen_epoch'] > 1800 || !hash_equals((string) $row['csrf_hash'], hash('sha256', $csrf))
            || ($recent && (int) $row['created_epoch'] < $now - 300)) {
            throw new \RuntimeException($recent ? 'Fresh MFA session required' : 'Authenticated owner session required');
        }
        return $row;
    }

    private function lockOwner(): void
    {
        if ($this->one('SELECT id FROM kp_owner_accounts WHERE id=1 FOR UPDATE') === null) {
            throw new \RuntimeException('Owner account is not provisioned');
        }
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
        try { $result = $operation(); $this->db->commit(); return $result; }
        catch (Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $error; }
    }

    private function now(): int
    {
        $value = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($value) || $value < 0) { throw new \RuntimeException('Invalid system clock'); }
        return $value;
    }

    private static function eventHash(string $previous, int $credentialId, int $actor, string $type, array $details, int $now): string
    {
        return hash('sha256', implode("\0", [$previous, (string) $credentialId, (string) $actor, $type, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) $now]));
    }
}
