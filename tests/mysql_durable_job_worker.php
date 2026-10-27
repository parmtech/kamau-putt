<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';
require_once __DIR__ . '/../src/Core/PdoOwnerControl.php';
require_once __DIR__ . '/../src/Core/PdoSpendReservations.php';
require_once __DIR__ . '/../src/Core/PdoDurableJobs.php';
require_once __DIR__ . '/../src/Core/PdoJobPayloadStore.php';
require_once __DIR__ . '/../src/Core/PdoCredentialVault.php';
require_once __DIR__ . '/../src/Core/ProviderHttpTransport.php';
require_once __DIR__ . '/../src/Core/ProviderRoutePolicy.php';
require_once __DIR__ . '/../src/Core/TrustedProviderOfferCatalog.php';
require_once __DIR__ . '/../src/Core/ProviderExecutionDecision.php';
require_once __DIR__ . '/../src/Core/ProviderExecutionAuthorizer.php';
require_once __DIR__ . '/../src/Core/JsonProviderAdapter.php';
require_once __DIR__ . '/../src/Core/DurableJobWorker.php';

use KamauPutt\Core\DurableJobWorker;
use KamauPutt\Core\JsonProviderAdapter;
use KamauPutt\Core\PdoCredentialVault;
use KamauPutt\Core\PdoDurableJobs;
use KamauPutt\Core\PdoJobPayloadStore;
use KamauPutt\Core\PdoOwnerAuth;
use KamauPutt\Core\PdoOwnerControl;
use KamauPutt\Core\PdoSpendReservations;
use KamauPutt\Core\ProviderExecutionAuthorizer;
use KamauPutt\Core\ProviderHttpTransport;
use KamauPutt\Core\TrustedProviderOfferCatalog;

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
    throw new RuntimeException('Worker test requires an EMPTY disposable database');
}
foreach ([
    '001_financial_control.mysql.sql', '002_double_entry_ledger.mysql.sql',
    '004_owner_auth.mysql.sql', '005_owner_control.mysql.sql', '006_owner_control_audit.mysql.sql',
    '007_provider_credential_vault.mysql.sql', '008_durable_jobs.mysql.sql',
    '009_durable_job_dispatch_attempt.mysql.sql', '010_encrypted_job_payloads.mysql.sql',
] as $migration) {
    $sql = file_get_contents(__DIR__ . '/../database/' . $migration);
    if (!is_string($sql)) { throw new RuntimeException('Worker test migration is unavailable: ' . $migration); }
    foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) {
        if (trim($statement) !== '') { $db->exec(trim($statement)); }
    }
}

$now = 2_000_000_000;
$clock = static function () use (&$now): int { return $now; };
$ownerEmail = 'worker-owner@example.test';
$ownerPassword = 'synthetic-worker-owner-password-123';
$totpSecret = PdoOwnerAuth::generateTotpSecret();
$auth = new PdoOwnerAuth($db, random_bytes(32), random_bytes(32), $clock);
$auth->bootstrapOwner($ownerEmail, $ownerPassword, $totpSecret);
$vault = new PdoCredentialVault($db, random_bytes(32), $clock);
$jobs = new PdoDurableJobs($db, $clock);
$payloads = new PdoJobPayloadStore($db, random_bytes(32), $clock);
$reservations = new PdoSpendReservations($db);
$control = new PdoOwnerControl($db, $clock);
$db->exec("UPDATE kp_finance_control SET operating_mode='growth',owner_emergency_stopped=0,dispatch_epoch=1 WHERE id=1");
$db->exec("INSERT INTO kp_budget_envelopes(scope,approved_limit_cents,per_action_cap_cents) VALUES('ai_api',10000,1000)");
$db->exec('UPDATE kp_financial_position SET settled_cash_cents=20000 WHERE id=1');

