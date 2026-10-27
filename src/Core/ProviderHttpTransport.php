<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Bounded HTTPS JSON transport. Implementations must not log headers or bodies. */
interface ProviderHttpTransport
{
    /** @return array{status:int,body:string} Throw ProviderDeadlineExceeded only when no request was sent. */
    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes, int $attemptDeadlineEpoch): array;
}

/** The transport confirmed it did not begin a provider request before the attempt deadline. */
final class ProviderDeadlineExceeded extends \RuntimeException {}
