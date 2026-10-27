<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/ProviderHttpTransport.php';
require_once __DIR__ . '/../src/Core/ProviderExecutionDecision.php';
require_once __DIR__ . '/../src/Core/JsonProviderAdapter.php';

use KamauPutt\Core\JsonProviderAdapter;
use KamauPutt\Core\ProviderExecutionDecision;
use KamauPutt\Core\ProviderDeadlineExceeded;
use KamauPutt\Core\ProviderHttpTransport;

final class FakeProviderTransport implements ProviderHttpTransport
{
    public array $calls = [];
    public array $response = ['status' => 200, 'body' => '{"id":"provider-job-123","ok":true}'];
    public bool $throwOnSend = false;
    public mixed $beforeSend = null;
    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes, int $attemptDeadlineEpoch): array
    {
        if (is_callable($this->beforeSend) && ($this->beforeSend)() >= $attemptDeadlineEpoch) {
            throw new ProviderDeadlineExceeded('simulated pre-send expiry');
        }
        $this->calls[] = compact('endpoint', 'headers', 'body', 'timeoutMs', 'maxResponseBytes');
        if ($this->throwOnSend) { throw new RuntimeException('simulated transport failure'); }
        return $this->response;
    }
}

$failed = 0;
$test = static function (string $name, bool $ok) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) { $failed++; }
};
$now = 2_000_000_000;
$transport = new FakeProviderTransport();
$builder = static fn(array $attempt, array $payload, array $authorization): array => [
    'input' => $payload['input'],
    'model' => $authorization['model'], 'max_output_tokens' => $authorization['max_output_tokens'],
];
$parser = static fn(array $json): array => ['outcome' => 'accepted', 'reference' => $json['id'] ?? null];
$attempt = ['action_type' => 'model_request', 'attempt_deadline_epoch' => $now + 5, 'provider_idempotency_key' => 'kp-stable-key-0001', 'reservation_amount_cents' => 100];
$payload = ['input' => 'private prompt'];
$authorization = new ProviderExecutionDecision(
    'model_request', 'example.ai', 'model-1', 'fixture-v1', str_repeat('a', 64),
    'quote-ref-0001', 'https://pricing.example.com/rates', $now - 60,
    'summarize', 'pa', 'sensitive', 32, 50, 2, 20, 100, 7,
);

$disabled = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $builder, $parser, clock: static fn(): int => $now, allowedHosts: ['api.example.ai']);
try { $disabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $disabledRejected = false; }
catch (RuntimeException) { $disabledRejected = true; }
$test('adapter_is_disabled_by_default_without_transport_call', $disabledRejected && $transport->calls === []);

$enabled = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $builder, $parser, true, 10_000, 1024, static fn(): int => $now, ['api.example.ai']);
$result = $enabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization);
$call = $transport->calls[0] ?? [];
$test('valid_response_is_normalized_and_idempotency_is_stable', $result === ['outcome' => 'accepted', 'reference' => 'provider-job-123']
    && ($call['headers']['Idempotency-Key'] ?? null) === $attempt['provider_idempotency_key']);
$test('transport_timeout_is_below_attempt_deadline', ($call['timeoutMs'] ?? 0) > 0 && $call['timeoutMs'] <= 5000);
$test('request_builder_must_use_authorized_content_model_and_output_cap', ($call['body'] ?? '') === '{"input":"private prompt","model":"model-1","max_output_tokens":50}' && !isset($result['secret']));
$beforeBadModel = count($transport->calls);
$badModelBuilder = static fn(array $attempt, array $payload, array $authorization): array => [
    'model' => 'unapproved-model', 'max_output_tokens' => $authorization['max_output_tokens'],
];
$badModelAdapter = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $badModelBuilder, $parser, true, 10_000, 1024, static fn(): int => $now, ['api.example.ai']);
try { $badModelAdapter->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $badModelRejected = false; }
catch (RuntimeException) { $badModelRejected = true; }
$test('unauthorized_model_is_rejected_before_transport', $badModelRejected && count($transport->calls) === $beforeBadModel);
$beforeBadOutput = count($transport->calls);
$badOutputBuilder = static fn(array $attempt, array $payload, array $authorization): array => [
    'model' => $authorization['model'], 'max_output_tokens' => $authorization['max_output_tokens'] + 1,
];
$badOutputAdapter = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $badOutputBuilder, $parser, true, 10_000, 1024, static fn(): int => $now, ['api.example.ai']);
try { $badOutputAdapter->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $badOutputRejected = false; }
catch (RuntimeException) { $badOutputRejected = true; }
$test('output_limit_cannot_exceed_authorized_cap', $badOutputRejected && count($transport->calls) === $beforeBadOutput);

