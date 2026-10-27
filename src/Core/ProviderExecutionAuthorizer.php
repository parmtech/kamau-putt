<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Deterministic, fail-closed authorizer for a configured single-provider worker route. */
final class ProviderExecutionAuthorizer
{
    /**
     * Policies and offers are server-side reviewed configuration. Payloads may contain only the configured text field.
     * Each policy requires task_type, language, data_class, minimum_quality_permille, max_input_bytes,
     * input_overhead_tokens, max_output_tokens, max_cost_cents, and credential_id.
     * @param array<string,array<string,int|string>> $actionPolicies
     * @param callable():int|null $clock
     */
    public function __construct(
        private readonly TrustedProviderOfferCatalog $catalog,
        private readonly array $actionPolicies,
        private readonly mixed $clock = null,
    ) {
        foreach ($actionPolicies as $action => $policy) {
            if (!is_string($action) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $action)
                || !is_array($policy)
                || !is_string($policy['task_type'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $policy['task_type'])
                || !is_string($policy['language'] ?? null) || !preg_match('/^[a-z]{2,3}$/D', $policy['language'])
                || !is_string($policy['data_class'] ?? null) || !in_array($policy['data_class'], ['public','internal','sensitive'], true)
                || !is_int($policy['minimum_quality_permille'] ?? null) || $policy['minimum_quality_permille'] < 0 || $policy['minimum_quality_permille'] > 1000
                || !is_int($policy['max_input_bytes'] ?? null) || $policy['max_input_bytes'] < 1 || $policy['max_input_bytes'] > 65_536
                || !is_int($policy['input_overhead_tokens'] ?? null) || $policy['input_overhead_tokens'] < 0 || $policy['input_overhead_tokens'] > 65_536
                || !is_int($policy['max_output_tokens'] ?? null) || $policy['max_output_tokens'] < 1 || $policy['max_output_tokens'] > 1_000_000
                || !is_int($policy['max_cost_cents'] ?? null) || $policy['max_cost_cents'] < 1
                || !is_int($policy['credential_id'] ?? null) || $policy['credential_id'] < 1) {
                throw new \InvalidArgumentException('Provider execution policy is invalid');
            }
        }
    }

    /** Return a typed decision, or null when any action, data, route, credential or spend gate denies. */
    public function __invoke(array $attempt, array $payload, array $route): ?ProviderExecutionDecision
    {
        $action = $attempt['action_type'] ?? null;
        $policy = is_string($action) ? ($this->actionPolicies[$action] ?? null) : null;
        $reservation = $attempt['reservation_amount_cents'] ?? null;
        if (!is_array($policy) || !is_int($reservation) || $reservation < 1
            || !(($route['adapter'] ?? null) instanceof JsonProviderAdapter)
            || !is_int($route['credential_id'] ?? null) || !is_string($route['model'] ?? null)
            || !is_array($route['allowed_data_classes'] ?? null)
            || !in_array($policy['data_class'], $route['allowed_data_classes'], true)) { return null; }

        // Payload data classes, task names, model choices and token counts are deliberately ignored.
        if (array_keys($payload) !== ['input'] || !is_string($payload['input'])
            || strlen($payload['input']) > $policy['max_input_bytes']) { return null; }
        if ($route['credential_id'] !== $policy['credential_id']) { return null; }

        $credential = $route['credential_metadata'] ?? null;
        if (!is_array($credential) || ($credential['status'] ?? null) !== 'active'
            || !is_string($credential['provider'] ?? null)
            || !hash_equals($route['adapter']->providerSlug(), $credential['provider'])) { return null; }

        $offers = $this->catalog->offers();
        foreach ($offers as &$offer) {
            $offer['key_status'] = ($offer['provider'] ?? null) === $credential['provider']
                && ($offer['model'] ?? null) === $route['model'] ? 'active' : 'inactive';
        }
        unset($offer);
        $inputTokenUpperBound = strlen($payload['input']) + $policy['input_overhead_tokens'];
        $maxCost = min($policy['max_cost_cents'], $reservation);
        try {
            $selection = ProviderRoutePolicy::select(
                $offers,
                $policy['task_type'],
                $policy['data_class'],
                $policy['language'],
                $inputTokenUpperBound,
                $policy['max_output_tokens'],
                $policy['minimum_quality_permille'],
                $maxCost,
                $this->now(),
            );
        } catch (\Throwable) { return null; }
        $selected = $selection['selected'] ?? null;
        if (!is_array($selected) || ($selected['provider'] ?? null) !== $credential['provider']
            || ($selected['provider'] ?? null) !== $route['adapter']->providerSlug()
            || ($selected['model'] ?? null) !== $route['model']) { return null; }

        if (!is_string($selected['quote_reference'] ?? null) || !is_string($selected['source_url'] ?? null)) { return null; }
        return new ProviderExecutionDecision(
            $action,
            $selected['provider'],
            $selected['model'],
            $this->catalog->version,
            $this->catalog->contentHash(),
            $selected['quote_reference'],
            $selected['source_url'],
            $selected['quote_epoch'],
            $policy['task_type'],
            $policy['language'],
            $policy['data_class'],
            $inputTokenUpperBound,
            $policy['max_output_tokens'],
            $selected['estimated_cost_cents'],
            $maxCost,
            $reservation,
            $policy['credential_id'],
        );
    }

    private function now(): int
    {
        $value = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($value) || $value < 0) { throw new \RuntimeException('Invalid system clock'); }
        return $value;
    }
}
