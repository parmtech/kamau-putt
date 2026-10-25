<?php

declare(strict_types=1);

/**
 * Fresh empty disposable MySQL 8.4 database only. Never use production data.
 * Requires KP_TEST_ALLOW_DB_WRITE=I_UNDERSTAND_STAGING_ONLY and pdo_mysql.
 */
require_once __DIR__ . '/../src/Core/PdoDoubleEntryLedger.php';

if (PHP_SAPI !== 'cli' || getenv('KP_TEST_ALLOW_DB_WRITE') !== 'I_UNDERSTAND_STAGING_ONLY') {
    fwrite(STDERR, "SKIP: explicit disposable database confirmation required\n");
    exit(2);
}
$dsn = getenv('KP_TEST_MYSQL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "SKIP: configured MySQL PDO driver required\n");
    exit(2);
}
$db = new PDO($dsn, getenv('KP_TEST_MYSQL_USER') ?: '', getenv('KP_TEST_MYSQL_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Accounting test requires an EMPTY disposable database');
}
foreach (['001_financial_control.mysql.sql', '002_double_entry_ledger.mysql.sql'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/../database/' . $migration);
    foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') { $db->exec(trim($statement)); }
    }
}
$ledger = new KamauPutt\Core\PdoDoubleEntryLedger($db);
$passed = 0;
$total = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$total): void {
    $total++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if ($condition) { $passed++; }
};
$throws = static function (callable $work, string $class = Throwable::class): bool {
    try { $work(); }
    catch (Throwable $error) { return $error instanceof $class; }
    return false;
};
$post = static fn(string $key, string $ref, array $lines): array => $ledger->post($key, $ref, 'test:owner', $lines);
$opening = [
    ['account'=>'cash_settled','debit_cents'=>10000,'credit_cents'=>0,'memo'=>'Test opening funds'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>10000,'memo'=>'Test opening funds'],
];
$first = $post('acct-opening-0001','opening:test-1',$opening);
$check('balanced_entry_posts', $first['status'] === 'posted');
$check('idempotent_replay_has_same_transaction', $post('acct-opening-0001','opening:test-1',$opening) === ['status'=>'already_posted','transaction_id'=>$first['transaction_id']]);
$reordered = array_reverse($opening);
$check('line_order_does_not_change_idempotency', $post('acct-opening-0001','opening:test-1',$reordered)['transaction_id'] === $first['transaction_id']);
$check('same_key_changed_amount_denied', $throws(fn() => $post('acct-opening-0001','opening:test-1',[
    ['account'=>'cash_settled','debit_cents'=>9000,'credit_cents'=>0,'memo'=>'Test opening funds'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>9000,'memo'=>'Test opening funds'],
])));
$check('duplicate_source_other_key_denied', $throws(fn() => $post('acct-opening-0002','opening:test-1',$opening)));
$check('unbalanced_entry_denied', $throws(fn() => $post('acct-bad-balance-01','opening:bad-balance',[
    ['account'=>'cash_settled','debit_cents'=>100,'credit_cents'=>0,'memo'=>'bad'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>99,'memo'=>'bad'],
])));
$check('zero_line_denied', $throws(fn() => $post('acct-zero-line-001','opening:zero-line',[
    ['account'=>'cash_settled','debit_cents'=>0,'credit_cents'=>0,'memo'=>'bad'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>0,'memo'=>'bad'],
])));
$check('float_amount_denied', $throws(fn() => $post('acct-float-amount-01','opening:float-amount',[
    ['account'=>'cash_settled','debit_cents'=>1.5,'credit_cents'=>0,'memo'=>'bad'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>1,'memo'=>'bad'],
])));
$check('duplicate_account_line_denied', $throws(fn() => $post('acct-duplicate-01','opening:duplicate-lines',[
    ['account'=>'cash_settled','debit_cents'=>50,'credit_cents'=>0,'memo'=>'one'],
    ['account'=>'cash_settled','debit_cents'=>50,'credit_cents'=>0,'memo'=>'two'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>100,'memo'=>'offset'],
])));
$check('unknown_account_denied', $throws(fn() => $post('acct-unknown-0001','opening:unknown-account',[
    ['account'=>'missing_account','debit_cents'=>1,'credit_cents'=>0,'memo'=>'bad'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>1,'memo'=>'bad'],
])));
$db->exec("INSERT INTO kp_ledger_accounts(account_code,account_name,account_type,normal_side,is_active) VALUES('inactive_test','Inactive test','asset','debit',0)");
$db->exec("INSERT INTO kp_ledger_accounts(account_code,account_name,account_type,normal_side,currency) VALUES('foreign_test','Foreign currency test','asset','debit','USD')");
$check('inactive_account_denied', $throws(fn() => $post('acct-inactive-0001','opening:inactive-account',[
    ['account'=>'inactive_test','debit_cents'=>1,'credit_cents'=>0,'memo'=>'bad'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>1,'memo'=>'bad'],
])));
$check('non_aud_account_denied', $throws(fn() => $post('acct-foreign-0001','opening:foreign-account',[
    ['account'=>'foreign_test','debit_cents'=>1,'credit_cents'=>0,'memo'=>'bad'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>1,'memo'=>'bad'],
])));
$check('more_than_two_accounts_post', $post('acct-multi-line-0001','expense:test-multi',[
    ['account'=>'api_cost','debit_cents'=>250,'credit_cents'=>0,'memo'=>'API'],
    ['account'=>'processing_fees','debit_cents'=>50,'credit_cents'=>0,'memo'=>'Fee'],
    ['account'=>'cash_settled','debit_cents'=>0,'credit_cents'=>300,'memo'=>'Paid'],
])['status'] === 'posted');
$check('asset_balance_uses_debit_normal', $ledger->accountBalance('cash_settled') === 9700);
$check('expense_balance_uses_debit_normal', $ledger->accountBalance('api_cost') === 250);
$check('equity_balance_uses_credit_normal', $ledger->accountBalance('owner_capital') === 10000);
$check('integrity_chain_verifies', $ledger->verify()['transactions'] === 2);