$beforeExtraInput = count($transport->calls);
$extraInputBuilder = static fn(array $attempt, array $payload, array $authorization): array => [
    'input' => $payload['input'] . str_repeat(' additional billable text', 100),
    'model' => $authorization['model'], 'max_output_tokens' => $authorization['max_output_tokens'],
];
$extraInputAdapter = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $extraInputBuilder, $parser, true, 10_000, 1024, static fn(): int => $now, ['api.example.ai']);
try { $extraInputAdapter->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $extraInputRejected = false; }
catch (RuntimeException) { $extraInputRejected = true; }
$test('request_builder_cannot_add_unbudgeted_billable_input', $extraInputRejected && count($transport->calls) === $beforeExtraInput);

$quoteBoundaryClock = $now;
$quoteBoundaryBuilder = static function (array $attempt, array $payload, array $authorization) use (&$quoteBoundaryClock): array {
    $quoteBoundaryClock += 241;
    return ['input' => $payload['input'], 'model' => $authorization['model'], 'max_output_tokens' => $authorization['max_output_tokens']];
};
$quoteBoundaryAdapter = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $quoteBoundaryBuilder, $parser, true, 10_000, 1024, static function () use (&$quoteBoundaryClock): int { return $quoteBoundaryClock; }, ['api.example.ai']);
$beforeStaleQuote = count($transport->calls);
try { $quoteBoundaryAdapter->execute(array_replace($attempt, ['attempt_deadline_epoch' => $now + 1000]), $payload, 'example.ai', 'test-secret-123456', $authorization); $staleQuoteRejected = false; }
catch (RuntimeException) { $staleQuoteRejected = true; }
$test('quote_that_expires_during_request_build_is_rejected_before_transport', $staleQuoteRejected && count($transport->calls) === $beforeStaleQuote);

$beforeExpired = count($transport->calls);
$expired = $enabled->execute(array_replace($attempt, ['attempt_deadline_epoch' => $now]), $payload, 'example.ai', 'test-secret-123456', $authorization);
$test('expired_attempt_has_no_network_effect', $expired === ['outcome' => 'no_effect'] && count($transport->calls) === $beforeExpired);

$clockNow = $now;
$lateBuilder = static function (array $attempt, array $payload, array $authorization) use (&$clockNow, $now): array {
    $clockNow = $now + 5;
    return ['input' => $payload['input'], 'model' => $authorization['model'], 'max_output_tokens' => $authorization['max_output_tokens']];
};
$preSendGuard = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $lateBuilder, $parser, true, 10_000, 1024, static function () use (&$clockNow): int { return $clockNow; }, ['api.example.ai']);
$beforeLateBuilder = count($transport->calls);
$lateBuildOutcome = $preSendGuard->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization);
$test('deadline_expiry_during_request_build_has_no_network_effect', $lateBuildOutcome === ['outcome' => 'no_effect'] && count($transport->calls) === $beforeLateBuilder);
$clockNow = $now;
$transport->beforeSend = static function () use (&$clockNow, $now): int { $clockNow = $now + 5; return $clockNow; };
$entryDelayAdapter = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $builder, $parser, true, 10_000, 1024, static function () use (&$clockNow): int { return $clockNow; }, ['api.example.ai']);
$beforeTransportEntry = count($transport->calls);
$lateTransportOutcome = $entryDelayAdapter->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization);
$test('transport_entry_deadline_check_prevents_send_after_pause', $lateTransportOutcome === ['outcome' => 'no_effect'] && count($transport->calls) === $beforeTransportEntry);
$transport->beforeSend = null;

