# Kamau Putt — Session Resume / AI Handoff

**Last updated:** 2026-10-27, Australia/Sydney
**Canonical repo:** `parmtech/kamau-putt` (public; verified via connected GitHub account). The requested alias `manbir6-ux/kamau-putt` was not visible in that connector. Default branch: `main`.
**Active branch:** `feat/kp-financial-reservations-foundation`; PR #1. Blueprint remains draft PR #2 (`docs/a-to-z-blueprint`). Preserve both drafts and keep work backend-first, Android last.

## Owner decisions to preserve
- “Kamau Putt” is the private AI operator identity; customer-facing brands may differ and must disclose legal identity honestly.
- Initial seed preferred AUD 200–300, with **AUD 500 cumulative external-seed maximum**, not a monthly allowance. Earned funds may only be reinvested within approved envelopes and reserves.
- Routine operations may be autonomous only inside explicitly authorized budgets/scopes and after safety gates. Strategic, banking, regulated, major funding and hardware decisions stay with Dad.
- Only Dad may resume after STOP, after reviewing the risk/cost summary and explicit confirmation. Shutdown preserves refunds, fulfilment, legal duties and accounting.
- AI-provider routing must respect each provider's terms; extra keys/accounts do not authorize quota circumvention. No live spending, payment, supplier commitment or customer operation is enabled.
- Android/Punjabi voice is the last major delivery, after backend, web control, commerce and live pilot have been proven.

## Current implementation and evidence
- PR #1 contains PHP/MySQL prototypes for reservations, double-entry posting, owner auth/STOP-resume, encrypted credential vault, route policy, Owner HQ, and durable reservation-backed dispatch. The migration-009 baseline passed PHP workflow `40424583575` and all seven MySQL 8.4 suites in `40424583409`.
- Migration 010 and `PdoJobPayloadStore` are published on PR #1 head `ac622cbbc9c2201cda962c86e714ed2795430f48`: max-64-KiB JSON objects encrypted with reference-bound libsodium keys; immutable DB rows; one payload reference per job; missing/reused references rejected; purge only after a known terminal result. Fresh isolated MariaDB 11.4.13 passed 41/41 durable-job assertions. PHP workflow `40426074493` and all seven suites of MySQL 8.4 workflow `40426074511` passed on that head. The first upload truncated two source files; the corrected commit and green CI supersede the failing run `40425604353`.
- A provider-neutral HTTP adapter boundary, `DurableJobWorker::runOnce()` and cURL transport now exist. Commit `9744c7bea74eacc672cb1431ade9c6cd76247bc0` fixes the reservation-amount callback gap. PHP run `40428564989` and all seven MySQL 8.4 suites in run `40428565026` passed, including 42 durable-job cases and exact-amount assertion. The worker and adapter remain default-disabled. The local typed authorizer and versioned server-side catalog enforce action policies, payload bounds, active credential/provider/model binding and reserved-cost limits; no verified live provider quote is configured. A new configured-worker integration passes 8/8 on isolated MariaDB 11.4.13 using a synthetic owner/key/offer and fake transport. It verifies exact reservation context, active credential, model/output binding, provider I/O outside DB transactions, accepted settlement, over-reservation denial and revoked-key denial. The new MySQL 8.4 matrix suite is pending CI. cURL/TLS behavior, provider contract, supervised scheduler, reconciliation UI, hosting/staging verification, store, real payment/provider operation and Android app remain unverified or unbuilt.

## Next work
1. Review and publish the local configured-worker integration plus the final quote-expiry handling to PR #1, then verify the added MySQL 8.4 matrix suite. Keep live calls disabled.
2. Verify the new `mysql_durable_job_worker` MySQL 8.4 CI suite; then extend disposable DB coverage for payload-authentication failure, timeout ambiguity and STOP races. Obtain a genuinely reviewed provider-specific contract/quote and credential evidence before any live path.
3. Add provider-specific request/outcome contracts and cURL/TLS transport verification; then add owner reconciliation/recovery for uncertain attempts, supervised workers, production key custody/rotation and payload retention/deletion audit.
4. Continue the tracker in order: actual hosting preflight; threat/privacy and Australian legal checklist; cash/liability accounting; staging security and recovery drills; evidence-led product selection, test checkout, controlled pilot. Then Android/Punjabi voice last.

The typed provider authorizer is published in commit 918ad24c4d47ece0647199bd0cf89d3b1412b199 and PHP/MySQL 8.4 CI passed (runs 40429853666 and 40429853611). The local follow-up adds 8/8 configured-worker MariaDB coverage and maps it into the MySQL CI matrix; this integration and the latest quote-expiry no-effect refinement are not yet published or CI verified. No actual verified provider quote or production policy is configured, and the worker remains disabled.

## Runtime and safe test setup
- Local portable runtime is in workspace `.tools/`: PHP 8.5.12 and MariaDB 11.4.13. PHP's CLI configuration does not auto-load sodium; run local crypto DB checks with `php -d extension=sodium ...`.
- Use a newly created empty disposable database for each DB suite. The fresh test MariaDB instance was loopback-only at port 33317 and was shut down after verification. Do not point tests at a real or hosted database.
- Keep `.tools/`, database files, owner/customer data, authentication values and API secrets out of GitHub.
- Every meaningful change updates tracker, changelog, handoff and verification in the same commit when possible. Keep status distinctions explicit: implemented, locally tested, CI verified, staging verified, production verified.
