<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

require_once __DIR__ . '/FinancialMath.php';
require_once __DIR__ . '/SpendDecision.php';
require_once __DIR__ . '/SpendGuard.php';
require_once __DIR__ . '/PdoDoubleEntryLedger.php';
require_once __DIR__ . '/PdoSpendReservations.php';

/**
 * Durable reservation-backed outbox foundation. Provider I/O runs outside DB
 * transactions; a lost or ambiguous result becomes uncertain and is never retried.
 */
final class PdoDurableJobs
{
    public const LEASE_SECONDS = 30;
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @param callable():int|null $clock Testable Unix clock. */
    public function __construct(private readonly PDO $db, private readonly mixed $clock = null)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL/MariaDB InnoDB required'); }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Create one durable intent for an existing reserved spend; payload_ref must be opaque. */
    public function enqueue(string $reservationKey, string $jobKey, string $payloadRef): array
    {
        if (!self::validKey($reservationKey) || !self::validKey($jobKey) || !preg_match('/^[A-Za-z0-9._:-]{8,191}$/D', $payloadRef)) {
            throw new \InvalidArgumentException('Durable job identity is invalid');
        }
        $now = $this->now();
        return $this->transaction(function () use ($reservationKey, $jobKey, $payloadRef, $now): array {
            $control = $this->lockControl();
            if ((bool) $control['owner_emergency_stopped'] || $control['operating_mode'] !== 'growth') { throw new \RuntimeException('Dispatch is stopped'); }
            $payload = $this->one('SELECT payload_ref FROM kp_job_payloads WHERE payload_ref=? FOR UPDATE', [$payloadRef]);
            if ($payload === null) { throw new \RuntimeException('Encrypted job payload must be stored before enqueue'); }
            $reservation = $this->one('SELECT id,action_type,status,authorization_epoch FROM kp_spend_reservations WHERE idempotency_key=? FOR UPDATE', [$reservationKey]);
            if ($reservation === null || $reservation['status'] !== 'reserved' || (int) $reservation['authorization_epoch'] !== (int) $control['dispatch_epoch']) {
                throw new \RuntimeException('A current reserved spend is required');
            }
            $existing = $this->one('SELECT id,job_key,payload_ref,status FROM kp_durable_jobs WHERE reservation_id=? OR job_key=? FOR UPDATE', [$reservation['id'], $jobKey]);
            if ($existing !== null) {
                if ($existing['job_key'] === $jobKey && $existing['payload_ref'] === $payloadRef && $existing['status'] === 'queued') {
                    return ['id' => (string) $existing['id'], 'job_key' => (string) $existing['job_key'], 'status' => 'queued', 'idempotent' => true];
                }
                throw new \RuntimeException('Reservation or job key already has an outbox entry');
            }
            if ($this->one('SELECT id FROM kp_durable_jobs WHERE payload_ref=? FOR UPDATE', [$payloadRef]) !== null) {
                throw new \RuntimeException('An encrypted payload can be used by only one durable job');
            }
            $id = self::uuidV4();
            $stmt = $this->db->prepare('INSERT INTO kp_durable_jobs(id,job_key,reservation_id,action_type,payload_ref,authorization_epoch,status,created_epoch,updated_epoch) VALUES(?,?,?,?,?,?,\'queued\',?,?)');
            $stmt->execute([$id, $jobKey, $reservation['id'], $reservation['action_type'], $payloadRef, (int) $reservation['authorization_epoch'], $now, $now]);
            $this->appendEvent($id, 'system', null, 'queued', ['action_type' => (string) $reservation['action_type']], $now);
            return ['id' => $id, 'job_key' => $jobKey, 'status' => 'queued', 'idempotent' => false];
        });
    }

