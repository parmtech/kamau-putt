<?php

declare(strict_types=1);

/**
 * DESTRUCTIVE STAGING-ONLY MySQL integration test.
 * Requires a dedicated EMPTY disposable database. Never pass production DSN.
 * Example:
 * KP_TEST_ALLOW_DB_WRITE=I_UNDERSTAND_STAGING_ONLY \
 * KP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=kamau_test;charset=utf8mb4' \
 * KP_TEST_MYSQL_USER=... KP_TEST_MYSQL_PASSWORD=... php tests/mysql_integration.php
 */

if (getenv('KP_TEST_ALLOW_DB_WRITE') !== 'I_UNDERSTAND_STAGING_ONLY') {
    fwrite(STDERR, "SKIP: requires explicit disposable database confirmation\n");
    exit(2);
}
$dsn = getenv('KP_TEST_MYSQL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "SKIP: dedicated mysql DSN and pdo_mysql required\n");
    exit(2);
}
require_once __DIR__ . '/../src/Core/SpendDecision.php';
require_once __DIR__ . '/../src/Core/SpendGuard.php';
require_once __DIR__ . '/../src/Core/FinancialMath.php';
require_once __DIR__ . '/../src/Core/PdoSpendReservations.php';

use KamauPutt\Core\PdoSpendReservations;

$db = new PDO($dsn, getenv('KP_TEST_MYSQL_USER') ?: '', getenv('KP_TEST_MYSQL_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Integration test requires an EMPTY disposable database');
}
$sql = file_get_contents(__DIR__ . '/../database/001_financial_control.mysql.sql');
foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) {
    if (trim($statement) !== '') {
        $db->exec(trim($statement));
    }
}
$db->exec("INSERT INTO kp_budget_envelopes(scope,approved_limit_cents,per_action_cap_cents) VALUES ('test',1000,1000)");
$db->exec("UPDATE kp_financial_position SET settled_cash_cents=1000 WHERE id=1");
$ledger = new PdoSpendReservations($db);
$tests = [];

$stopped = $ledger->reserve('test-case-0001', 'test', 'supplier_order', 400, 500, true);
$tests['default_emergency_stop'] = !$stopped['allowed'] && $stopped['reason'] === 'owner_emergency_stop';

$db->exec("UPDATE kp_finance_control SET operating_mode='growth',owner_emergency_stopped=0 WHERE id=1");
$reserved = $ledger->reserve('test-case-0001', 'test', 'supplier_order', 400, 500, true);
$tests['first_reserve'] = $reserved['allowed'] && $reserved['status'] === 'reserved';
$replay = $ledger->reserve('test-case-0001', 'test', 'supplier_order', 400, 500, true);
$tests['replay_not_authorized'] = !$replay['allowed'] && $replay['reason'] === 'existing_reservation_requires_reconciliation';
$conflict = $ledger->reserve('test-case-0001', 'test', 'supplier_order', 300, 500, true);
$tests['same_key_different_amount'] = !$conflict['allowed'] && $conflict['reason'] === 'idempotency_conflict';
$over = $ledger->reserve('test-case-0002', 'test', 'supplier_order', 700, 800, true);
$tests['budget_limit_includes_pending'] = !$over['allowed'] && $over['reason'] === 'approved_budget_exceeded';
$tests['commit'] = $ledger->commit('test-case-0001', 'verified-test-provider-ref') === 'committed';
$tests['commit_replay'] = $ledger->commit('test-case-0001', 'verified-test-provider-ref') === 'idempotent_committed';
$another = $ledger->reserve('test-case-0003', 'test', 'supplier_order', 200, 300, true);
$tests['reserve_after_commit'] = $another['allowed'];
$tests['release'] = $ledger->release('test-case-0003') === 'released';
$position = $db->query('SELECT settled_cash_cents, discretionary_reserved_cents FROM kp_financial_position WHERE id=1')->fetch();
$tests['accounting_position'] = (int) $position['settled_cash_cents'] === 600 && (int) $position['discretionary_reserved_cents'] === 0;
$tests['event_count'] = (int) $db->query('SELECT COUNT(*) FROM kp_financial_events')->fetchColumn() === 4;

foreach ($tests as $name => $pass) {
    echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
$passed = count(array_filter($tests));
echo "$passed/" . count($tests) . " integration cases passed\n";
exit($passed === count($tests) ? 0 : 1);