$totpCode = static function () use (&$now, $totpSecret): string {
    $bits = '';
    foreach (str_split($totpSecret) as $char) {
        $value = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
        if ($value === false) { throw new RuntimeException('Synthetic TOTP secret is malformed'); }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $key .= chr(bindec($byte)); } }
    $step = intdiv($now, 30);
    $hash = hash_hmac('sha1', pack('N2', intdiv($step, 0x100000000), $step % 0x100000000), $key, true);
    $offset = ord($hash[19]) & 15;
    $number = ((ord($hash[$offset]) & 127) << 24)
        | ((ord($hash[$offset + 1]) & 255) << 16)
        | ((ord($hash[$offset + 2]) & 255) << 8)
        | (ord($hash[$offset + 3]) & 255);
    return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
};
$login = $auth->authenticate($ownerEmail, $ownerPassword, $totpCode(), '192.0.2.77');
if (!($login['authenticated'] ?? false)) { throw new RuntimeException('Synthetic owner login failed'); }
$credentialSecret = 'synthetic-provider-key-123456789';
$credential = $vault->storePending($login['session_token'], $login['csrf_token'], 'example.ai', 'worker integration fixture', $credentialSecret);
$vault->recordSmokeTest((int) $credential['id'], true, 'synthetic-request-0001', 20);
$now += 30;
$stepUp = $auth->authenticate($ownerEmail, $ownerPassword, $totpCode(), '192.0.2.77');
if (!($stepUp['authenticated'] ?? false)) { throw new RuntimeException('Synthetic owner step-up failed'); }
$vault->activate($stepUp['session_token'], $stepUp['csrf_token'], (int) $credential['id'], 'ACTIVATE CREDENTIAL');

$offer = [
    'provider' => 'example.ai', 'model' => 'model-1', 'key_status' => 'inactive', 'currency' => 'AUD',
    'quote_epoch' => $now - 60, 'input_cents_per_million' => 100_000, 'output_cents_per_million' => 100_000,
    'quality_permille' => 920, 'latency_ms' => 100, 'task_types' => ['summarize'],
    'languages' => ['pa'], 'data_classes' => ['public'], 'quote_reference' => 'synthetic-quote-0001',
    'source_url' => 'https://pricing.example.com/synthetic-fixture',
];
$catalog = new TrustedProviderOfferCatalog('worker-fixture-v1', [$offer]);
$actionPolicies = [
    'model_request' => [
        'task_type' => 'summarize', 'language' => 'pa', 'data_class' => 'public',
        'minimum_quality_permille' => 850, 'max_input_bytes' => 64, 'input_overhead_tokens' => 4,
        'max_output_tokens' => 20, 'max_cost_cents' => 20, 'credential_id' => (int) $credential['id'],
    ],
];
$executionAuthorizer = new ProviderExecutionAuthorizer($catalog, $actionPolicies, $clock);

final class WorkerIntegrationFakeTransport implements ProviderHttpTransport
{
    public array $calls = [];
    public array $response = ['status' => 200, 'body' => '{"id":"synthetic-provider-ref-0001"}'];

    public function __construct(private readonly PDO $db) {}

    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes, int $attemptDeadlineEpoch): array
    {
        $this->calls[] = [
            'endpoint' => $endpoint, 'headers' => $headers, 'body' => $body,
            'timeout_ms' => $timeoutMs, 'deadline_epoch' => $attemptDeadlineEpoch,
            'in_transaction' => $this->db->inTransaction(),
        ];
        return $this->response;
    }
}
$transport = new WorkerIntegrationFakeTransport($db);
$requestBuilder = static fn(array $attempt, array $payload, array $decision): array => [
    'input' => $payload['input'],
    'model' => $decision['model'],
    'max_output_tokens' => $decision['max_output_tokens'],
];
$responseParser = static fn(array $json): array => ['outcome' => 'accepted', 'reference' => $json['id'] ?? null];
$adapter = new JsonProviderAdapter(
    'example.ai', 'https://api.example.ai/v1/run', $transport,
    $requestBuilder, $responseParser, true, 10_000, 1024, $clock, ['api.example.ai'],
);
$route = [
    'credential_id' => (int) $credential['id'], 'adapter' => $adapter, 'model' => 'model-1',
    'allowed_data_classes' => ['public'],
];
$capture = [];
$authorizer = static function (array $attempt, array $payload, array $context) use ($executionAuthorizer, &$capture): ?\KamauPutt\Core\ProviderExecutionDecision {
    $capture = ['attempt' => $attempt, 'payload' => $payload, 'credential_metadata' => $context['credential_metadata'] ?? null];
    return $executionAuthorizer($attempt, $payload, $context);
};
$worker = new DurableJobWorker($jobs, $payloads, $vault, ['model_request' => $route], true, $clock, $authorizer);

