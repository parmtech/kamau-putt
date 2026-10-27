# Kamau Putt — A to Z implementation blueprint
Date: 2026-10-27 (Australia/Sydney)
Status: DESIGN BASELINE with verified prototype progress on draft PR #1; staging and production are separate gates.
Read with MASTER_PLAN.md, DECISIONS.md and PROJECT_TRACKER.md. Backend first; Android last. This expands existing requirements without granting live spending or changing owner decisions.

## Implementation evidence snapshot — 2026-10-27
The design is written A to Z, while implementation, review, staging and production remain separate. Draft PR #1 remains open and unmerged. Code head `ac622cbbc9c2201cda962c86e714ed2795430f48` contains migration 010 and encrypted, bounded job-payload storage on the existing two-phase durable dispatch path. The earlier source-upload truncation was corrected at this commit.

- Fresh isolated PHP 8.5.12 + MariaDB 11.4.13: durable-job suite **41/41** passed, including ciphertext confidentiality, round-trip decrypt, wrong-key failure, reference-swap rejection, immutable payload rows, payload size/shape limits, single-job binding and terminal-only purge. Pure suites: policy 11/11, financial math 10/10, provider routing 13/13; PHP lint across 25 files, Node syntax, Composer manifest and whitespace checks passed.
- Migration-009 baseline was verified by MySQL 8.4 run `40424583409` (all seven disposable database suites) and PHP run `40424583575`. On corrected migration-010 code head `ac622cbbc9c2201cda962c86e714ed2795430f48`, PHP workflow `40426074493` passed and MySQL 8.4 workflow `40426074511` passed all seven suites. The earlier `40425604353` run failed because the source upload had truncated files; its code was corrected before the green runs.
- Current payload rows use per-reference derived keys from a separate 32-byte sodium master key; SQL blocks updates. Enqueue requires a stored unused reference, and purge rejects nonterminal or unlinked jobs. They passed local MariaDB 11.4.13 and MySQL 8.4 CI on the corrected code head.
- Latest PR #1 code commit `20902f261f4b9458a93736a551962f8035051dfa` includes the hardened provider-neutral HTTPS JSON adapter and a default-disabled one-shot worker that maps action types to adapters, active vault credentials and data-class allowlists after dispatch authorization. Local PHP 8.5.12 adapter tests pass 17/17 and the disabled-worker guard passes 1/1. PHP and MySQL 8.4 runs `40427587644` and `40427587526` are queued/in progress; no CI pass is claimed for this head yet.
- No real provider call, live spending, deployment, staging, browser-integrated HQ, verified hosting, commercial compliance, backup restore or profitability is established. A disabled-by-default one-shot worker now connects the queue, encrypted payload store, credential vault and adapter, but its configured MySQL path has not been tested and no supervised worker exists. Fake-transport tests do not exercise cURL/TLS or any provider contract. Provider-specific API contracts, reconciliation UI, persistent memory and Android/voice app remain open.

See the current tracker for item-level status. Keep this blueprint as the A–Z design baseline; update this snapshot as PR #1 progresses.

## A. Purpose and completion definition
One private, owner-controlled AI business operator, with Punjabi Dad conversation, persistent memory and bounded delegated authority. Start with one Australia-focused low-liability dropshipping product. Success means reconciled cash and positive contribution after all obligations, not revenue alone. No profit or uptime promise. V1 excludes multi-brand expansion, robots and model training.

## B. Authority and consent
Dad controls legal identity, seed funding, new paid subscriptions, live launch, new provider-key activation, exceptional permissions, production releases and emergency resume. Routine research and operations may run within explicitly recorded scopes and budget envelopes; per-action owner approval is not required for every ordinary authorized task. Default deny for missing or expired authority. An LLM proposes actions; deterministic server code decides permission.
Approval records bind owner, action/scope, maximum amount, expiry, environment and policy version. Changed material terms invalidate approval.

## C. Deployment decision
Proposed modular PHP backend with Composer autoloading and MySQL-compatible InnoDB. Use a currently supported PHP version after host verification; existing PHP 8.2+ code is a provisional foundation, not a final runtime commitment.
Web HQ and bounded cron-triggered workers first. Measure actual host PHP/extensions, DB version/transactions, TLS, SSH, scheduler limits, execution/memory limits and backup restore. If constraints cannot meet requirements, keep autonomy disabled and present a costed hosting alternative to Dad; no automatic purchase. Voice transport may need a separately approved service later.

## D. Components and trust boundaries
Owner web/mobile clients -> authenticated API -> permissions and financial policy -> transactional repositories/outbox -> bounded workers -> provider adapters.
Core modules: identity/auth; policy; accounting; scheduler; memory/journal; vault/router; research; commerce; notifications; reporting.
Public storefront/customer agent has brand-specific access only. Customer input, websites, documents and tool outputs are untrusted data, never privileged instructions. Models cannot obtain vault keys, raw DB authority or private Dad memory through customer tools.

