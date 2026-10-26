<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Local fail-closed model selection policy. Selecting a route is not spend approval. */
final class ProviderRoutePolicy
{
    private const QUOTE_MAX_AGE_SECONDS = 300;
    private const TOKENS_PER_MILLION = 1_000_000;
    private const MAX_TOKENS = 1_000_000;
    private const MAX_RATE_CENTS_PER_MILLION = 100_000_000;

    /**
     * Offers must contain current AUD price quotes, approved key status, measured quality,
     * task/language capabilities and an explicit data-class allowlist.
     * @return array{selected:?array,reason:string,alternatives:int}
     */
    public static function select(
        array $offers,
        string $taskType,
        string $dataClass,
        string $language,
        int $inputTokens,
        int $maxOutputTokens,
        int $minimumQualityPermille,
        int $maxCostCents,
        int $now,
    ): array {
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $taskType)
            || !in_array($dataClass, ['public','internal','sensitive','restricted'], true)
            || !preg_match('/^[a-z]{2,3}$/D', $language)
            || $inputTokens < 0 || $inputTokens > self::MAX_TOKENS
            || $maxOutputTokens < 1 || $maxOutputTokens > self::MAX_TOKENS
            || $minimumQualityPermille < 0 || $minimumQualityPermille > 1000
            || $maxCostCents < 0 || $now < 0) {
            throw new \InvalidArgumentException('Invalid model route request');
        }
        if ($dataClass === 'restricted') { return ['selected' => null, 'reason' => 'restricted_data_never_routed', 'alternatives' => 0]; }
        $eligible = [];
        foreach ($offers as $offer) {
            if (!is_array($offer) || !self::validOffer($offer, $now)) { continue; }
            if ($offer['key_status'] !== 'active'
                || !in_array($taskType, $offer['task_types'], true)
                || !in_array($language, $offer['languages'], true)
                || !in_array($dataClass, $offer['data_classes'], true)
                || $offer['quality_permille'] < $minimumQualityPermille) { continue; }
            $cost = self::estimateCost($inputTokens, $maxOutputTokens, $offer['input_cents_per_million'], $offer['output_cents_per_million']);
            if ($cost > $maxCostCents) { continue; }
            $eligible[] = [
                'provider' => $offer['provider'], 'model' => $offer['model'],
                'estimated_cost_cents' => $cost, 'quality_permille' => $offer['quality_permille'],
                'latency_ms' => $offer['latency_ms'], 'quote_epoch' => $offer['quote_epoch'],
            ];
        }
        if ($eligible === []) { return ['selected' => null, 'reason' => 'no_eligible_offer', 'alternatives' => 0]; }
        usort($eligible, static fn(array $a, array $b): int =>
            ($a['estimated_cost_cents'] <=> $b['estimated_cost_cents'])
            ?: ($b['quality_permille'] <=> $a['quality_permille'])
            ?: ($a['latency_ms'] <=> $b['latency_ms'])
            ?: strcmp($a['provider'] . '/' . $a['model'], $b['provider'] . '/' . $b['model'])
        );
        return ['selected' => $eligible[0], 'reason' => 'eligible_lowest_cost', 'alternatives' => count($eligible) - 1];
    }

    private static function validOffer(array $offer, int $now): bool
    {
        foreach (['provider','model','key_status','currency','quote_epoch','input_cents_per_million','output_cents_per_million','quality_permille','latency_ms','task_types','languages','data_classes'] as $field) {
            if (!array_key_exists($field, $offer)) { return false; }
        }
        if (!is_string($offer['provider']) || !preg_match('/^[a-z0-9][a-z0-9.-]{1,63}$/D', $offer['provider'])
            || !is_string($offer['model']) || !preg_match('/^[a-zA-Z0-9._:-]{1,96}$/D', $offer['model'])
            || !is_string($offer['key_status'])
            || ($offer['currency'] ?? null) !== 'AUD'
            || !is_int($offer['quote_epoch']) || $offer['quote_epoch'] < 0 || $offer['quote_epoch'] > $now || $offer['quote_epoch'] < $now - self::QUOTE_MAX_AGE_SECONDS
            || !is_int($offer['input_cents_per_million']) || $offer['input_cents_per_million'] < 0 || $offer['input_cents_per_million'] > self::MAX_RATE_CENTS_PER_MILLION
            || !is_int($offer['output_cents_per_million']) || $offer['output_cents_per_million'] < 0 || $offer['output_cents_per_million'] > self::MAX_RATE_CENTS_PER_MILLION
            || !is_int($offer['quality_permille']) || $offer['quality_permille'] < 0 || $offer['quality_permille'] > 1000
            || !is_int($offer['latency_ms']) || $offer['latency_ms'] < 1 || $offer['latency_ms'] > 600_000
            || !is_array($offer['task_types']) || !is_array($offer['languages']) || !is_array($offer['data_classes'])) { return false; }
        foreach ([$offer['task_types'], $offer['languages'], $offer['data_classes']] as $items) {
            foreach ($items as $item) { if (!is_string($item)) { return false; } }
        }
        return true;
    }

    private static function estimateCost(int $inputTokens, int $outputTokens, int $inputRate, int $outputRate): int
    {
        $numerator = ($inputTokens * $inputRate) + ($outputTokens * $outputRate);
        $whole = intdiv($numerator, self::TOKENS_PER_MILLION);
        return $whole + ($numerator % self::TOKENS_PER_MILLION === 0 ? 0 : 1);
    }
}
