<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/ProviderHttpTransport.php';
require_once __DIR__ . '/../src/Core/ProviderRoutePolicy.php';
require_once __DIR__ . '/../src/Core/TrustedProviderOfferCatalog.php';
require_once __DIR__ . '/../src/Core/ProviderExecutionDecision.php';
require_once __DIR__ . '/../src/Core/JsonProviderAdapter.php';
require_once __DIR__ . '/../src/Core/ProviderExecutionAuthorizer.php';

use KamauPutt\Core\JsonProviderAdapter;
use KamauPutt\Core\ProviderExecutionAuthorizer;
use KamauPutt\Core\ProviderHttpTransport;
use KamauPutt\Core\ProviderRoutePolicy;
use KamauPutt\Core\TrustedProviderOfferCatalog;

final class NeverUsedAuthorizerTransport implements ProviderHttpTransport
{
    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes, int $attemptDeadlineEpoch): array
    { throw new RuntimeException('authorization tests must never send HTTP'); }
}

$now = 2_000_000_000;
$offer = static fn(array $overrides = []): array => array_merge([
    'provider' => 'example.ai', 'model' => 'model-1', 'key_status' => 'revoked', 'currency' => 'AUD',
    'quote_epoch' => $now - 60, 'input_cents_per_million' => 100_000, 'output_cents_per_million' => 100_000,
    'quality_permille' => 920, 'latency_ms' => 250, 'task_types' => ['summarize'],
    'languages' => ['pa'], 'data_classes' => ['sensitive'], 'quote_reference' => 'quote-ref-0001',
    'source_url' => 'https://pricing.example.com/rates',
], $overrides);
$catalog = new TrustedProviderOfferCatalog('fixture-v1', [$offer()]);
$policy = [
    'model_request' => [
        'task_type' => 'summarize', 'language' => 'pa', 'data_class' => 'sensitive',
        'minimum_quality_permille' => 850, 'max_input_bytes' => 64, 'input_overhead_tokens' => 4,
        'max_output_tokens' => 20, 'max_cost_cents' => 20, 'credential_id' => 7,
    ],
];
$adapter = new JsonProviderAdapter(
    'example.ai', 'https://api.example.ai/v1/run', new NeverUsedAuthorizerTransport(),
    static fn(array $attempt, array $payload, array $authorization): array => [
        'input' => $payload['input'], 'model' => $authorization['model'], 'max_output_tokens' => $authorization['max_output_tokens'],
    ],
    static fn(array $response): array => ['outcome' => 'unknown'],
    allowedHosts: ['api.example.ai'],
);
$route = [
    'credential_id' => 7, 'adapter' => $adapter, 'model' => 'model-1',
    'allowed_data_classes' => ['sensitive'],
    'credential_metadata' => ['provider' => 'example.ai', 'status' => 'active'],
];
$attempt = ['action_type' => 'model_request', 'reservation_amount_cents' => 50];
$payload = ['input' => 'hello'];
$authorize = static function (array $offers, ?array $actionPolicies = null) use ($policy, $now): ProviderExecutionAuthorizer {
    return new ProviderExecutionAuthorizer(
        new TrustedProviderOfferCatalog('fixture-v1', $offers), $actionPolicies ?? $policy, static fn(): int => $now,
    );
};
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) { throw new RuntimeException('Failed provider authorization invariant: ' . $name); }
    $passed++;
};
$decision = ($authorize([$offer()]))($attempt, $payload, $route);
$check('issues_typed_decision_from_server_policy_and_versioned_quote', $decision !== null
    && $decision->provider === 'example.ai' && $decision->model === 'model-1'
    && $decision->taskType === 'summarize' && $decision->language === 'pa'
    && $decision->dataClass === 'sensitive' && $decision->inputTokenUpperBound === 9
    && $decision->maxOutputTokens === 20 && $decision->estimatedCostCents === 3
    && $decision->maxCostCents === 20 && $decision->reservationAmountCents === 50
    && $decision->credentialId === 7 && $decision->quoteReference === 'quote-ref-0001'
    && $decision->catalogHash === $catalog->contentHash());