## E. Data contract
Use UTC timestamps, display Australia/Sydney, integer AUD cents and explicit currency. Reject mixed currencies until a tested conversion module exists.
Proposed entities:
- owners, sessions, MFA/recovery metadata, approval records, role/scope grants;
- system_control(singleton stop epoch, mode, version), budget_envelopes, reservations;
- ledger_accounts, ledger_transactions, ledger_lines, provider_events, reconciliation_runs;
- jobs, leases, outbox_messages, action_attempts, audit_events;
- memories(provenance, owner, classification, confidence, expiry), conversations, journal_entries, agent_state(version);
- credential_metadata and encrypted secret payloads with key versions;
- brands, products, supplier_quotes, evidence_sources, offers;
- orders, order_lines, payment_attempts, fulfilments, refunds, support_cases;
- availability_windows, notification_attempts, calls and usage_costs.
Use foreign keys, unique business idempotency keys, migration versions and optimistic versions. Ledger corrections append reversing/correcting transactions. Never silently mutate posted history. Minimize personal data and keep secrets outside public webroot and logs.

## F. Money and accounting
Separate owner seed (AUD 200–300 preferred, AUD 500 cumulative maximum), earned cash, unsettled processor balances, commitments and liabilities. Track seed as a cumulative funding counter; ordinary refunds do not reset it.
Chart of accounts covers bank/processor clearing, receivables, customer/refund/tax liabilities, supplier payables, owner capital, revenue, cost of goods, shipping, fees, ads and API expense. Each posted transaction balances debits and credits per currency. Never count unsettled payouts or customer obligations as spendable profit.
Free cash is a documented reconciliation result after protected obligations, reserves and existing reservations, without counting the same obligation twice. Missing/stale balances deny new discretionary spend.
Reserve caps apply globally and to action, brand, campaign and time window. Configure reserve amounts and operating envelopes with Dad; unconfigured envelopes authorize zero spend.

## G. Paid-action protocol
1. Validate scope, approval expiry, currency and conservative maximum total charge.
2. In one DB transaction lock control, funding snapshot and affected budgets in a consistent order. Verify stop epoch, mode, freshness, free cash and caps; create unique reservation, audit event and outbox item.
3. Worker claims a bounded lease and checks current stop/approval immediately before dispatch. Record durable dispatch attempt and provider idempotency key before external call.
4. Provider success commits actual charge within reserved maximum; known rejection releases; ambiguous timeout enters UNCERTAIN and retains funds.
5. Reconcile uncertain attempts by provider key/reference before retry. Never interpret duplicate request as new spend authority. If provider lacks safe idempotency, require reconciliation/manual handling rather than automatic repeat.
6. Expiry alone cannot release a dispatched or uncertain reservation. Refunds use distinct keys and compensating ledger entries.
External dispatch cannot be made atomic with the DB. Stop blocks future authorizations and dispatches but may race an in-flight provider call; record exposure, cancel where possible and reconcile. Do not promise reversal of an accepted charge.

## H. Emergency control and financial modes
Durable owner stop increments an epoch and gates discretionary tools, queues and new orders when fulfilment cannot be guaranteed. Workers compare epochs and stop retries. Track provider cancellations individually; report pending or failed cancellations.
Customer service, refunds and existing lawful obligations remain recorded and require an explicit obligation-handling policy; a stopped business must not abandon them.
Resume requires fresh owner authentication/MFA and a one-use, short-lived confirmation bound to the latest state summary, stop epoch and maximum remaining exposure. No AI, schedule, reboot or restored backup can clear stop.
Modes: staging/disabled by default; controlled Growth only after gates; Survival suspends discretionary spend; Safe Shutdown prevents unsafe new orders while retaining records and duties. Thresholds are owner-configured and tested.

## I. Authentication, vault and threat model
Owner login with MFA, secure session cookies, CSRF controls, rate limiting, session revocation and step-up authentication for sensitive actions. Mobile uses revocable scoped credentials and secure OS storage. Voice alone is not identity proof.
Encrypt credentials with authenticated encryption, versioned keys outside repository/database backup trust boundary and owner recovery material. Avoid custom cryptography; choose maintained libraries at implementation review. Rotation, revocation and redaction are test cases.
Threats: account takeover, prompt injection, webhook forgery/replay, duplicate spending, compromised dependencies/host, memory poisoning, cross-brand data leakage and backup theft. Each needs least privilege, audit evidence, negative tests and an incident runbook.

