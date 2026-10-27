<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/FinancialMath.php';

use KamauPutt\Core\FinancialMath as F;

$cases = [
    ['free_cash_after_reserves', F::freeSettledCash(30000, 9000, 2000, 1000, 4000) === 14000],
    ['free_cash_never_negative', F::freeSettledCash(100, 80, 30, 0, 0) === 0],
    ['zero_free_cash', F::freeSettledCash(0, 0, 0, 0, 0) === 0],
    ['budget_remaining', F::availableEnvelope(50000, 8000, 12000) === 30000],
    ['budget_overallocated_fail_closed', F::availableEnvelope(100, 90, 20) === 0],
    ['seed_within_limit', F::mayAcceptSeed(20000, 30000)],
    ['seed_over_limit', !F::mayAcceptSeed(20000, 30001)],
    ['seed_not_monthly_reset', !F::mayAcceptSeed(50000, 1)],
    ['seed_invalid', !F::mayAcceptSeed(0, -100)],
    ['negative_rejected', (static function (): bool { try { F::freeSettledCash(-1, 0, 0, 0, 0); return false; } catch (InvalidArgumentException) { return true; } })()],
];
foreach ($cases as [$name, $ok]) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
$total = count($cases);
$passed = count(array_filter($cases, fn($item) => $item[1]));
echo "$passed/$total financial math tests passed\n";
exit($passed === $total ? 0 : 1);
