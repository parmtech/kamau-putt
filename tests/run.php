<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/SpendDecision.php';
require_once __DIR__ . '/../src/Core/SpendGuard.php';

use KamauPutt\Core\SpendGuard;

$cases = [
    ['allow_approved_spend', [500, 1000, 1500, 2500, false, true, true], true, 'preflight_passed_reservation_required'],
    ['deny_zero', [0, 1000, 1500, 2500, false, true, true], false, 'invalid_amount'],
    ['deny_negative', [-1, 1000, 1500, 2500, false, true, true], false, 'invalid_amount'],
    ['deny_owner_stop', [500, 1000, 1500, 2500, true, true, true], false, 'owner_emergency_stop'],
    ['deny_unapproved_scope', [500, 1000, 1500, 2500, false, false, true], false, 'scope_not_authorized'],
    ['deny_survival_mode', [500, 1000, 1500, 2500, false, true, false], false, 'survival_or_shutdown_mode'],
    ['deny_action_cap', [1001, 1000, 1500, 2500, false, true, true], false, 'per_action_cap_exceeded'],
    ['deny_budget_cap', [1501, 2000, 1500, 2500, false, true, true], false, 'approved_budget_exceeded'],
    ['deny_unsettled_cash', [2501, 3000, 3000, 2500, false, true, true], false, 'insufficient_free_settled_cash'],
    ['deny_invalid_snapshot', [100, 1000, -1, 1000, false, true, true], false, 'invalid_financial_snapshot'],
    ['allow_exact_boundary', [1000, 1000, 1000, 1000, false, true, true], true, 'preflight_passed_reservation_required'],
];

$failed = 0;
foreach ($cases as [$name, $args, $expectedAllow, $expectedReason]) {
    $result = SpendGuard::evaluate(...$args);
    $pass = $result->allowed === $expectedAllow && $result->reason === $expectedReason;
    echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$pass) {
        $failed++;
    }
}

printf("%d/%d tests passed\n", count($cases) - $failed, count($cases));
exit($failed === 0 ? 0 : 1);
