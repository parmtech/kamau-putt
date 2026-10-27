<?php

declare(strict_types=1);

namespace KamauPutt\Core;

/** Immutable, versioned server-side snapshot of provider offers and their source references. */
final class TrustedProviderOfferCatalog
{
    private readonly array $offers;
    private readonly string $hash;

    /**
     * The catalog is trusted configuration, not data loaded from a job payload or an LLM.
     * Each source URL/reference must be reviewed when the server-side snapshot is updated.
     * @param list<array<string,mixed>> $offers
     */
    public function __construct(public readonly string $version, array $offers)
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,63}$/D', $version) || $offers === [] || !array_is_list($offers)) {
            throw new \InvalidArgumentException('Offer catalog version or contents are invalid');
        }
        foreach ($offers as $index => $offer) {
            if (!is_array($offer) || !is_string($offer['quote_reference'] ?? null)
                || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $offer['quote_reference'])
                || !is_string($offer['source_url'] ?? null) || !self::isHttpsSource($offer['source_url'])
                || !is_int($offer['quote_epoch'] ?? null) || $offer['quote_epoch'] < 0) {
                throw new \InvalidArgumentException('Offer catalog entry lacks reviewed quote provenance');
            }
            // Key activation is a live vault state, never a claim trusted from the catalog.
            $offers[$index]['key_status'] = 'inactive';
        }
        $canonical = self::canonicalJsonValue(['version' => $version, 'offers' => $offers]);
        $this->hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->offers = $offers;
    }

    /** @return list<array<string,mixed>> */
    public function offers(): array { return $this->offers; }

    public function contentHash(): string { return $this->hash; }

    private static function isHttpsSource(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { return false; }
        $host = strtolower(rtrim($parts['host'], '.'));
        return filter_var($host, FILTER_VALIDATE_IP) === false && str_contains($host, '.')
            && preg_match('/\.(?:localhost|local|internal|test|invalid)$/D', $host) !== 1;
    }

    private static function canonicalJsonValue(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (array_is_list($value)) { return array_map(self::canonicalJsonValue(...), $value); }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) { $value[$key] = self::canonicalJsonValue($child); }
        return $value;
    }
}