$transport->response = ['status' => 503, 'body' => '{"error":"uncertain"}'];
$test('non_success_http_status_is_unknown', $enabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization) === ['outcome' => 'unknown']);
$transport->response = ['status' => 200, 'body' => 'not-json'];
try { $enabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $malformedRejected = false; }
catch (RuntimeException) { $malformedRejected = true; }
$test('malformed_json_fails_closed', $malformedRejected);
$transport->response = ['status' => 200, 'body' => str_repeat('x', 1025)];
try { $enabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $oversizeRejected = false; }
catch (RuntimeException) { $oversizeRejected = true; }
$test('oversized_response_fails_closed', $oversizeRejected);
$transport->response = ['status' => 200, 'body' => '{"id":"x"}'];
try { $enabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $badReferenceRejected = false; }
catch (RuntimeException) { $badReferenceRejected = true; }
$test('accepted_result_requires_valid_external_reference', $badReferenceRejected);

try { new JsonProviderAdapter('example.ai', 'http://127.0.0.1/private', $transport, $builder, $parser, allowedHosts: ['127.0.0.1']); $ssrfRejected = false; }
catch (InvalidArgumentException) { $ssrfRejected = true; }
$test('non_https_endpoint_is_rejected', $ssrfRejected);

try { new JsonProviderAdapter('example.ai', 'https://127.0.0.1/private', $transport, $builder, $parser, allowedHosts: ['api.example.ai']); $hostRejected = false; }
catch (InvalidArgumentException) { $hostRejected = true; }
$test('unlisted_endpoint_host_is_rejected', $hostRejected);
$invalidPublicEndpoints = ['https://api.example.ai:8443/v1/run', 'https://127.0.0.1/private', 'https://[::1]/private', 'https://localhost/private', 'https://db.internal/private'];
$privateAndCustomPortRejected = true;
foreach ($invalidPublicEndpoints as $endpoint) {
    try { new JsonProviderAdapter('example.ai', $endpoint, $transport, $builder, $parser, allowedHosts: ['api.example.ai', '127.0.0.1', '::1', 'localhost', 'db.internal']); $privateAndCustomPortRejected = false; }
    catch (InvalidArgumentException) {}
}
$test('custom_ports_and_local_or_ip_endpoints_are_rejected', $privateAndCustomPortRejected);
$normalizedHost = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $builder, $parser, allowedHosts: ['API.EXAMPLE.AI.']);
$test('hostname_allowlist_normalizes_case_and_trailing_dot', !$normalizedHost->isEnabled());

$transport->response = ['status' => 200, 'body' => '{"rejected":true}'];
$noEffectParser = static fn(array $json): array => ['outcome' => 'no_effect'];
$genericNoEffect = new JsonProviderAdapter('example.ai', 'https://api.example.ai/v1/run', $transport, $builder, $noEffectParser, true, 10_000, 1024, static fn(): int => $now, ['api.example.ai']);
$test('generic_response_parser_cannot_release_funds_as_no_effect', $genericNoEffect->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization) === ['outcome' => 'unknown']);

$transport->throwOnSend = true;
try { $enabled->execute($attempt, $payload, 'example.ai', 'test-secret-123456', $authorization); $transportFailurePropagates = false; }
catch (RuntimeException) { $transportFailurePropagates = true; }
$test('transport_exception_is_not_reported_as_known_no_effect', $transportFailurePropagates);

printf("%d/%d tests passed\n", 19 - $failed, 19);
exit($failed === 0 ? 0 : 1);
