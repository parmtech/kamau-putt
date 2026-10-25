<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

/**
 * Conservative dispatch gate prototype. The callback runs while the finance
 * control row is locked, so STOP waits for an already-started call and prevents
 * every later call. Keep callbacks bounded and use the supplied stable provider
 * idempotency key. This class is not an authentication boundary or live adapter.
 */
final class PdoDispatchGate
{
    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('MySQL/MariaDB InnoDB required for row locks');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @return array{allowed:bool,reason:string,epoch?:int,result?:mixed} */
    public function dispatch(string $reservationKey, callable $providerCall): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $reservationKey)) {
            return ['allowed' => false, 'reason' => 'invalid_request'];
        }

        return $this->transaction(function () use ($reservationKey, $providerCall): array {
            $control = $this->one('SELECT operating_mode, owner_emergency_stopped, dispatch_epoch FROM kp_finance_control WHERE id = 1 FOR UPDATE');
            if ($control === null) {
                throw new \RuntimeException('Finance control not initialized: fail closed');
            }
            $epoch = (int) $control['dispatch_epoch'];
            if ((bool) $control['owner_emergency_stopped']) {
                return ['allowed' => false, 'reason' => 'owner_emergency_stop', 'epoch' => $epoch];
            }
            if ($control['operating_mode'] !== 'growth') {
                return ['allowed' => false, 'reason' => 'operating_mode_disallows_dispatch', 'epoch' => $epoch];
            }

            $reservation = $this->one(
                'SELECT id, idempotency_key, scope, action_type, amount_cents, status, authorization_epoch FROM kp_spend_reservations WHERE idempotency_key = ? FOR UPDATE',
                [$reservationKey]
            );
            if ($reservation === null || $reservation['status'] !== 'reserved') {
                return ['allowed' => false, 'reason' => 'reservation_not_dispatchable', 'epoch' => $epoch];
            }
            if ((int) $reservation['authorization_epoch'] !== $epoch) {
                return ['allowed' => false, 'reason' => 'stale_dispatch_authorization', 'epoch' => $epoch];
            }

            // This critical section intentionally serializes provider initiation
            // against STOP. Do not put unbounded work in the callback.
            $result = $providerCall([
                'reservation_id' => (string) $reservation['id'],
                'scope' => (string) $reservation['scope'],
                'action_type' => (string) $reservation['action_type'],
                'amount_cents' => (int) $reservation['amount_cents'],
                'provider_idempotency_key' => 'kp-' . hash('sha256', (string) $reservation['idempotency_key']),
                'authorization_epoch' => $epoch,
            ]);
            return ['allowed' => true, 'reason' => 'provider_call_completed', 'epoch' => $epoch, 'result' => $result];
        });
    }

    /** Trusted internal STOP primitive; authentication and owner identity are still pending. */
    public function emergencyStop(): int
    {
        return $this->transaction(function (): int {
            $control = $this->one('SELECT owner_emergency_stopped, dispatch_epoch FROM kp_finance_control WHERE id = 1 FOR UPDATE');
            if ($control === null) {
                throw new \RuntimeException('Finance control not initialized: fail closed');
            }
            $epoch = (int) $control['dispatch_epoch'];
            if (!(bool) $control['owner_emergency_stopped']) {
                if ($epoch >= PHP_INT_MAX) {
                    throw new \OverflowException('Dispatch epoch exhausted');
                }
                $epoch++;
                $this->exec('UPDATE kp_finance_control SET owner_emergency_stopped = 1, dispatch_epoch = ? WHERE id = 1', [$epoch]);
            }
            return $epoch;
        });
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
            throw new \RuntimeException('Finance control invariant broken');
        }
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
}


