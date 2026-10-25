<?php

declare(strict_types=1);

/**
 * Disposable-database process tests. Never use production credentials.
 * Requires the same explicit opt-in as mysql_integration.php, an EMPTY database,
 * pdo_mysql in the PHP runtime configuration, and proc_open.
 * Workers are independent PHP processes/connections, not mocked PDO objects.
 */
require_once __DIR__ . '/../src/Core/SpendDecision.php';
require_once __DIR__ . '/../src/Core/SpendGuard.php';
require_once __DIR__ . '/../src/Core/FinancialMath.php';
require_once __DIR__ . '/../src/Core/PdoSpendReservations.php';

use KamauPutt\Core\PdoSpendReservations;

function connectTestDatabase(): PDO
{
    if (PHP_SAPI !== 'cli' || getenv('KP_TEST_ALLOW_DB_WRITE') !== 'I_UNDERSTAND_STAGING_ONLY') {
        throw new RuntimeException('Explicit disposable database confirmation required');
    }
    $dsn = getenv('KP_TEST_MYSQL_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || !extension_loaded('pdo_mysql')) {
        throw new RuntimeException('MySQL DSN and configured pdo_mysql required');
    }
    return new PDO($dsn, getenv('KP_TEST_MYSQL_USER') ?: '', getenv('KP_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function readWorkerLine(array $worker): string
{
    $deadline = microtime(true) + 20;
    $line = '';
    do {
        $part = fgets($worker['pipes'][1]);
        if ($part !== false) {
            $line .= $part;
            if (str_ends_with($line, "\n")) {
                return trim($line);
            }
        }
        if (feof($worker['pipes'][1])) {
            throw new RuntimeException('Worker ended unexpectedly: ' . stream_get_contents($worker['pipes'][2]));
        }
        usleep(1000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Worker response timed out');
}

function startWorker(string $operation, string $key, string $scope = 'test', int $amount = 700): array
{
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() !== false) {
        $command = array_merge($command, ['-c', php_ini_loaded_file()]);
    }
    $command = array_merge($command, [__FILE__, '--worker', $operation, $key, $scope, (string) $amount]);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start independent test process');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $worker = ['process' => $process, 'pipes' => $pipes];
    try {
        if (readWorkerLine($worker) !== 'READY') {
            throw new RuntimeException('Worker did not reach barrier');
        }
    } catch (Throwable $error) {
        closeWorker($worker, true);
        throw $error;
    }
    return $worker;
}

function releaseWorker(array $worker): void
{
    fwrite($worker['pipes'][0], "GO\n");
    fflush($worker['pipes'][0]);
    if (readWorkerLine($worker) !== 'ATTEMPT') {
        throw new RuntimeException('Worker did not attempt operation');
    }
}

function closeWorker(array $worker, bool $terminate = false): void
{
    if ($terminate) {
        proc_terminate($worker['process']);
    }
    foreach ($worker['pipes'] as $pipe) {
        fclose($pipe);
    }
    $status = proc_close($worker['process']);
    if (!$terminate && $status !== 0) {
        throw new RuntimeException('Worker failed with exit code ' . $status);
    }
}

function parallelOperations(PDO $db, array $requests, ?callable $lockedChange = null): array
{
    $workers = [];
    $results = [];
    $db->beginTransaction();
    $db->query('SELECT id FROM kp_finance_control WHERE id=1 FOR UPDATE')->fetch();
    try {
        if ($lockedChange !== null) {
            $lockedChange($db);
        }
        foreach ($requests as $request) {
            $workers[] = startWorker(...$request);
        }
        foreach ($workers as $worker) {
            releaseWorker($worker);
        }
        // All workers reached the operation barrier while the control row was locked.
        $db->commit();
        foreach ($workers as $worker) {
            $results[] = json_decode(readWorkerLine($worker), true, 512, JSON_THROW_ON_ERROR);
        }
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        foreach ($workers as $worker) {
            closeWorker($worker, true);
        }
        throw $error;
    }
    foreach ($workers as $worker) {
        closeWorker($worker);
    }
    return $results;
}

function fixture(PDO $db): void
{
    // Called only after this test created the schema in an EMPTY disposable DB.
    $db->exec('DELETE FROM kp_financial_events');
    $db->exec('DELETE FROM kp_spend_reservations');
    $db->exec('DELETE FROM kp_budget_envelopes');
    $db->exec("INSERT INTO kp_budget_envelopes(scope,approved_limit_cents,per_action_cap_cents) VALUES ('test',1000,1000),('other',1000,1000)");
    $db->exec('UPDATE kp_financial_position SET settled_cash_cents=1000, customer_liabilities_cents=0, tax_reserve_cents=0, refund_reserve_cents=0, discretionary_reserved_cents=0 WHERE id=1');
    $db->exec("UPDATE kp_finance_control SET operating_mode='growth',owner_emergency_stopped=0 WHERE id=1");
}

function checkCase(string $name, bool $pass): void
{
    echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$pass) {
        throw new RuntimeException('Failed invariant: ' . $name);
    }
    $GLOBALS['passedCases']++;
}

$db = connectTestDatabase();
if (($argv[1] ?? '') === '--worker') {
    echo "READY\n";
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing parent barrier signal');
    }
    echo "ATTEMPT\n";
    fflush(STDOUT);
    $ledger = new PdoSpendReservations($db);
    $operation = $argv[2];
    if ($operation === 'reserve') {
        $result = $ledger->reserve($argv[3], $argv[4], 'supplier_order', (int) $argv[5], 1000, true);
    } elseif ($operation === 'commit') {
        $result = ['status' => $ledger->commit($argv[3], 'test-provider-reference')];
    } elseif ($operation === 'crash') {
        $db->beginTransaction();
        $db->query('SELECT id FROM kp_finance_control WHERE id=1 FOR UPDATE')->fetch();
        $db->exec('UPDATE kp_financial_position SET discretionary_reserved_cents=900 WHERE id=1');
        $db->exec("UPDATE kp_budget_envelopes SET reserved_cents=900 WHERE scope='test'");
        echo "MUTATED\n";
        fflush(STDOUT);
        // Parent forcibly terminates this process without a commit or rollback.
        fgets(STDIN);
        throw new RuntimeException('Crash worker must be terminated by parent');
    } else {
        throw new RuntimeException('Unknown worker operation');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Concurrency test requires an EMPTY disposable database');
}
foreach (preg_split('/;\s*(?=\n|$)/', file_get_contents(__DIR__ . '/../database/001_financial_control.mysql.sql')) as $statement) {
    if (trim($statement) !== '') {
        $db->exec(trim($statement));
    }
}
$GLOBALS['passedCases'] = 0;
$ledger = new PdoSpendReservations($db);
for ($round = 1; $round <= 3; $round++) {
    fixture($db);
    $results = parallelOperations($db, [['reserve','race-budget-0001'], ['reserve','race-budget-0002']]);
    checkCase("budget_one_winner_$round", count(array_filter($results, fn($r) => $r['allowed'])) === 1);
    checkCase("budget_no_overallocation_$round", (int) $db->query("SELECT reserved_cents FROM kp_budget_envelopes WHERE scope='test'")->fetchColumn() === 700);

    fixture($db);
    $results = parallelOperations($db, [['reserve','race-cash-0001','test',800], ['reserve','race-cash-0002','other',800]]);
    checkCase("global_cash_one_winner_$round", count(array_filter($results, fn($r) => $r['allowed'])) === 1);
    checkCase("global_cash_held_once_$round", (int) $db->query('SELECT discretionary_reserved_cents FROM kp_financial_position WHERE id=1')->fetchColumn() === 800);

    fixture($db);
    $results = parallelOperations($db, [['reserve','race-duplicate-0001'], ['reserve','race-duplicate-0001']]);
    checkCase("duplicate_authorized_once_$round", count(array_filter($results, fn($r) => $r['allowed'])) === 1);
    checkCase("duplicate_single_record_$round", (int) $db->query('SELECT COUNT(*) FROM kp_spend_reservations')->fetchColumn() === 1);

    $results = parallelOperations($db, [['commit','race-duplicate-0001'], ['commit','race-duplicate-0001']]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    checkCase("commit_serializes_replay_$round", $statuses === ['committed','idempotent_committed']);
    checkCase("commit_single_cash_debit_$round", (int) $db->query('SELECT settled_cash_cents FROM kp_financial_position WHERE id=1')->fetchColumn() === 300);
    checkCase("commit_single_event_$round", (int) $db->query("SELECT COUNT(*) FROM kp_financial_events WHERE event_type='committed'")->fetchColumn() === 1);
}

fixture($db);
$results = parallelOperations($db, [['reserve','stop-race-0001'], ['reserve','stop-race-0002']], static function(PDO $db): void {
    // Models a durable stop transaction; this is NOT an owner authentication test.
    $db->exec('UPDATE kp_finance_control SET owner_emergency_stopped=1 WHERE id=1');
});
checkCase('committed_stop_blocks_waiting_requests', count(array_filter($results, fn($r) => !$r['allowed'] && $r['reason'] === 'owner_emergency_stop')) === 2);
checkCase('stopped_requests_create_no_records', (int) $db->query('SELECT COUNT(*) FROM kp_spend_reservations')->fetchColumn() === 0);
$reconnected = new PdoSpendReservations(connectTestDatabase());
checkCase('stop_survives_new_connection', !$reconnected->reserve('stop-reconnect-0001','test','supplier_order',100,100,true)['allowed']);

fixture($db);
$worker = startWorker('crash', 'crash-test-0001');
releaseWorker($worker);
if (readWorkerLine($worker) !== 'MUTATED') {
    closeWorker($worker, true);
    throw new RuntimeException('Crash worker did not mutate its uncommitted transaction');
}
closeWorker($worker, true);
checkCase('crashed_process_rolls_back_reservation', (int) $db->query('SELECT discretionary_reserved_cents FROM kp_financial_position WHERE id=1')->fetchColumn() === 0);
checkCase('crashed_process_rolls_back_budget', (int) $db->query("SELECT reserved_cents FROM kp_budget_envelopes WHERE scope='test'")->fetchColumn() === 0);
checkCase('crashed_lock_released', $ledger->reserve('after-crash-0001','test','supplier_order',100,100,true)['allowed']);

echo $GLOBALS['passedCases'] . " concurrency cases passed\n";
