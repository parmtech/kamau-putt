<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Production HTTPS transport. It deliberately refuses redirects and unbounded response bodies. */
final class CurlJsonTransport implements ProviderHttpTransport
{
    public function postJson(string $endpoint, array $headers, string $body, int $timeoutMs, int $maxResponseBytes, int $attemptDeadlineEpoch): array
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('Bounded provider transport is unavailable'); }
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')
            || preg_match('/\.(?:localhost|local|internal|test|invalid)$/D', $host) === 1
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            throw new \InvalidArgumentException('Provider endpoint must be a fixed HTTPS URL');
        }
        if ($timeoutMs < 1 || $timeoutMs > 15_000 || $maxResponseBytes < 1 || $maxResponseBytes > 262_144
            || strlen($body) > 65_536 || $headers === []) {
            throw new \InvalidArgumentException('Provider transport bounds are invalid');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name)
                || !is_string($value) || strlen($value) > 4096 || preg_match('/[\r\n\x00]/', $value)) {
                throw new \InvalidArgumentException('Provider request headers are invalid');
            }
            $headerLines[] = $name . ': ' . $value;
        }

        $response = '';
        $handle = curl_init($endpoint);
        if ($handle === false) { throw new \RuntimeException('Provider transport initialization failed'); }
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, $maxResponseBytes): int {
                    $length = strlen($chunk);
                    if (strlen($response) + $length > $maxResponseBytes) { return 0; }
                    $response .= $chunk;
                    return $length;
                },
            ]);
            // Recheck at the actual transport boundary. A timeout is only known no-effect
            // when the request has not started; cURL errors after this point stay ambiguous.
            $remainingMs = (int) floor(($attemptDeadlineEpoch - microtime(true)) * 1000) - 25;
            if ($remainingMs < 1) { throw new ProviderDeadlineExceeded('Provider attempt expired before request start'); }
            $effectiveTimeoutMs = min($timeoutMs, $remainingMs);
            curl_setopt($handle, CURLOPT_TIMEOUT_MS, $effectiveTimeoutMs);
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, min(3000, $effectiveTimeoutMs));
            $ok = curl_exec($handle);
            if ($ok !== true) {
                // Do not expose cURL's error text: it may contain endpoint or request details.
                throw new \RuntimeException('Provider transport failed or exceeded its response limit');
            }
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 100 || $status > 599) { throw new \RuntimeException('Provider returned an invalid HTTP status'); }
            return ['status' => $status, 'body' => $response];
        } finally { curl_close($handle); }
    }
}