    /** Lease one eligible intent. Expired work becomes uncertain; it is never retried. */
    public function claimNext(): ?array
    {
        $now = $this->now();
        return $this->transaction(function () use ($now): ?array {
            $control = $this->lockControl();
            $this->recoverExpiredLeases($now);
            if ((bool) $control['owner_emergency_stopped'] || $control['operating_mode'] !== 'growth') { return null; }
            $job = $this->one("SELECT id,job_key,reservation_id,action_type,payload_ref,authorization_epoch FROM kp_durable_jobs WHERE status='queued' AND authorization_epoch=? ORDER BY created_epoch,id LIMIT 1 FOR UPDATE", [(int) $control['dispatch_epoch']]);
            if ($job === null) { return null; }
            $reservation = $this->one('SELECT status,authorization_epoch FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
            if ($reservation === null || $reservation['status'] !== 'reserved' || (int) $reservation['authorization_epoch'] !== (int) $control['dispatch_epoch']) {
                $this->updateJob((string) $job['id'], 'canceled', null, null, null, $now);
                $this->appendEvent((string) $job['id'], 'system', null, 'stop_canceled', ['reason' => 'reservation_not_current'], $now);
                return null;
            }
            $token = self::base64UrlEncode(random_bytes(32));
            $expires = $now + self::LEASE_SECONDS;
            $this->updateJob((string) $job['id'], 'leased', hash('sha256', $token), $expires, null, $now, true);
            $this->appendEvent((string) $job['id'], 'worker', null, 'lease_issued', ['expires_epoch' => $expires], $now);
            return [
                'id' => (string) $job['id'], 'job_key' => (string) $job['job_key'],
                'action_type' => (string) $job['action_type'], 'payload_ref' => (string) $job['payload_ref'],
                'lease_token' => $token, 'lease_expires_epoch' => $expires,
                'provider_idempotency_key' => 'kp-' . hash('sha256', (string) $job['job_key']),
                'authorization_epoch' => (int) $job['authorization_epoch'],
            ];
        });
    }

    /** Authorize an attempt in a short transaction, perform provider I/O unlocked, then settle it. */
    public function dispatch(string $jobId, string $leaseToken, callable $providerCall): array
    {
        if (!self::validUuid($jobId) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $leaseToken)) { return ['dispatched' => false, 'reason' => 'invalid_lease']; }
        $leaseHash = hash('sha256', $leaseToken);
        $attempt = $this->transaction(function () use ($jobId, $leaseHash): array {
            $now = $this->now();
            $control = $this->lockControl();
            $job = $this->one('SELECT job_key,reservation_id,action_type,payload_ref,authorization_epoch,status,lease_token_hash,lease_expires_epoch FROM kp_durable_jobs WHERE id=? FOR UPDATE', [$jobId]);
            if ($job === null || $job['status'] !== 'leased' || !is_string($job['lease_token_hash']) || !hash_equals((string) $job['lease_token_hash'], $leaseHash) || (int) $job['lease_expires_epoch'] <= $now) { return ['started' => false, 'reason' => 'lease_unavailable']; }
            if ((bool) $control['owner_emergency_stopped'] || $control['operating_mode'] !== 'growth' || (int) $job['authorization_epoch'] !== (int) $control['dispatch_epoch']) {
                $reservation = $this->one('SELECT idempotency_key,status FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
                if ($reservation !== null && $reservation['status'] === 'reserved') { (new PdoSpendReservations($this->db))->transitionInTransaction((string) $reservation['idempotency_key'], 'released'); }
                $this->updateJob($jobId, 'canceled', null, null, null, $now);
                $this->appendEvent($jobId, 'system', null, 'dispatch_rejected', ['reason' => 'dispatch_fenced_before_start'], $now);
                return ['started' => false, 'reason' => 'dispatch_fenced'];
            }
            $reservation = $this->one('SELECT idempotency_key,status,authorization_epoch,amount_cents FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
            if ($reservation === null) { throw new \RuntimeException('Outbox reservation is missing'); }
            if ($reservation['status'] !== 'reserved' || (int) $reservation['authorization_epoch'] !== (int) $control['dispatch_epoch']) {
                $next = $reservation['status'] === 'uncertain' ? 'uncertain' : 'canceled';
                if ($reservation['status'] === 'reserved') { (new PdoSpendReservations($this->db))->transitionInTransaction((string) $reservation['idempotency_key'], 'released'); }
                $this->updateJob($jobId, $next, null, null, null, $now);
                $this->appendEvent($jobId, 'system', null, 'dispatch_rejected', ['reason' => 'reservation_not_current'], $now);
                return ['started' => false, 'reason' => 'reservation_not_dispatchable'];
            }
            $attemptId = self::uuidV4();
            // The claim lease may have been mostly consumed before this worker started.
            // Begin a fresh bounded attempt deadline at the authorization boundary.
            $attemptDeadline = $now + self::LEASE_SECONDS;
            $stmt = $this->db->prepare("UPDATE kp_durable_jobs SET status='dispatching',dispatch_attempt_id=?,lease_expires_epoch=?,updated_epoch=? WHERE id=? AND status='leased'");
            $stmt->execute([$attemptId, $attemptDeadline, $now, $jobId]);
            if ($stmt->rowCount() !== 1) { throw new \RuntimeException('Durable job changed before dispatch start'); }
            $this->appendEvent($jobId, 'worker', null, 'dispatch_started', ['attempt_id' => $attemptId, 'deadline_epoch' => $attemptDeadline], $now);
            return ['started' => true, 'attempt_id' => $attemptId, 'attempt_deadline_epoch' => $attemptDeadline, 'job_key' => (string) $job['job_key'], 'action_type' => (string) $job['action_type'], 'payload_ref' => (string) $job['payload_ref'], 'authorization_epoch' => (int) $job['authorization_epoch'], 'reservation_amount_cents' => (int) $reservation['amount_cents']];
        });
        if (!$attempt['started']) { return ['dispatched' => false, 'reason' => $attempt['reason']]; }

        // Recheck after the commit in case this worker was paused at the authorization boundary.
        // A real adapter must also check immediately before network send and enforce a shorter timeout.
        if ($this->now() >= (int) $attempt['attempt_deadline_epoch']) {
            $outcome = ['outcome' => 'no_effect'];
        } else {
            try {
                $outcome = $providerCall([
                    'action_type' => $attempt['action_type'], 'payload_ref' => $attempt['payload_ref'],
                    'provider_idempotency_key' => 'kp-' . hash('sha256', $attempt['job_key']),
                    'authorization_epoch' => $attempt['authorization_epoch'], 'attempt_id' => $attempt['attempt_id'],
                    'attempt_deadline_epoch' => $attempt['attempt_deadline_epoch'],
                    'reservation_amount_cents' => $attempt['reservation_amount_cents'],
                ]);
            } catch (Throwable) { $outcome = ['outcome' => 'unknown']; }
        }
        if (!is_array($outcome) || !in_array($outcome['outcome'] ?? null, ['accepted', 'no_effect', 'unknown'], true)) { $outcome = ['outcome' => 'unknown']; }
        if (($outcome['outcome'] ?? null) === 'accepted' && (!is_string($outcome['reference'] ?? null) || $outcome['reference'] === '' || strlen($outcome['reference']) > 255)) { $outcome = ['outcome' => 'unknown']; }
        return $this->settleDispatch($jobId, (string) $attempt['attempt_id'], $outcome);
    }

    /** Persist a provider result for the exact authorized attempt, including after STOP. */
    private function settleDispatch(string $jobId, string $attemptId, array $outcome): array
    {
        $now = $this->now();
        return $this->transaction(function () use ($jobId, $attemptId, $outcome, $now): array {
            $this->lockControl();
            $job = $this->one('SELECT reservation_id,status,dispatch_attempt_id,external_reference FROM kp_durable_jobs WHERE id=? FOR UPDATE', [$jobId]);
            if ($job === null || !is_string($job['dispatch_attempt_id']) || !hash_equals((string) $job['dispatch_attempt_id'], $attemptId)) { return ['dispatched' => false, 'reason' => 'attempt_unavailable']; }
            $kind = (string) $outcome['outcome'];
            if ($job['status'] === 'completed') { return $kind === 'accepted' && ($job['external_reference'] ?? null) === ($outcome['reference'] ?? null) ? ['dispatched' => true, 'status' => 'completed', 'idempotent' => true] : ['dispatched' => false, 'reason' => 'attempt_already_settled']; }
            if ($job['status'] === 'failed') { return $kind === 'no_effect' ? ['dispatched' => true, 'status' => 'failed_no_effect', 'idempotent' => true] : ['dispatched' => false, 'reason' => 'attempt_already_settled']; }
            if (!in_array($job['status'], ['dispatching', 'cancel_requested', 'uncertain'], true)) { return ['dispatched' => false, 'reason' => 'attempt_unavailable']; }
            $reservation = $this->one('SELECT idempotency_key,status FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
            if ($reservation === null) { throw new \RuntimeException('Outbox reservation is missing'); }
            $reservations = new PdoSpendReservations($this->db);
            if ($kind === 'accepted') {
                $reference = (string) $outcome['reference'];
                $reservations->transitionInTransaction((string) $reservation['idempotency_key'], 'committed', $reference);
                $this->updateJob($jobId, 'completed', null, null, $reference, $now);
                $this->appendEvent($jobId, 'worker', null, 'provider_completed', ['attempt_id' => $attemptId, 'reference_hash' => hash('sha256', $reference)], $now);
                return ['dispatched' => true, 'status' => 'completed'];
            }
            if ($kind === 'no_effect' && $reservation['status'] === 'reserved') {
                $reservations->transitionInTransaction((string) $reservation['idempotency_key'], 'released');
                $this->updateJob($jobId, 'failed', null, null, null, $now);
                $this->appendEvent($jobId, 'worker', null, 'provider_failed_no_effect', ['attempt_id' => $attemptId], $now);
                return ['dispatched' => true, 'status' => 'failed_no_effect'];
            }
            if ($reservation['status'] === 'reserved') { $reservations->transitionInTransaction((string) $reservation['idempotency_key'], 'uncertain'); }
            $this->updateJob($jobId, 'uncertain', null, null, null, $now);
            $this->appendEvent($jobId, 'worker', null, 'provider_outcome_uncertain', ['attempt_id' => $attemptId, 'reason' => $kind === 'no_effect' ? 'reservation_already_uncertain' : 'provider_unknown'], $now);
            return ['dispatched' => true, 'status' => 'uncertain'];
        });
    }

    /** STOP cancels unstarted work and marks authorized provider attempts in flight. */
    public static function requestStopInTransaction(PDO $db, int $now): void
    {
        if (!$db->inTransaction()) { throw new \LogicException('STOP queue update requires an active transaction'); }
        $stmt = $db->query("SELECT id,reservation_id,status,authorization_epoch,lease_token_hash,lease_expires_epoch FROM kp_durable_jobs WHERE status IN ('queued','leased','dispatching') ORDER BY id FOR UPDATE");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $next = $job['status'] === 'dispatching' ? 'cancel_requested' : 'canceled';
            $update = $db->prepare('UPDATE kp_durable_jobs SET status=?,lease_token_hash=?,lease_expires_epoch=?,updated_epoch=? WHERE id=?');
            $update->execute([$next, $next === 'cancel_requested' ? $job['lease_token_hash'] : null, $next === 'cancel_requested' ? $job['lease_expires_epoch'] : null, $now, $job['id']]);
            if ($next === 'canceled') {
                $reservation = $db->prepare('SELECT idempotency_key,status FROM kp_spend_reservations WHERE id=? FOR UPDATE');
                $reservation->execute([$job['reservation_id']]);
                $reserved = $reservation->fetch(PDO::FETCH_ASSOC);
                if ($reserved !== false && $reserved['status'] === 'reserved') {
                    (new PdoSpendReservations($db))->transitionInTransaction((string) $reserved['idempotency_key'], 'released');
                }
            }
            self::appendEventForDb($db, (string) $job['id'], 'owner', 1, $next === 'canceled' ? 'stop_canceled' : 'stop_cancel_requested', ['authorization_epoch' => (int) $job['authorization_epoch']], $now);
        }
    }

    public function verifyAudit(): array
    {
        $rows = $this->db->query('SELECT job_id,actor_kind,actor_owner_id,event_type,detail_json,occurred_epoch,previous_hash,event_hash FROM kp_durable_job_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $previous = self::GENESIS;
        foreach ($rows as $row) {
            if (!hash_equals($previous, (string) $row['previous_hash'])) { throw new \RuntimeException('Durable job audit chain is broken'); }
            $details = json_decode((string) $row['detail_json'], true, 32, JSON_THROW_ON_ERROR);
            $expected = self::eventHash($previous, (string) $row['job_id'], (string) $row['actor_kind'], $row['actor_owner_id'] === null ? null : (int) $row['actor_owner_id'], (string) $row['event_type'], $details, (int) $row['occurred_epoch']);
            if (!hash_equals($expected, (string) $row['event_hash'])) { throw new \RuntimeException('Durable job audit event was altered'); }
            $previous = (string) $row['event_hash'];
        }
        return ['events' => count($rows), 'last_hash' => $previous];
    }

    private function recoverExpiredLeases(int $now): void
    {
        $stmt = $this->db->prepare("SELECT id,reservation_id,status FROM kp_durable_jobs WHERE status IN ('leased','dispatching','cancel_requested') AND lease_expires_epoch<=? ORDER BY id FOR UPDATE");
        $stmt->execute([$now]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $reservation = $this->one('SELECT idempotency_key,status FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
            if ($job['status'] === 'leased') {
                if ($reservation !== null && $reservation['status'] === 'reserved') { (new PdoSpendReservations($this->db))->transitionInTransaction((string) $reservation['idempotency_key'], 'released'); }
                $this->updateJob((string) $job['id'], 'canceled', null, null, null, $now);
                $this->appendEvent((string) $job['id'], 'system', null, 'dispatch_rejected', ['reason' => 'lease_expired_before_dispatch'], $now);
            } else {
                if ($reservation !== null && $reservation['status'] === 'reserved') { (new PdoSpendReservations($this->db))->transitionInTransaction((string) $reservation['idempotency_key'], 'uncertain'); }
                $this->updateJob((string) $job['id'], 'uncertain', null, null, null, $now);
                $this->appendEvent((string) $job['id'], 'system', null, 'lease_expired_uncertain', ['prior_status' => (string) $job['status']], $now);
            }
        }
    }

    private function appendEvent(string $jobId, string $actorKind, ?int $ownerId, string $type, array $details, int $now): void
    {
        self::appendEventForDb($this->db, $jobId, $actorKind, $ownerId, $type, $details, $now);
    }

    private static function appendEventForDb(PDO $db, string $jobId, string $actorKind, ?int $ownerId, string $type, array $details, int $now): void
    {
        $row = $db->query('SELECT event_hash FROM kp_durable_job_events ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        $previous = $row === false ? self::GENESIS : (string) $row['event_hash'];
        $json = json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = self::eventHash($previous, $jobId, $actorKind, $ownerId, $type, $details, $now);
        $stmt = $db->prepare('INSERT INTO kp_durable_job_events(job_id,actor_kind,actor_owner_id,event_type,detail_json,occurred_epoch,previous_hash,event_hash) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$jobId, $actorKind, $ownerId, $type, $json, $now, $previous, $hash]);
    }

    private static function eventHash(string $previous, string $jobId, string $actorKind, ?int $ownerId, string $type, array $details, int $now): string
    {
        // MySQL JSON columns normalize object-member order, and that order can change by release.
        // Hash a canonical representation so verification is independent of storage ordering.
        $details = self::canonicalJsonValue($details);
        return hash('sha256', implode("\0", [$previous, $jobId, $actorKind, $ownerId === null ? '' : (string) $ownerId, $type, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) $now]));
    }

    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map(self::canonicalJsonValue(...), $value); }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) { $value[$key] = self::canonicalJsonValue($child); }
        return $value;
    }

    private function lockControl(): array
    {
        $row = $this->one('SELECT operating_mode,owner_emergency_stopped,dispatch_epoch FROM kp_finance_control WHERE id=1 FOR UPDATE');
        if ($row === null) { throw new \RuntimeException('Finance control unavailable'); }
        return $row;
    }

    private function updateJob(string $id, string $status, ?string $tokenHash, ?int $leaseExpires, ?string $reference, int $now, bool $incrementAttempt = false): void
    {
        $stmt = $this->db->prepare('UPDATE kp_durable_jobs SET status=?,lease_token_hash=?,lease_expires_epoch=?,external_reference=COALESCE(?,external_reference),attempt_count=attempt_count+?,updated_epoch=? WHERE id=?');
        $stmt->execute([$status, $tokenHash, $leaseExpires, $reference, $incrementAttempt ? 1 : 0, $now, $id]);
        if ($stmt->rowCount() !== 1) { throw new \RuntimeException('Durable job state changed concurrently'); }
    }

    private function one(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($params); $row = $stmt->fetch(PDO::FETCH_ASSOC);
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

    private static function validKey(string $key): bool { return preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) === 1; }
    private static function validUuid(string $id): bool { return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $id) === 1; }
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20));
    }
    private static function base64UrlEncode(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
}

