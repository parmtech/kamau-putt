<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Bounded HTTPS JSON transport. Implementations must not log headers or bodies. */
interface ProviderHttpTransport
{
    /** @return array{status:int,body:string} */
    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes): array;
}
