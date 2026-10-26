<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

require_once __DIR__ . '/PdoDurableJobs.php';

/** Owner-authenticated STOP and explicit, single-use resume foundation. */
final class PdoOwnerControl
{
    private const CHALLENGE_TTL_SECONDS = 300;

    /** @param callable():int|null $clock Testable Unix clock. */
    public function __construct(private readonly PDO $db, private readonly mixed $clock = null)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('MySQL/MariaDB InnoDB required for owner controls');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** STOP serializes against dispatch initiation and journals its state change atomically. */
    public function emergencyStop(): array
    {
        $now = $this->now();
        return $this->transaction(function () use ($now): array {
            $control = $this->one('SELECT operating_mode,owner_emergency_stopped,dispatch_epoch FROM kp_finance_control WHERE id=1 FOR UPDATE');
            if ($control === null) { throw new \RuntimeException('Finance control not initialized: fail closed'); }
            $wasStopped = (bool) $control['owner_emergency_stopped'];
            $epoch = (int) $control['dispatch_epoch'];
            if (!$wasStopped) {
                if ($epoch >= PHP_INT_MAX) { throw new \OverflowException('Dispatch epoch exhausted'); }
                $epoch++;
                $this->db->prepare('UPDATE kp_finance_control SET owner_emergency_stopped=1,dispatch_epoch=? WHERE id=1')->execute([$epoch]);
                PdoDurableJobs::requestStopInTransaction($this->db, $now);
            }
            $summary = $this->summary();
            $this->appendAudit($wasStopped ? 'stop_already_stopped' : 'stop_applied', $epoch, self::summaryHash($summary), $now);
            return $summary;
        });
    }

    /** @return array<string,int|string|bool> */
    public function summary(): array
    {
        $row = $this->one(
            "SELECT c.operating_mode,c.owner_emergency_stopped,c.dispatch_epoch,
                    p.settled_cash_cents,p.customer_liabilities_cents,p.tax_reserve_cents,
                    p.refund_reserve_cents,p.discretionary_reserved_cents,
                    (SELECT COUNT(*) FROM kp_spend_reservations r WHERE r.status IN ('reserved','uncertain')) AS open_reservation_count,
                    (SELECT COUNT(*) FROM kp_durable_jobs j WHERE j.status IN ('queued','leased','dispatching','cancel_requested','uncertain')) AS job_queue_actions
             FROM kp_finance_control c JOIN kp_financial_position p ON p.id=1 WHERE c.id=1"
        );
        if ($row === null) { throw new \RuntimeException('Owner control state unavailable: fail closed'); }
        return [
            'operating_mode' => (string) $row['operating_mode'],
            'emergency_stopped' => (bool) $row['owner_emergency_stopped'],
            'dispatch_epoch' => (int) $row['dispatch_epoch'],
            'settled_cash_cents' => (int) $row['settled_cash_cents'],
            'customer_liabilities_cents' => (int) $row['customer_liabilities_cents'],
            'tax_reserve_cents' => (int) $row['tax_reserve_cents'],
            'refund_reserve_cents' => (int) $row['refund_reserve_cents'],
            'discretionary_reserved_cents' => (int) $row['discretionary_reserved_cents'],
            'open_reservation_count' => (int) $row['open_reservation_count'],
            'job_queue_actions' => (int) $row['job_queue_actions'],
        ];
    }

    /** @return array{token:string,expires_epoch:int,summary:array<string,int|string|bool>} */
    public function prepareResume(string $sessionToken, string $csrfToken): array
    {
        $sessionHash = self::tokenHash($sessionToken);
        $now = $this->now();
        return $this->transaction(function () use ($sessionToken, $csrfToken, $sessionHash, $now): array {
            $control = $this->one('SELECT operating_mode,owner_emergency_stopped,dispatch_epoch FROM kp_finance_control WHERE id=1 FOR UPDATE');
            if ($control === null || !(bool) $control['owner_emergency_stopped']) {
                throw new \RuntimeException('System is not stopped');
            }
            if ($control['operating_mode'] === 'shutdown') { throw new \RuntimeException('Safe shutdown requires a separate reviewed recovery'); }
            $this->assertRecentSession($sessionToken, $csrfToken, $now);
            $summary = $this->summary();
            $token = self::base64UrlEncode(random_bytes(32));
            $stmt = $this->db->prepare('INSERT INTO kp_owner_resume_challenges (token_hash,session_hash,dispatch_epoch,summary_hash,created_epoch,expires_epoch) VALUES (?,?,?,?,?,?)');
            $expires = $now + self::CHALLENGE_TTL_SECONDS;
            $stmt->execute([hash('sha256', $token), $sessionHash, (int) $control['dispatch_epoch'], self::summaryHash($summary), $now, $expires]);
            $this->appendAudit('resume_challenge_issued', (int) $control['dispatch_epoch'], self::summaryHash($summary), $now);
            return ['token' => $token, 'expires_epoch' => $expires, 'summary' => $summary];
        });
    }

