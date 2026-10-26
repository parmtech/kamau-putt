<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

/**
 * Durable reservation-backed outbox foundation. Provider dispatch happens only
 * inside a short callback while the finance control row is locked. A lost or
 * ambiguous result becomes uncertain and is never automatically retried.
 */
final class PdoDurableJobs
{
    private const LEASE_SECONDS = 30;
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

    /**
     * Run one bounded provider initiation and settle its reservation atomically.
     * Callback returns accepted + reference, no_effect, or unknown. Never retry unknown.
     */
    public function dispatch(string $jobId, string $leaseToken, callable $providerCall): array
    {
        if (!self::validUuid($jobId) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $leaseToken)) { return ['dispatched' => false, 'reason' => 'invalid_lease']; }
        $now = $this->now();
        return $this->transaction(function () use ($jobId, $leaseToken, $providerCall, $now): array {
            $control = $this->lockControl(); // STOP takes the same row lock.
            $job = $this->one('SELECT job_key,reservation_id,action_type,payload_ref,authorization_epoch,status,lease_token_hash,lease_expires_epoch FROM kp_durable_jobs WHERE id=? FOR UPDATE', [$jobId]);
            if ($job === null || $job['status'] !== 'leased' || !is_string($job['lease_token_hash']) || !hash_equals((string) $job['lease_token_hash'], hash('sha256', $leaseToken)) || (int) $job['lease_expires_epoch'] <= $now) {
                return ['dispatched' => false, 'reason' => 'lease_unavailable'];
            }
            if ((bool) $control['owner_emergency_stopped'] || $control['operating_mode'] !== 'growth' || (int) $job['authorization_epoch'] !== (int) $control['dispatch_epoch']) {
                $this->updateJob($jobId, 'cancel_requested', (string) $job['lease_token_hash'], (int) $job['lease_expires_epoch'], null, $now);
                $this->appendEvent($jobId, 'system', null, 'stop_cancel_requested', ['reason' => 'dispatch_fence'], $now);
                return ['dispatched' => false, 'reason' => 'dispatch_fenced'];
            }
            // Must remain bounded. A process crash after provider acceptance is recovered as uncertain.
            $outcome = $providerCall([
                'action_type' => (string) $job['action_type'], 'payload_ref' => (string) $job['payload_ref'],
                'provider_idempotency_key' => 'kp-' . hash('sha256', (string) $job['job_key']),
                'authorization_epoch' => (int) $job['authorization_epoch'],
            ]);
            if (!is_array($outcome) || !in_array($outcome['outcome'] ?? null, ['accepted', 'no_effect', 'unknown'], true)) {
                throw new \RuntimeException('Provider adapter returned an invalid outcome');
            }
            $reservation = $this->one('SELECT idempotency_key FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
            if ($reservation === null) { throw new \RuntimeException('Outbox reservation is missing'); }
            $reservations = new PdoSpendReservations($this->db);
            if ($outcome['outcome'] === 'accepted') {
                $reference = $outcome['reference'] ?? null;
                if (!is_string($reference) || $reference === '' || strlen($reference) > 255) { throw new \RuntimeException('Accepted provider outcome requires a reference'); }
                $reservations->transitionInTransaction((string) $reservation['idempotency_key'], 'committed', $reference);
                $this->updateJob($jobId, 'completed', null, null, $reference, $now);
                $this->appendEvent($jobId, 'worker', null, 'provider_completed', ['reference_hash' => hash('sha256', $reference)], $now);
                return ['dispatched' => true, 'status' => 'completed'];
            }
            if ($outcome['outcome'] === 'no_effect') {
                $reservations->transitionInTransaction((string) $reservation['idempotency_key'], 'released');
                $this->updateJob($jobId, 'failed', null, null, null, $now);
                $this->appendEvent($jobId, 'worker', null, 'provider_failed_no_effect', [], $now);
                return ['dispatched' => true, 'status' => 'failed_no_effect'];
            }
            $reservations->transitionInTransaction((string) $reservation['idempotency_key'], 'uncertain');
            $this->updateJob($jobId, 'uncertain', null, null, null, $now);
            $this->appendEvent($jobId, 'worker', null, 'provider_outcome_uncertain', [], $now);
            return ['dispatched' => true, 'status' => 'uncertain'];
        });
    }

    /** STOP transaction hook: queued work is canceled and leased work is fenced. */
    public static function requestStopInTransaction(PDO $db, int $now): void
    {
        if (!$db->inTransaction()) { throw new \LogicException('STOP queue update requires an active transaction'); }
        $stmt = $db->query("SELECT id,status,authorization_epoch,lease_token_hash,lease_expires_epoch FROM kp_durable_jobs WHERE status IN ('queued','leased') ORDER BY id FOR UPDATE");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $next = $job['status'] === 'queued' ? 'canceled' : 'cancel_requested';
            $update = $db->prepare('UPDATE kp_durable_jobs SET status=?,lease_token_hash=?,lease_expires_epoch=?,updated_epoch=? WHERE id=?');
            $update->execute([$next, $next === 'cancel_requested' ? $job['lease_token_hash'] : null, $next === 'cancel_requested' ? $job['lease_expires_epoch'] : null, $now, $job['id']]);
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
        $stmt = $this->db->prepare("SELECT id,reservation_id,status FROM kp_durable_jobs WHERE status IN ('leased','cancel_requested') AND lease_expires_epoch<=? ORDER BY id FOR UPDATE");
        $stmt->execute([$now]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $reservation = $this->one('SELECT idempotency_key,status FROM kp_spend_reservations WHERE id=? FOR UPDATE', [$job['reservation_id']]);
            if ($reservation !== null && $reservation['status'] === 'reserved') {
                (new PdoSpendReservations($this->db))->transitionInTransaction((string) $reservation['idempotency_key'], 'uncertain');
            }
            $this->updateJob((string) $job['id'], 'uncertain', null, null, null, $now);
            $this->appendEvent((string) $job['id'], 'system', null, 'lease_expired_uncertain', ['prior_status' => (string) $job['status']], $now);
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
        return hash('sha256', implode("\0", [$previous, $jobId, $actorKind, $ownerId === null ? '' : (string) $ownerId, $type, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) $now]));
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
