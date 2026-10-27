<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/ProviderRoutePolicy.php';

use KamauPutt\Core\ProviderRoutePolicy;

$now = 1_800_000_000;
$offer = static fn(string $provider, int $rate, int $quality, array $classes = ['public','internal'], array $overrides = []): array => array_merge([
    'provider' => $provider, 'model' => 'model-1', 'key_status' => 'active', 'currency' => 'AUD',
    'quote_epoch' => $now - 60, 'input_cents_per_million' => $rate, 'output_cents_per_million' => $rate,
    'quality_permille' => $quality, 'latency_ms' => 100, 'task_types' => ['summarize'],
    'languages' => ['pa'], 'data_classes' => $classes,
], $overrides);
$passed = 0;
$check = static function (string $name, bool $condition) use (&$passed): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) { throw new RuntimeException('Failed provider route policy invariant: ' . $name); }
    $passed++;
};
$route = static fn(array $offers, string $data = 'public', int $minimum = 800, int $cap = 100): array => ProviderRoutePolicy::select($offers, 'summarize', $data, 'pa', 250, 500, $minimum, $cap, $now);

$selected = $route([$offer('alpha', 10_000, 900), $offer('beta', 5_000, 799), $offer('gamma', 15_000, 950)]);
$check('selects_cheapest_offer_that_meets_quality_floor', $selected['selected']['provider'] === 'alpha' && $selected['selected']['estimated_cost_cents'] === 8 && $selected['alternatives'] === 1);
$sensitive = $route([$offer('public-only', 1, 1000), $offer('private-ready', 12_000, 900, ['public','sensitive'])], 'sensitive');
$check('sensitive_data_requires_explicit_offer_allowlist', $sensitive['selected']['provider'] === 'private-ready');
$restricted = $route([$offer('anything', 1, 1000, ['restricted'])], 'restricted');
$check('restricted_data_is_never_routed', $restricted['selected'] === null && $restricted['reason'] === 'restricted_data_never_routed');
$stale = $offer('stale', 1, 1000, ['public'], ['quote_epoch' => $now - 301]);
$check('stale_price_quote_is_rejected', $route([$stale])['selected'] === null);
$future = $offer('future', 1, 1000, ['public'], ['quote_epoch' => $now + 1]);
$check('future_dated_quote_is_rejected', $route([$future])['selected'] === null);
$inactive = $offer('inactive', 1, 1000, ['public'], ['key_status' => 'pending']);
$check('inactive_key_is_rejected', $route([$inactive])['selected'] === null);
$wrongCurrency = $offer('usd-quote', 1, 1000, ['public'], ['currency' => 'USD']);
$check('non_aud_quote_is_rejected', $route([$wrongCurrency])['selected'] === null);
$wrongTask = $offer('wrong-task', 1, 1000, ['public'], ['task_types' => ['translate']]);
$check('unsupported_task_is_rejected', $route([$wrongTask])['selected'] === null);
$wrongLanguage = $offer('wrong-language', 1, 1000, ['public'], ['languages' => ['en']]);
$check('unsupported_language_is_rejected', $route([$wrongLanguage])['selected'] === null);
$wrongPrivacy = $offer('no-private', 1, 1000, ['public']);
$check('disallowed_data_class_is_rejected', $route([$wrongPrivacy], 'internal')['selected'] === null);
$expensive = $offer('too-expensive', 100_000, 1000, ['public']);
$check('hard_action_cost_cap_excludes_offer', $route([$expensive], 'public', 800, 10)['selected'] === null);
$tie = $route([$offer('zeta', 10_000, 900, ['public'], ['latency_ms' => 250]), $offer('eta', 10_000, 900, ['public'], ['latency_ms' => 100])]);
$check('equal_cost_prefers_lower_latency', $tie['selected']['provider'] === 'eta');
$badInputDenied = false;
try { ProviderRoutePolicy::select([], 'summarize', 'public', 'pa', 1_000_001, 10, 800, 100, $now); }
catch (InvalidArgumentException) { $badInputDenied = true; }
$check('oversized_token_estimate_is_rejected', $badInputDenied);
echo $passed . " provider route cases passed\n";