    /** Confirmation phrase and challenge are bound to a fresh MFA-created session and unchanged state. */
    public function confirmResume(string $sessionToken, string $csrfToken, string $challengeToken, string $confirmation): array
    {
        if ($confirmation !== 'RESUME KAMAU PUTT') { throw new \RuntimeException('Explicit resume confirmation required'); }
        $sessionHash = self::tokenHash($sessionToken);
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $challengeToken)) { throw new \RuntimeException('Invalid resume challenge'); }
        $challengeHash = hash('sha256', $challengeToken);
        $now = $this->now();
        return $this->transaction(function () use ($sessionToken, $csrfToken, $challengeHash, $sessionHash, $now): array {
            $control = $this->one('SELECT operating_mode,owner_emergency_stopped,dispatch_epoch FROM kp_finance_control WHERE id=1 FOR UPDATE');
            $challenge = $this->one('SELECT session_hash,dispatch_epoch,summary_hash,expires_epoch,consumed_epoch FROM kp_owner_resume_challenges WHERE token_hash=? FOR UPDATE', [$challengeHash]);
            $this->assertRecentSession($sessionToken, $csrfToken, $now);
            if ($control === null || $challenge === null || !(bool) $control['owner_emergency_stopped'] || $control['operating_mode'] === 'shutdown'
                || $challenge['consumed_epoch'] !== null || (int) $challenge['expires_epoch'] <= $now
                || !hash_equals((string) $challenge['session_hash'], $sessionHash)
                || (int) $challenge['dispatch_epoch'] !== (int) $control['dispatch_epoch']) {
                throw new \RuntimeException('Resume challenge is stale or unavailable');
            }
            $summary = $this->summary();
            if (!hash_equals((string) $challenge['summary_hash'], self::summaryHash($summary))) {
                throw new \RuntimeException('Financial state changed; review a fresh summary');
            }
            $epoch = (int) $control['dispatch_epoch'];
            if ($epoch >= PHP_INT_MAX) { throw new \OverflowException('Dispatch epoch exhausted'); }
            $epoch++;
            $stmt = $this->db->prepare('UPDATE kp_finance_control SET owner_emergency_stopped=0,dispatch_epoch=? WHERE id=1 AND owner_emergency_stopped=1');
            $stmt->execute([$epoch]);
            if ($stmt->rowCount() !== 1) { throw new \RuntimeException('Resume state changed concurrently'); }
            $this->db->prepare('UPDATE kp_owner_resume_challenges SET consumed_epoch=? WHERE token_hash=? AND consumed_epoch IS NULL')->execute([$now, $challengeHash]);
            $resumed = $this->summary();
            $this->appendAudit('resume_confirmed', $epoch, self::summaryHash($resumed), $now);
            return $resumed;
        });
    }

    /** Verify the complete append-only control-event hash chain. */
    public function verifyAudit(): array
    {
        $rows = $this->db->query('SELECT actor_owner_id,event_type,dispatch_epoch,summary_hash,occurred_epoch,previous_hash,event_hash FROM kp_owner_control_audit ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $previous = str_repeat('0', 64);
        foreach ($rows as $row) {
            if (!hash_equals($previous, (string) $row['previous_hash'])) { throw new \RuntimeException('Owner control audit chain is broken'); }
            $expected = self::auditHash($previous, (int) $row['actor_owner_id'], (string) $row['event_type'], (int) $row['dispatch_epoch'], (string) $row['summary_hash'], (int) $row['occurred_epoch']);
            if (!hash_equals($expected, (string) $row['event_hash'])) { throw new \RuntimeException('Owner control audit event was altered'); }
            $previous = (string) $row['event_hash'];
        }
        return ['events' => count($rows), 'last_hash' => $previous];
    }

    private function appendAudit(string $type, int $epoch, string $summaryHash, int $now): void
    {
        $row = $this->one('SELECT event_hash FROM kp_owner_control_audit ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $previous = $row === null ? str_repeat('0', 64) : (string) $row['event_hash'];
        $eventHash = self::auditHash($previous, 1, $type, $epoch, $summaryHash, $now);
        $statement = $this->db->prepare('INSERT INTO kp_owner_control_audit (actor_owner_id,event_type,dispatch_epoch,summary_hash,occurred_epoch,previous_hash,event_hash) VALUES (1,?,?,?,?,?,?)');
        $statement->execute([$type, $epoch, $summaryHash, $now, $previous, $eventHash]);
    }

    private static function auditHash(string $previous, int $actor, string $type, int $epoch, string $summaryHash, int $now): string
    {
        return hash('sha256', implode("\0", [$previous, (string) $actor, $type, (string) $epoch, $summaryHash, (string) $now]));
    }

    private function assertRecentSession(string $token, string $csrf, int $now): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $csrf)) {
            throw new \RuntimeException('Authenticated owner session required');
        }
        $hash = hash('sha256', $token);
        $row = $this->one('SELECT session_hash,csrf_hash,created_epoch,last_seen_epoch,expires_epoch,revoked_epoch FROM kp_owner_sessions WHERE session_hash=? FOR UPDATE', [$hash]);
        if ($row === null || $row['revoked_epoch'] !== null || (int) $row['created_epoch'] < $now - self::CHALLENGE_TTL_SECONDS
            || (int) $row['expires_epoch'] <= $now || $now - (int) $row['last_seen_epoch'] > 1800
            || !hash_equals((string) $row['csrf_hash'], hash('sha256', $csrf))) {
            throw new \RuntimeException('A fresh authenticated owner session is required');
        }
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
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

    private static function tokenHash(string $token): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) { throw new \RuntimeException('Authenticated owner session required'); }
        return hash('sha256', $token);
    }

    private static function summaryHash(array $summary): string
    {
        return hash('sha256', json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
