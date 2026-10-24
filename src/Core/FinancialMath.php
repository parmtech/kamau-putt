<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Integer-cents arithmetic; no payment provider calls or mutable state. */
final class FinancialMath
{
    public static function freeSettledCash(
        int $settledCashCents,
        int $customerLiabilitiesCents,
        int $taxReserveCents,
        int $refundReserveCents,
        int $pendingDiscretionaryCents,
    ): int {
        foreach (func_get_args() as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Negative financial position');
            }
        }
        $available = $settledCashCents;
        foreach ([$customerLiabilitiesCents, $taxReserveCents, $refundReserveCents, $pendingDiscretionaryCents] as $deduction) {
            if ($deduction > $available) {
                return 0;
            }
            $available -= $deduction;
        }
        return $available;
    }

    public static function availableEnvelope(int $limitCents, int $spentCents, int $reservedCents): int
    {
        if ($limitCents < 0 || $spentCents < 0 || $reservedCents < 0) {
            throw new \InvalidArgumentException('Negative budget balance');
        }
        if ($spentCents > $limitCents || $reservedCents > $limitCents - $spentCents) {
            return 0;
        }
        return $limitCents - $spentCents - $reservedCents;
    }

    /** Fixed maximum external startup seed, NOT a recurring monthly allowance. */
    public static function mayAcceptSeed(int $alreadyFundedCents, int $additionalCents, int $ceilingCents = 50000): bool
    {
        return $alreadyFundedCents >= 0 && $additionalCents > 0 && $ceilingCents >= 0 &&
            $alreadyFundedCents <= $ceilingCents && $additionalCents <= $ceilingCents - $alreadyFundedCents;
    }
}