## J. Durable job engine
DB-backed queue: READY -> LEASED -> SUCCEEDED, RETRY_WAIT or DEAD; uncertain financial actions reconcile instead of ordinary retry. Unique scheduled occurrence keys avoid duplicate cron runs. Bounded exponential backoff with jitter, attempt ceilings and dead-letter alerts. Leases prevent concurrent claims; expired leases trigger recovery based on action state, not blind redispatch. Every task has time/token/money bounds and a correlation ID.
No always-running worker assumed on shared hosting. No sub-minute or daily-call availability promise until measured infrastructure supports it.

Implementation status (2026-10-27): the reservation-backed outbox has one-attempt dispatch, STOP fencing, exact-attempt settlement and unknown-result retention. Migration 010 stores request payloads separately using bounded JSON and reference-bound secretbox encryption; enqueue requires one encrypted payload per job. Local MariaDB passes 41/41 durable-job cases. A supervised scheduler, provider adapter, reconciliation operations, key rotation and staging tests remain open.

## K. Persistent Brain
Separate identity/preferences, sourced facts, conversation summaries, business evidence and immutable audit records. Memory includes provenance, confidence, sensitivity and expiry. Owner can correct/export/delete personal memory subject to required record retention. Untrusted content cannot rewrite authority or verified facts.
One authoritative agent state uses version checks/leases. Model replacement changes inference, not identity or accounting. Nightly reflection proposes measurable changes; changes to money/auth controls require isolated tests and owner-approved release.

## L. Model and credential routing
Provider-neutral adapters normalize request, response, timeout and usage. Owner enters keys server-side; bounded tests run within an explicit test allowance; Dad approves activation. Route by task quality, privacy class, availability and cost ceilings. Quota exhaustion never authorizes evasion through accounts.
Reserve conservative usage before paid requests; enforce output/time/retry ceilings; reconcile reported usage and stop on unavailable pricing or cost bounds. No named model, pricing or privacy suitability is assumed current; verify official terms at selection.

## M. Owner web HQ and reporting
Views: status and stop; approvals/exposure; settled cash/reserves/commitments; ledger/reconciliation; jobs/incidents; research evidence; orders/refunds/support; memory/journal; provider health and costs. Show evidence timestamps and distinguish planned, tested, deployed and unknown.
Daily concise report: cash, obligations, costs, orders, contribution, incidents, decisions needed and next actions. Report cadence and channels are pending owner preference. Never disclose secrets in notifications.

## N. Research and product selection
Evidence records URL, observation time, delivered competitor price, supplier stock/lead time, reliability, claims and uncertainty. Use existing provisional score; hard veto unresolved product safety, unverifiable supplier/claims or negative risk-adjusted margin.
Produce Reject / Research More / Organic Test / Capped Launch report with sources and assumptions. Select one SKU only after current supplier and regulatory checks. No automatic paid research tools or commitments.

## O. Pricing and commercial gates
Calculate landed unit cost, delivery, processing, packaging where applicable, expected refunds/chargebacks, support, ads and API allocation. Use scenario ranges rather than guaranteed demand or CAC. Stock and shipping quotes expire.
Free Standard / paid Fast Australia only when independently supported by supplier evidence. Clear total price, realistic delivery, returns, contact and legal seller information. Tax/GST, consumer rights, privacy, marketing consent, product safety and business-name obligations require current official-source review before launch; this blueprint is not a legal determination.

## P. Store and order state machines
Offer version and supplier quote bind checkout; server computes totals. Start with Stripe test mode and separate test/live credentials.
Order: CREATED -> PAYMENT_PENDING -> PAID -> FULFILMENT_PENDING -> ORDERED -> SHIPPED -> DELIVERED; cancellation, refund and exception states are explicit.
Payment success does not prove supplier purchase; shipment claim needs evidence. Signed webhooks are verified, deduplicated and processed transactionally with outbox actions. Browser success page is not payment proof.
Supplier purchase needs separate financial authorization and idempotency. Stock failure/late delivery opens support workflow and refund handling. Customer agents cannot promise unsupported refunds or delivery dates.

## Q. Marketing and measurement
Begin organic with 3–5 honest creatives and tagged links. Record consent, attribution limits and cohort contribution after refunds. No fake reviews/scarcity. Paid campaigns require funded envelopes, verified stop controls and owner-authorized launch. Provider-side spend may lag local reporting; account for worst-case exposure and external caps.
Scale only after observed fulfilment, reconciliation and positive economics over a documented evaluation window approved before the pilot.

## R. Notifications and Punjabi relationship
Private Punjabi conversation calls owner Dad, with concise risks and optional details. Store timezone-aware availability with expiry; rejected/missed calls are respected. Routine contacts stay in agreed windows; emergency category and retry limits need owner configuration. Before Android, use approved web notifications/manual summaries; no claim of reliable incoming calls yet.

