<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/PdoDurableJobs.php';
require_once __DIR__ . '/../src/Core/PdoJobPayloadStore.php';
require_once __DIR__ . '/../src/Core/PdoCredentialVault.php';
require_once __DIR__ . '/../src/Core/ProviderHttpTransport.php';
require_once __DIR__ . '/../src/Core/JsonProviderAdapter.php';
require_once __DIR__ . '/../src/Core/DurableJobWorker.php';

use KamauPutt\Core\DurableJobWorker;
use KamauPutt\Core\JsonProviderAdapter;
use KamauPutt\Core\PdoCredentialVault;
use KamauPutt\Core\PdoDurableJobs;
use KamauPutt\Core\PdoJobPayloadStore;
use KamauPutt\Core\ProviderHttpTransport;

final class NeverUsedWorkerPdo extends PDO
{
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : null; }
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
}

final class NeverUsedWorkerTransport implements ProviderHttpTransport
{
    public int $calls = 0;
    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes, int $attemptDeadlineEpoch): array
    {
        $this->calls++;
        throw new RuntimeException('transport should not be reached');
    }
}

$db = new NeverUsedWorkerPdo();
$jobs = new PdoDurableJobs($db);
$payloads = (new ReflectionClass(PdoJobPayloadStore::class))->newInstanceWithoutConstructor();
$vault = (new ReflectionClass(PdoCredentialVault::class))->newInstanceWithoutConstructor();
$transport = new NeverUsedWorkerTransport();
$adapter = new JsonProviderAdapter(
    'example.ai', 'https://api.example.ai/v1/run', $transport,
    static fn(array $attempt, array $payload): array => ['input' => $payload['input'] ?? ''],
    static fn(array $response): array => ['outcome' => 'unknown'],
    allowedHosts: ['api.example.ai'],
);

$worker = new DurableJobWorker($jobs, $payloads, $vault, ['model_request' => [
    'credential_id' => 1,
    'adapter' => $adapter,
    'allowed_data_classes' => ['public'],
]]);
$result = $worker->runOnce();
$pass = $result === ['status' => 'disabled'] && $transport->calls === 0;
echo ($pass ? 'PASS ' : 'FAIL ') . 'default_disabled_worker_does_not_claim_jobs_or_send_requests' . PHP_EOL;
echo ($pass ? '1/1 tests passed' : '0/1 tests passed') . PHP_EOL;
exit($pass ? 0 : 1);