$check('catalog_hash_is_stable_and_key_status_is_not_trusted_from_catalog', $catalog->contentHash() === (new TrustedProviderOfferCatalog('fixture-v1', [$offer()]))->contentHash()
    && $catalog->offers()[0]['key_status'] === 'inactive');
$smallReserveAttempt = array_replace($attempt, ['reservation_amount_cents' => 2]);
$check('estimated_cost_cannot_exceed_locked_reservation', ($authorize([$offer()]))($smallReserveAttempt, $payload, $route) === null);
$capPolicy = $policy;
$capPolicy['model_request']['max_cost_cents'] = 2;
$check('estimated_cost_cannot_exceed_action_cost_cap', ($authorize([$offer()], $capPolicy))($attempt, $payload, $route) === null);
$check('stale_quote_is_denied', ($authorize([$offer(['quote_epoch' => $now - 301])]))($attempt, $payload, $route) === null);
$check('unsupported_task_is_denied', ($authorize([$offer(['task_types' => ['translate']])]))($attempt, $payload, $route) === null);
$check('unsupported_language_is_denied', ($authorize([$offer(['languages' => ['en']])]))($attempt, $payload, $route) === null);
$check('disallowed_data_class_is_denied', ($authorize([$offer(['data_classes' => ['public']])]))($attempt, $payload, $route) === null);
$check('quality_below_policy_floor_is_denied', ($authorize([$offer(['quality_permille' => 849])]))($attempt, $payload, $route) === null);
$check('route_model_mismatch_is_denied', ($authorize([$offer()]))($attempt, $payload, array_replace($route, ['model' => 'other-model'])) === null);
$wrongProviderRoute = array_replace($route, ['adapter' => new JsonProviderAdapter(
    'other.ai', 'https://api.other.ai/v1/run', new NeverUsedAuthorizerTransport(),
    static fn(array $a, array $p, array $d): array => ['input' => $p['input'], 'model' => $d['model'], 'max_output_tokens' => $d['max_output_tokens']],
    static fn(array $r): array => ['outcome' => 'unknown'], allowedHosts: ['api.other.ai'],
)]);
$check('credential_provider_must_match_adapter', ($authorize([$offer()]))($attempt, $payload, $wrongProviderRoute) === null);
$revoked = array_replace($route, ['credential_metadata' => ['provider' => 'example.ai', 'status' => 'revoked']]);
$check('inactive_credential_is_denied_before_secret_access', ($authorize([$offer()]))($attempt, $payload, $revoked) === null);
$wrongCredential = array_replace($route, ['credential_id' => 8]);
$check('credential_id_must_match_server_action_policy', ($authorize([$offer()]))($attempt, $payload, $wrongCredential) === null);
$check('payload_cannot_override_data_class_task_or_model', ($authorize([$offer()]))($attempt, ['input' => 'hello', 'data_class' => 'public'], $route) === null);
$check('caller_token_count_is_rejected_as_an_extra_payload_field', ($authorize([$offer()]))($attempt, ['input' => 'hello', 'input_tokens' => 1], $route) === null);
$check('oversized_input_is_denied', ($authorize([$offer()]))($attempt, ['input' => str_repeat('x', 65)], $route) === null);
$check('unknown_action_is_denied', ($authorize([$offer()]))(['action_type' => 'unknown', 'reservation_amount_cents' => 50], $payload, $route) === null);
$restrictedPolicy = $policy;
$restrictedPolicy['model_request']['data_class'] = 'restricted';
$restrictedPolicyRejected = false;
try { $authorize([$offer()], $restrictedPolicy); } catch (InvalidArgumentException) { $restrictedPolicyRejected = true; }
$check('restricted_data_policy_cannot_be_configured_for_external_routing', $restrictedPolicyRejected);
$badSourceRejected = false;
try { new TrustedProviderOfferCatalog('fixture-v1', [$offer(['source_url' => 'http://pricing.example.com/rates'])]); }
catch (InvalidArgumentException) { $badSourceRejected = true; }
$check('offer_catalog_requires_https_provenance', $badSourceRejected);
echo $passed . " provider execution authorization cases passed\n";
