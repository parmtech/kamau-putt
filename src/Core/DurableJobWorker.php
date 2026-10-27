<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use Throwable;

/** One-shot worker orchestration. It remains disabled unless explicitly configured on. */
final class DurableJobWorker
{
    /**
     * @param array<string,array{credential_id:int,adapter:JsonProviderAdapter,allowed_data_classes:list<string>}> $routes
     * @param callable(array,array):bool|null $executionAuthorizer Trusted deterministic policy gate; required to run.
     * @param callable():int|null $clock
     */
    public function __construct(
        private readonly PdoDurableJobs $jobs,
        private readonly PdoJobPayloadStore $payloads,
        private readonly PdoCredentialVault $vault,
        private readonly array $routes,
        private readonly bool $enabled = false,
        private readonly mixed $clock = null,
        ?callable $executionAuthorizer = null,
    ) {
        $this->executionAuthorizer = $executionAuthorizer === null ? null : $executionAuthorizer(...);
        foreach ($routes as $actionType => $route) {
            if (!is_string($actionType) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $actionType)
                || !is_array($route) || !is_int($route['credential_id'] ?? null) || $route['credential_id'] < 1
                || !(($route['adapter'] ?? null) instanceof JsonProviderAdapter)
                || !is_array($route['allowed_data_classes'] ?? null) || $route['allowed_data_classes'] === []) {
                throw new \InvalidArgumentException('Worker route configuration is invalid');
            }
            foreach ($route['allowed_data_classes'] as $class) {
                if (!is_string($class) || !in_array($class, ['public', 'internal', 'sensitive'], true)) {
                    throw new \InvalidArgumentException('Worker data-class allowlist is invalid');
                }
            }
        }
    }

    private readonly ?\Closure $executionAuthorizer;

    /** Claim and process no more than one job. Returns metadata only; never returns payload or secret. */
    public function runOnce(): array
    {
        if (!$this->enabled || !$this->hasEnabledRoute() || $this->executionAuthorizer === null) { return ['status' => 'disabled']; }
        $lease = $this->jobs->claimNext();
        if ($lease === null) { return ['status' => 'idle']; }
        $route = $this->routes[$lease['action_type']] ?? null;
        $result = $this->jobs->dispatch($lease['id'], $lease['lease_token'], function (array $attempt) use ($route): array {
            if ($route === null || !$route['adapter']->isEnabled()) { return ['outcome' => 'no_effect']; }
            if ($this->now() >= (int) $attempt['attempt_deadline_epoch']) { return ['outcome' => 'no_effect']; }
            try { $payload = $this->payloads->load((string) $attempt['payload_ref']); }
            catch (Throwable) { return ['outcome' => 'no_effect']; }

            // Deny sensitive/disallowed payloads before opening the provider credential.
            $dataClass = $payload['data_class'] ?? null;
            if (!is_string($dataClass) || !in_array($dataClass, $route['allowed_data_classes'], true)) {
                unset($payload);
                return ['outcome' => 'no_effect'];
            }
            try { $authorized = ($this->executionAuthorizer)($attempt, $payload); }
            catch (Throwable) { unset($payload); return ['outcome' => 'no_effect']; }
            if ($authorized !== true) { unset($payload); return ['outcome' => 'no_effect']; }

            $enteredAdapter = false;
            try {
                return $this->vault->withActiveSecret((int) $route['credential_id'], function (string $provider, string $apiKey) use ($route, $attempt, $payload, &$enteredAdapter): array {
                    $enteredAdapter = true;
                    return $route['adapter']->execute($attempt, $payload, $provider, $apiKey);
                });
            } catch (Throwable $error) {
                // Vault lookup/decryption failed before any adapter call: known no external effect.
                // Once adapter execution begins, every exception is ambiguous and must hold funds.
                if (!$enteredAdapter) { return ['outcome' => 'no_effect']; }
                throw $error;
            } finally { unset($payload); }
        });
        return ['status' => (string) ($result['status'] ?? ($result['dispatched'] ? 'dispatched' : 'not_dispatched')), 'job_id' => (string) $lease['id'], 'result' => $result];
    }

    private function hasEnabledRoute(): bool
    {
        foreach ($this->routes as $route) { if ($route['adapter']->isEnabled()) { return true; } }
        return false;
    }

    private function now(): int
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($now) || $now < 0) { throw new \RuntimeException('Invalid system clock'); }
        return $now;
    }
}
