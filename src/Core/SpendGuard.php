<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/**
 * A pure pre-flight spending guard. All money values are integer AUD cents.
 *
 * IMPORTANT: This does not prevent concurrent overspend on its own. Production
 * actions must acquire and persist an atomic budget reservation in a locked
 * database transaction, then use an idempotency key at the external provider.
 */
final class SpendGuard
{
    public static function evaluate(
        int $amountCents,
        int $perActionCapCents,
        int $remainingApprovedBudgetCents,
        int $freeSettledCashCents,
        bool $ownerEmergencyStopped,
        bool $scopeAuthorized,
        bool $discretionarySpendingEnabled,
    ): SpendDecision {
        if ($amountCents <= 0) {
            return new SpendDecision(false, 'invalid_amount');
        }
        if ($ownerEmergencyStopped) {
            return new SpendDecision(false, 'owner_emergency_stop');
        }
        if (!$scopeAuthorized) {
            return new SpendDecision(false, 'scope_not_authorized');
        }
        if (!$discretionarySpendingEnabled) {
            return new SpendDecision(false, 'survival_or_shutdown_mode');
        }
        if ($perActionCapCents < 0 || $remainingApprovedBudgetCents < 0 || $freeSettledCashCents < 0) {
            return new SpendDecision(false, 'invalid_financial_snapshot');
        }
        if ($amountCents > $perActionCapCents) {
            return new SpendDecision(false, 'per_action_cap_exceeded');
        }
        if ($amountCents > $remainingApprovedBudgetCents) {
            return new SpendDecision(false, 'approved_budget_exceeded');
        }
        if ($amountCents > $freeSettledCashCents) {
            return new SpendDecision(false, 'insufficient_free_settled_cash');
        }

        return new SpendDecision(true, 'preflight_passed_reservation_required');
    }
}
