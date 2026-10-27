<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/**
 * Provider-neutral, disabled-by-default adapter boundary. Provider-specific request
 * construction and outcome interpretation are injected and must be reviewed separately.
 */
final class JsonProviderAdapter
{
    private readonly \Closure $requestBuilder;
    private readonly \Closure $responseParser;

    /** @param callable(array,array,array):array $requestBuilder @param callable(array):array $responseParser @param callable():int|float|null $clock */
    public function __construct(
        private readonly string $providerSlug,
        private readonly string $endpoint,
        private readonly ProviderHttpTransport $transport,
        callable $requestBuilder,
        callable $responseParser,
        private readonly bool $enabled = false,
        private readonly int $maxTimeoutMs = 10_000,
        private readonly int $maxResponseBytes = 65_536,
        private readonly mixed $clock = null,
        private readonly array $allowedHosts = [],
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,63}$/D', $providerSlug)) { throw new \InvalidArgumentException('Provider identifier is invalid'); }
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')
            || preg_match('/\.(?:localhost|local|internal|test|invalid)$/D', $host) === 1
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            throw new \InvalidArgumentException('Provider endpoint must be a fixed HTTPS URL');
        }
        if ($allowedHosts === [] || !in_array($host, array_map(static fn(mixed $allowed): string => is_string($allowed) ? strtolower(rtrim($allowed, '.')) : '', $allowedHosts), true)) {
            throw new \InvalidArgumentException('Provider endpoint host is not on the configured allowlist');
        }
        if ($maxTimeoutMs < 1 || $maxTimeoutMs > 15_000 || $maxResponseBytes < 1 || $maxResponseBytes > 262_144) {
            throw new \InvalidArgumentException('Provider adapter bounds are invalid');
        }
        $this->requestBuilder = $requestBuilder(...);
        $this->responseParser = $responseParser(...);
    }

    public function isEnabled(): bool { return $this->enabled; }
    public function providerSlug(): string { return $this->providerSlug; }

    /** Return only a normalized outcome; never include request, response or key data. */
    public function execute(array $attempt, array $payload, string $vaultProvider, string $apiKey, ProviderExecutionDecision $authorization): array
    {
        if (!$this->enabled) { throw new \RuntimeException('Provider adapter is disabled'); }
        if (!hash_equals($this->providerSlug, $vaultProvider)) { throw new \RuntimeException('Credential provider does not match adapter'); }
        if ($apiKey === '' || strlen($apiKey) > 2048 || preg_match('/[^\x21-\x7e]/', $apiKey)) { throw new \RuntimeException('Provider credential is invalid'); }
        $deadline = $attempt['attempt_deadline_epoch'] ?? null;
        $idempotencyKey = $attempt['provider_idempotency_key'] ?? null;
        if (!is_int($deadline) || !is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9._:-]{8,191}$/D', $idempotencyKey)
            || !is_string($attempt['action_type'] ?? null) || $payload === [] || array_is_list($payload)) {
            throw new \InvalidArgumentException('Provider attempt context is invalid');
        }
        if (!hash_equals($this->providerSlug, $authorization->provider)
            || !hash_equals((string) $attempt['action_type'], $authorization->actionType)
            || !is_int($attempt['reservation_amount_cents'] ?? null)
            || $authorization->reservationAmountCents !== $attempt['reservation_amount_cents']) {
            throw new \InvalidArgumentException('Provider authorization does not match the attempt');
        }
        if ($this->nowFloat() >= $deadline) { return ['outcome' => 'no_effect']; }

        $request = ($this->requestBuilder)($attempt, $payload, $authorization->toArray());
        if (!is_array($request) || $request === [] || array_is_list($request)) { throw new \RuntimeException('Provider request builder returned invalid data'); }
        if (($request['model'] ?? null) !== $authorization->model
            || ($request['max_output_tokens'] ?? null) !== $authorization->maxOutputTokens
            || ($request['input'] ?? null) !== ($payload['input'] ?? null)
            || array_keys($request) !== ['input', 'model', 'max_output_tokens']) {
            throw new \RuntimeException('Provider request did not honor the authorized content, model and output limit');
        }
        try { $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $error) { throw new \RuntimeException('Provider request could not be encoded', 0, $error); }
        if (strlen($body) > 65_536) { throw new \RuntimeException('Provider request exceeds its size limit'); }

        // Check as close as possible to send and cap transport time below the attempt deadline.
        // Account for sub-second time and leave a small margin for the transport call boundary.
        $remainingMs = (int) floor(($deadline - $this->nowFloat()) * 1000) - 25;
        if ($remainingMs < 1) { return ['outcome' => 'no_effect']; }
        $sendEpoch = (int) floor($this->nowFloat());
        if ($authorization->quoteEpoch > $sendEpoch || $authorization->quoteEpoch < $sendEpoch - 300) {
            return ['outcome' => 'no_effect'];
        }
        $timeoutMs = min($this->maxTimeoutMs, $remainingMs);
        try {
            $http = $this->transport->postJson($this->endpoint, [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ], $body, $timeoutMs, $this->maxResponseBytes, $deadline);
        } catch (ProviderDeadlineExceeded) { return ['outcome' => 'no_effect']; }
        if (!is_array($http) || !is_int($http['status'] ?? null) || !is_string($http['body'] ?? null)
            || strlen($http['body']) > $this->maxResponseBytes) {
            throw new \RuntimeException('Provider transport returned invalid data');
        }
        // A non-2xx response is ambiguous unless a provider-specific contract proves no effect.
        if ($http['status'] < 200 || $http['status'] >= 300) { return ['outcome' => 'unknown']; }
        try { $decoded = json_decode($http['body'], true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('Provider response is not valid JSON', 0, $error); }
        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) { throw new \RuntimeException('Provider response has an invalid shape'); }
        $result = ($this->responseParser)($decoded);
        if (!is_array($result) || !in_array($result['outcome'] ?? null, ['accepted', 'no_effect', 'unknown'], true)) {
            throw new \RuntimeException('Provider response parser returned an invalid outcome');
        }
        if ($result['outcome'] === 'accepted') {
            $reference = $result['reference'] ?? null;
            if (!is_string($reference) || !preg_match('/^[A-Za-z0-9._:-]{8,255}$/D', $reference)) {
                throw new \RuntimeException('Accepted provider response is missing a valid reference');
            }
            return ['outcome' => 'accepted', 'reference' => $reference];
        }
        // Generic parsers cannot establish provider-specific no-effect guarantees.
        // Only the transport can report no_effect, and only before any send begins.
        return ['outcome' => $result['outcome'] === 'no_effect' ? 'unknown' : $result['outcome']];
    }

    private function nowFloat(): float
    {
        $now = $this->clock === null ? microtime(true) : ($this->clock)();
        if ((!is_int($now) && !is_float($now)) || $now < 0 || !is_finite((float) $now)) { throw new \RuntimeException('Invalid system clock'); }
        return (float) $now;
    }
}