## S. Android delivery (last)
Use the same authenticated backend; no provider secrets in APK. Deliver chat, portfolio, ledger, journal, stop/reviewed resume, Punjabi voice and two-way calls. Prototype push/incoming-call transport on actual devices, including permissions, lock screen, background, battery limits, network loss and rejected calls. Choose transport/vendor only after evidence and cost approval. Show text fallback and missed-call record. Recording/transcript consent and retention are explicit settings.

## T. Backups and recovery
Proposed targets for owner review: daily encrypted off-host backup, RPO <=24h; RTO <=24h initially, subject to restore drill. These are targets, not verified service guarantees. Include DB, migration/config versions and encrypted memory; key recovery is separately protected. Use a documented retention/deletion schedule and least-privilege backup identity.
Restore first into isolated staging, retain stop=true, invalidate sessions, reconcile provider state and prove one authoritative instance before any resume. Never restore into automatic spending.

## U. Operations and incidents
Monitor failed jobs, stale reconciliation, reservation age, balance mismatch, security events, backup age and provider costs. Alert with severity, impact, exposure and next action. On compromise: stop, revoke sessions/keys, preserve evidence, assess customer impact, restore verified release and reconcile; required reporting is checked against current obligations.
Maintenance retains customer contact/refund routes. Health checks must not expose secrets or imply business readiness.

## V. Validation matrix
- Finance: parallel reservations, boundaries, duplicate keys, stale snapshots, deadlocks, provider timeout, crash before/after dispatch, partial refunds and ledger balance.
- Stop: stop/resume races, restart persistence, expired/replayed confirmation, unprivileged/model attempts, in-flight external exposure.
- Auth/privacy: session/CSRF/MFA negatives, vault rotation, redaction, prompt injection and cross-brand access denial.
- Jobs: overlapping cron, lease loss, duplicate event, poison task and bounded retries.
- Commerce: test checkout/webhooks, repeated/out-of-order events, stock failure, refunds, customer support and reconciliation.
- Recovery: isolated backup restore, stop persistence, key recovery and split-brain prevention.
- Android: real-device call/notification tests and authentication.
Document exact environment, command, result and unresolved cases. PHP run `40310403543` passed 13 provider-route cases. MySQL run `40309790257` passed the seven existing DB suites, including 20 durable-job cases; run `40247547998` passed vault lifecycle coverage. These are prototype CI results, not live provider or deployment evidence.

## W. Delivery gates and dependencies
G0: hosting feasibility and scoped security/commercial review.
G1: schema/auth/vault/journal and green reproducible CI. Prototype components now exist on unmerged PR #1; independent review, safe recovery, privacy review and staging gates still apply.
G2: transactional finance, stop/resume, accounting, reconciliation and independent staging failure tests.
G3: memory/router/queue/web HQ plus bounded-cost and restore demonstrations.
G4: sourced single-product decision and end-to-end test commerce.
G5: owner-approved live pilot with funded caps, support and incident readiness.
G6: measured stable economics before brands/scale.
G7: Android last, real-device QA.
Map to existing KP-003..KP-114 tasks; do not reset progress or check implementation boxes for this document.

## X. Existing work integration
Main contains the initial PHP preflight library. Draft PR #1 contains the current backend prototypes and is still unmerged; latest code checks passed on MySQL 8.4 runs `40309790257` and `40310403535`, and PHP runs `40309790387` and `40310403543`. Review and reuse those changes rather than rebuilding. This documentation proposal does not approve, replace or merge PR #1. Reconcile overlapping README/tracker/handoff edits before merging either branch. Earlier MASTER_PLAN section 14 Android-first language is superseded by DECISIONS item 14 and ROADMAP.

## Y. Owner decisions still needed
Ask one at a time; use already confirmed choices without re-asking.
1. Initial autonomy: research/recommendations only until explicit live launch, or a later bounded routine-operations mandate? Proposed starting default: research/test only.
2. Actual seed to fund and expense envelopes/reserve amounts; zero live authorization until funded and approved.
3. Actual hosting capabilities; obtain a redacted capability report, never secrets in chat/Git.
4. First product/supplier selection after research; no product is assumed selected.
5. Daily report/contact window, emergency categories and contact route.
6. Customer support owner/escalation, refund operating authority and response targets.
7. Backup retention/recovery targets, transcript retention and recovery custody.
8. Final vendor/framework and recurring costs after feasibility; no new subscription assumed.
These are explicit configuration/acceptance decisions, not missing hidden assumptions. Technical feasibility is resolved through tests, not guessed answers from Dad.

## Z. Honest readiness declaration
A to Z scope, proposed interfaces, controls, flows, validation and delivery sequence are documented. This is an implementation blueprint with explicit unresolved configuration and verification gates, not a claim that all technical/commercial facts are known. Design review, account evidence, passing CI, staging and owner live approval remain required. No deployment, payment, secret access or new spending is authorized by this document.
