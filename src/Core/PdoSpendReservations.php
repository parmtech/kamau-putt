<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

/**
 * Financial reservation FOUNDATION; never makes external charges.
 * InnoDB, PDO mysql driver and a restricted DB account are required.
 * All mutations serialize on kp_finance_control id=1 FOR UPDATE.
 * The owner-stop bit defaults to STOPPED; this class cannot resume it.
 *
 * NOT PRODUCTION APPROVED: authentication, true accounting, funding intake,
 * concurrency/crash/provider integration testing are still pending.
 */
final class PdoSpendReservations
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('MySQL/MariaDB InnoDB required for row locks');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @return array{allowed:bool,reason:string,id?:string,status?:string} */
    public function reserve(
        string $key,
        string $scope,
        string $actionType,
        int $amountCents,
        int $perActionCapCents,
        bool $scopeAuthorized = false,
    ): array {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) ||
            !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $scope) ||
            !preg_match('/^[a-z_]{1,100}$/D', $actionType)) {
            return ['allowed' => false, 'reason' => 'invalid_request'];
        }
        if (!$scopeAuthorized) {
            return ['allowed' => false, 'reason' => 'scope_not_authorized'];
        }
        if ($amountCents <= 0 || $perActionCapCents < 0) {
            return ['allowed' => false, 'reason' => 'invalid_amount_or_cap'];
        }
        $fingerprint = hash('sha256', json_encode([$scope, $actionType, $amountCents, $perActionCapCents], JSON_THROW_ON_ERROR));
        return $this->transaction(function () use ($key, $scope, $actionType, $amountCents, $perActionCapCents, $scopeAuthorized, $fingerprint): array {
            $control = $this->controlLock();
            $prior = $this->one('SELECT id, request_hash, status FROM kp_spend_reservations WHERE idempotency_key = ? FOR UPDATE', [$key]);
            if ($prior !== null) {
                if (!hash_equals((string) $prior['request_hash'], $fingerprint)) {
                    return ['allowed' => false, 'reason' => 'idempotency_conflict'];
                }
                // Replays are NOT fresh authorization to make another provider call.
                return ['allowed' => false, 'reason' => 'existing_reservation_requires_reconciliation', 'id' => (string) $prior['id'], 'status' => (string) $prior['status']];
            }
            $budget = $this->one('SELECT approved_limit_cents, per_action_cap_cents, spent_cents, reserved_cents FROM kp_budget_envelopes WHERE scope = ? FOR UPDATE', [$scope]);
            if ($budget === null) {
                return ['allowed' => false, 'reason' => 'unknown_budget_scope'];
            }
            $position = $this->one('SELECT * FROM kp_financial_position WHERE id = 1 FOR UPDATE');
            if ($position === null) {
                throw new \RuntimeException('Missing financial position: fail closed');
            }
            $remaining = FinancialMath::availableEnvelope((int) $budget['approved_limit_cents'], (int) $budget['spent_cents'], (int) $budget['reserved_cents']);
            $free = FinancialMath::freeSettledCash(
                (int) $position['settled_cash_cents'],
                (int) $position['customer_liabilities_cents'],
                (int) $position['tax_reserve_cents'],
                (int) $position['refund_reserve_cents'],
                (int) $position['discretionary_reserved_cents'],
            );
            $decision = SpendGuard::evaluate(
                $amountCents,
                min($perActionCapCents, (int) $budget['per_action_cap_cents']),
                $remaining,
                $free,
                (bool) $control['owner_emergency_stopped'],
                $scopeAuthorized,
                $control['operating_mode'] === 'growth',
            );
            if (!$decision->allowed) {
                return ['allowed' => false, 'reason' => $decision->reason];
            }
            $id = $this->uuidV4();
            $this->exec('INSERT INTO kp_spend_reservations (id, idempotency_key, request_hash, scope, action_type, amount_cents, authorization_epoch, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'reserved\')', [$id, $key, $fingerprint, $scope, $actionType, $amountCents, (int) $control['dispatch_epoch']]);
            $this->exec('UPDATE kp_budget_envelopes SET reserved_cents = reserved_cents + ? WHERE scope = ?', [$amountCents, $scope]);
            $this->exec('UPDATE kp_financial_position SET discretionary_reserved_cents = discretionary_reserved_cents + ? WHERE id = 1', [$amountCents]);
            $this->event($id, 'reserved', $amountCents);
            return ['allowed' => true, 'reason' => 'reserved_not_spent', 'id' => $id, 'status' => 'reserved'];
        });
    }

    /** Provider timeout/unknown result: hold funds for reconciliation. */
    public function markUncertain(string $key): string
    {
        return $this->transition($key, 'uncertain');
    }

    /** Record a separately VERIFIED paid provider charge. Does not execute it. */
    public function commit(string $key, string $externalReference): string
    {
        if ($externalReference === '' || strlen($externalReference) > 255) {
            throw new \InvalidArgumentException('Provider reference required');
        }
        return $this->transition($key, 'committed', $externalReference);
    }

    /** Release ONLY after independently verifying that no provider charge exists. */
    public function release(string $key): string
    {
        return $this->transition($key, 'released');
    }

    private function transition(string $key, string $next, ?string $reference = null): string
    {
        return $this->transaction(function () use ($key, $next, $reference): string {
            $this->controlLock(); // same serial lock as reserve and future owner stop
            $row = $this->one('SELECT * FROM kp_spend_reservations WHERE idempotency_key = ? FOR UPDATE', [$key]);
            if ($row === null) {
                throw new \RuntimeException('Unknown spending request');
            }
            $current = (string) $row['status'];
            if ($current === $next) {
                if ($next === 'committed' && $row['external_reference'] !== $reference) {
                    throw new \RuntimeException('Conflicting provider reference');
                }
                return 'idempotent_' . $next;
            }
            if ($current === 'committed' || $current === 'released') {
                throw new \RuntimeException('Terminal reservation cannot change');
            }
            if ($current === 'uncertain' && $next === 'released') {
                throw new \RuntimeException('Uncertain spend requires independently audited reconciliation');
            }
            if ($next === 'uncertain' && $current !== 'reserved') {
                throw new \RuntimeException('Only reserved spend can become uncertain');
            }
            $amount = (int) $row['amount_cents'];
            if ($next === 'committed' || $next === 'released') {
                if ($next === 'committed') {
                    (new PdoDoubleEntryLedger($this->db))->postInTransaction(
                        'reservation-charge:' . hash('sha256', $key),
                        'reservation:' . hash('sha256', $key),
                        'system:spend-ledger',
                        [
                            ['account' => 'operating_expense', 'debit_cents' => $amount, 'credit_cents' => 0, 'memo' => 'Verified provider charge'],
                            ['account' => 'cash_settled', 'debit_cents' => 0, 'credit_cents' => $amount, 'memo' => 'Verified provider charge'],
                        ]
                    );
                }
                $this->exec('UPDATE kp_budget_envelopes SET reserved_cents = reserved_cents - ?' .
                    ($next === 'committed' ? ', spent_cents = spent_cents + ?' : '') .
                    ' WHERE scope = ?', $next === 'committed' ? [$amount, $amount, $row['scope']] : [$amount, $row['scope']]);
                $this->exec('UPDATE kp_financial_position SET discretionary_reserved_cents = discretionary_reserved_cents - ?' .
                    ($next === 'committed' ? ', settled_cash_cents = settled_cash_cents - ?' : '') .
                    ' WHERE id = 1', $next === 'committed' ? [$amount, $amount] : [$amount]);
            }
            $this->exec('UPDATE kp_spend_reservations SET status = ?, external_reference = COALESCE(?, external_reference) WHERE id = ?', [$next, $reference, $row['id']]);
            $this->event((string) $row['id'], $next, $amount);
            return $next;
        });
    }

    private function controlLock(): array
    {
        $row = $this->one('SELECT operating_mode, owner_emergency_stopped, dispatch_epoch FROM kp_finance_control WHERE id = 1 FOR UPDATE');
        if ($row === null) {
            throw new \RuntimeException('Finance control not initialized: fail closed');
        }
        return $row;
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function exec(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Financial invariant broken: expected one row');
        }
    }

    private function event(string $id, string $type, int $amount): void
    {
        $this->exec('INSERT INTO kp_financial_events (reservation_id, event_type, amount_cents) VALUES (?, ?, ?)', [$id, $type, $amount]);
    }

    private function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new \RuntimeException('Nested transactions are unsupported');
        }
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}