$before = (int) $db->query('SELECT COUNT(*) FROM kp_ledger_transactions')->fetchColumn();
$db->beginTransaction();
$db->query('SELECT id FROM kp_finance_control WHERE id=1 FOR UPDATE')->fetch();
$ledger->postInTransaction('acct-rollback-0001','opening:rollback-test','test:owner',$opening);
$db->rollBack();
$check('composed_post_rolls_back_with_parent', (int) $db->query('SELECT COUNT(*) FROM kp_ledger_transactions')->fetchColumn() === $before);
$check('rollback_leaves_chain_valid', $ledger->verify()['transactions'] === $before);
$check('standalone_composition_requires_transaction', $throws(fn() => $ledger->postInTransaction('acct-no-tx-00001','opening:no-tx','test:owner',$opening), LogicException::class));

$before = (int) $db->query('SELECT COUNT(*) FROM kp_ledger_transactions')->fetchColumn();
$check('transaction_update_rejected', $throws(fn() => $db->exec("UPDATE kp_ledger_transactions SET actor='tampered' WHERE id=1")));
$check('transaction_delete_rejected', $throws(fn() => $db->exec('DELETE FROM kp_ledger_transactions WHERE id=1')));
$check('entry_update_rejected', $throws(fn() => $db->exec("UPDATE kp_ledger_entries SET memo='tampered' WHERE id=1")));
$check('entry_delete_rejected', $throws(fn() => $db->exec('DELETE FROM kp_ledger_entries WHERE id=1')));
$check('account_definition_update_rejected', $throws(fn() => $db->exec("UPDATE kp_ledger_accounts SET account_type='income',normal_side='credit' WHERE account_code='cash_settled'")));
$check('account_definition_delete_rejected', $throws(fn() => $db->exec("DELETE FROM kp_ledger_accounts WHERE account_code='cash_settled'")));
$check('tamper_attempts_preserve_chain', $ledger->verify()['transactions'] === $before);

$overflow = [
    ['account'=>'marketing_cost','debit_cents'=>PHP_INT_MAX,'credit_cents'=>0,'memo'=>'large'],
    ['account'=>'api_cost','debit_cents'=>1,'credit_cents'=>0,'memo'=>'overflow'],
    ['account'=>'cash_settled','debit_cents'=>0,'credit_cents'=>PHP_INT_MAX,'memo'=>'offset'],
    ['account'=>'owner_capital','debit_cents'=>0,'credit_cents'=>1,'memo'=>'offset'],
];
$check('overflowing_totals_denied', $throws(fn() => $post('acct-overflow-total-01','opening:overflow-total',$overflow), OverflowException::class));
$check('invalid_idempotency_denied', $throws(fn() => $post('short','opening:short-key',$opening), InvalidArgumentException::class));

echo "$passed/$total accounting cases passed\n";
exit($passed === $total ? 0 : 1);