$passed = 0;
$check = static function (string $label, bool $condition) use (&$passed): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) { throw new RuntimeException('Failed configured worker invariant: ' . $label); }
    $passed++;
};
$enqueue = static function (string $key, int $amountCents, array $payload) use ($reservations, $payloads, $jobs): array {
    $reserved = $reservations->reserve($key, 'ai_api', 'model_request', $amountCents, 1000, true);
    if (!($reserved['allowed'] ?? false)) { throw new RuntimeException('Synthetic reservation failed: ' . $key); }
    $payloadRef = $payloads->store($payload);
    return $jobs->enqueue($key, 'worker-job-' . $key, $payloadRef);
};

$good = $enqueue('worker-reserve-0001', 20, ['input' => 'hello']);
$first = $worker->runOnce();
$request = $transport->calls[0] ?? [];
$body = json_decode((string) ($request['body'] ?? ''), true);
$check('configured_worker_executes_one_synthetic_request_and_completes', $first['status'] === 'completed'
    && count($transport->calls) === 1 && $first['job_id'] === $good['id']);
$check('authorizer_receives_exact_locked_reservation_and_active_credential', ($capture['attempt']['reservation_amount_cents'] ?? null) === 20
    && ($capture['credential_metadata']['status'] ?? null) === 'active'
    && ($capture['credential_metadata']['provider'] ?? null) === 'example.ai');
$check('authorized_model_output_cap_and_stable_idempotency_reach_fake_transport', ($body['model'] ?? null) === 'model-1'
    && ($body['max_output_tokens'] ?? null) === 20
    && ($request['headers']['Idempotency-Key'] ?? null) === 'kp-' . hash('sha256', 'worker-job-worker-reserve-0001'));
$check('fake_provider_io_runs_outside_database_transaction_and_uses_synthetic_key', ($request['in_transaction'] ?? true) === false
    && ($request['headers']['Authorization'] ?? null) === 'Bearer ' . $credentialSecret);
$firstReservation = $db->prepare('SELECT status,amount_cents,external_reference FROM kp_spend_reservations WHERE idempotency_key=?');
$firstReservation->execute(['worker-reserve-0001']);
$settled = $firstReservation->fetch(PDO::FETCH_ASSOC);
$check('accepted_worker_result_settles_exact_reservation_once', $settled['status'] === 'committed'
    && (int) $settled['amount_cents'] === 20 && $settled['external_reference'] === 'synthetic-provider-ref-0001');

$beforeDenied = count($transport->calls);
$denied = $enqueue('worker-reserve-0002', 1, ['input' => 'hello']);
$deniedRun = $worker->runOnce();
$deniedState = $db->prepare('SELECT status FROM kp_spend_reservations WHERE idempotency_key=?');
$deniedState->execute(['worker-reserve-0002']);
$check('quote_cost_above_reserved_amount_is_denied_before_transport', $deniedRun['status'] === 'failed_no_effect'
    && count($transport->calls) === $beforeDenied && $deniedState->fetchColumn() === 'released');

$vault->revoke($stepUp['session_token'], $stepUp['csrf_token'], (int) $credential['id']);
$beforeRevoked = count($transport->calls);
$revoked = $enqueue('worker-reserve-0003', 20, ['input' => 'hello']);
$revokedRun = $worker->runOnce();
$revokedState = $db->prepare('SELECT status FROM kp_spend_reservations WHERE idempotency_key=?');
$revokedState->execute(['worker-reserve-0003']);
$check('revoked_credential_is_denied_before_transport', $revokedRun['status'] === 'failed_no_effect'
    && count($transport->calls) === $beforeRevoked && $revokedState->fetchColumn() === 'released');

$check('worker_dispatch_audit_chain_verifies_after_settlement_and_denials', $jobs->verifyAudit()['events'] > 0);
echo $passed . " configured MySQL worker cases passed\n";
