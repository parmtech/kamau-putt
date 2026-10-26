# Provider routing policy foundation

`src/Core/ProviderRoutePolicy.php` is a deterministic local selector. It accepts trusted offer metadata and returns the lowest estimated-cost candidate that passes every policy filter; quality, latency and provider/model names break ties in that order. The class makes no network calls and never handles provider secrets.

Each offer must carry an active-key status, provider/model identifiers, AUD cents per million input/output tokens, a quote timestamp no more than five minutes old, measured quality on a 0–1000 scale, latency, and explicit task, language and data-class allowlists. Invalid or stale offers are skipped. Estimated cost uses the requested input token count plus the maximum output token count and rounds up to the next AUD cent. The selected estimate must fit the supplied action cap.

Data classes are `public`, `internal`, `sensitive` and `restricted`. `restricted` data is never routed. Other classes require an explicit match in the offer allowlist. An owner-approved quality floor, supported task and language are required; a low price cannot override those constraints.

## Limits and integration gates

The caller must obtain quotes and measurements from a trusted source and set the action cap from the current approved budget. This policy does not verify provider contracts, privacy terms, key activation in the database, benchmark provenance or quote authenticity; it also does not reserve funds. A selected offer still needs a transactional reservation and a durable outbox lease before any call. No offer catalog, live quote feed, provider adapter, benchmark process, privacy review or model fallback implementation exists. Current policy cases run in PHP CI, but do not constitute live provider testing.
