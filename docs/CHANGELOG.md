# Kamau Putt — Development & Decision Changelog

All dates Australia/Sydney. Append a new dated entry for each substantive session. Keep code history in Git commits. **Do not delete or silently rewrite previously recorded decisions.**

## 2026-10-26 — Double-entry ledger prototype
- Added AUD chart of accounts, append-only balanced journal entries, source/idempotency uniqueness, hash chaining and DB-level update/delete guards.
- Reservation commit now posts expense and settled-cash entries in the same DB transaction; rollback covers both records.
- Added 29 accounting checks and expanded reservation integration to 25 checks; CI now runs reservation, accounting and concurrency suites in separate MySQL 8.4 jobs.
- Local MariaDB 11.4.13 passed all three suites (25 + 29 + 33). First MySQL 8.4 CI run exposed its binary-logging trigger-creator restriction before accounting assertions; updated the disposable CI service to trust its test trigger creators. Follow-up MySQL 8.4 run 40245143663 and PHP run 40245143656 passed. This does not verify real cash, refunds, liabilities, owner authentication, provider dispatch, hosting or production readiness.

## 2026-10-26 — STOP/dispatch epoch gate prototype
- Added dispatch epochs to the finance control row and reservation authorization snapshot. The gate serializes a bounded callback with STOP; STOP increments the epoch and stale approvals cannot dispatch.
- Added independent-process coverage for STOP waiting on an in-flight callback and blocking future calls. PHP syntax and pure checks pass; MySQL 8.4 race results are pending.
- First dispatch-gate CI attempt found that the reservation control-row query omitted `dispatch_epoch`; fixed the query. Rerun is required.
- Corrected MySQL 8.4 run 40245586343 passed reservation, accounting and concurrency suites; the concurrency suite reports 40 passing cases. PHP run 40245586377 is green. Callback tests are simulations only; owner authentication and live provider integration remain incomplete.

## 2026-10-26 — Owner authentication core prototype
- Added CLI-only bootstrap for one owner, password verification, encrypted TOTP MFA, anti-replay, HMAC-throttled failures, opaque server-side sessions and CSRF token validation with idle/absolute expiry.
- Added same-origin HTTPS JSON login/session/logout routes, strict Origin validation, Secure/HttpOnly `__Host-` cookies and Apache front-controller rewrite. MySQL 8.4 run `40246429206` passed all four database suites; owner-auth/controller assertions are 19/19. PHP run `40246429054` passed.
- At this milestone, password/MFA recovery, roles, authenticated STOP/resume binding, UI, deployment and independent security review remained incomplete; later STOP/resume progress is recorded below.
- Prior MySQL 8.4 run 40246011247 passed the 14 auth-core cases; PHP CI run 40246011106 passed. Fresh CI must verify the controller additions.
- No authentication, resume, live provider adapter, durable external outbox or deployment is included.

## 2026-10-26 — Authenticated STOP and reviewed resume prototype
- Added financial-state summary, owner-authenticated STOP, fresh password/TOTP resume challenge and exact-phrase one-use confirmation bound to session, dispatch epoch and unchanged summary. Safe Shutdown and changed state fail closed; resume preserves Survival mode.
- Added migration `005_owner_control.mysql.sql` and a disposable owner-control MySQL suite in the CI matrix. MySQL 8.4 run `40246815770` passed all five DB suites; owner-control assertions 12/12 and owner-auth/controller 19/19. PHP run `40246815796` passed. No queue, cancellation adapter, UI, deployment or security review exists.

## 2026-10-26 — Owner-control audit journal
- Added migration `006_owner_control_audit.mysql.sql`, append-only STOP/challenge/resume events with a SHA-256 chain, and database triggers rejecting UPDATE/DELETE. STOP and resume state transitions plus audit entries share a transaction.
- MySQL 8.4 run `40247011094` passed all five suites; owner-control reports 15/15. PHP run `40247011117` passed. The suite verifies chain integrity and SQL update/delete rejection. This journal covers owner-control events only, not the full business decision/event journal.

## 2026-10-24 — Session: financial reservation prototype and hosting preflight
- Kept work in existing private repository on `feat/kp-financial-reservations-foundation`; no replacement project.
- Reviewed current Namecheap shared-host policy; created `docs/HOSTING_PREFLIGHT.md`, noting 5-minute minimum cron interval and prohibition on long-lived daemons; account checks still pending.
- Added MySQL/MariaDB InnoDB migration schema, integer-cents financial calculations, PDO reservation/uncertain/commit/release prototype, lifecycle events, default stopped/survival mode and idempotency-safe replays.
- Added optional **staging-only** MySQL test harness and PHP CI workflow. In local PHP 8.4.24, new finance arithmetic passed **10/10**, syntax checked. Actual DB concurrency and GitHub CI result remain unverified.
- Updated tracker, verification, and handoff. No live financial permission or deployment has been enabled.

## 2026-10-24 — Session: formal build tracker and start of backend Core
- Confirmed private repo `manbir6-ux/kamau-putt` and existing planning/specification files.
- New owner build priority: **first build/test stable backend and controls, Android app last**. Captured in `docs/DECISIONS.md` and replaced earlier phase ordering in `docs/ROADMAP.md`.
- Established step-by-step acceptance-driven `docs/PROJECT_TRACKER.md`, AI continuation contract in root `AGENTS.md`, `docs/SESSION_HANDOFF.md`, `docs/VERIFICATION.md` and initial architecture outline.
- Started *only* a pure, dependency-free PHP Core spending-preflight guard; it makes no external purchases and has no database, secrets or production powers.
- PHP **8.4.24**: syntax checks clean and **11/11** policy test scenarios passed locally. **NOT** proof of production no-overspend enforcement.
- Next: environment verification and durable transactional ledger + authorization tests.

## 2026-10-24 — Earlier conversational design work
- Captured identity, autonomy, financial safety, Australian dropshipping plan, provider-agnostic API vault, memory, collaboration, call scheduling, owner emergency stop and future physical-hardware aspirations in Master Plan/Features/Decisions.
- These were **requirements only**, not implemented commercial features.

## 2026-10-25 — Disposable database validation implementation
- Added isolated MySQL 8.4 service CI workflow with explicit PDO driver install and empty disposable database; no production credentials or external payment APIs.
- Expanded integration harness with uncertain outcome retention, denied-release rollback, reconciled commit, conflicting provider reference, terminal transition and no-double-debit assertions (22 sequential database cases total).
- Status: Implemented/Untested until the new CI run is observed passing. Local PHP/MySQL runtime unavailable in this session; no test pass claimed. Prior failed CI logs returned unavailable; root cause unknown.
- Next: inspect new workflow result, fix concrete failures, then implement independent-process reservation and stop races. Authenticated stop/resume, full accounting and live deployment remain pending.

## 2026-10-26 — Concurrency and crash validation
- Added independent-process finance tests and a separate disposable database CI matrix suite.
- Local MariaDB sequential 22/22 and concurrency 33/33 pass; prior PHP/DB GitHub reruns green after owner made repo public. Details and scope in VERIFICATION.md.
- Updated tracker/handoff/README/AGENTS and finance documentation to distinguish tested reservation behavior from pending accounting/auth/provider/deploy gates. No production powers enabled.

# 2026-10-26 — Encrypted provider credential vault foundation
- Added migration 007 and an owner-authenticated credential lifecycle: encrypted inactive storage, masked metadata, internal-only smoke-test evidence, fresh MFA plus exact confirmation for activation, revocation, and append-only hash-chained lifecycle events. MySQL 8.4 CI run `40247547998` passed all six suites; vault lifecycle assertions passed 16/16. There is no live provider adapter or HTTP test endpoint.
- Vault encryption uses a separate `KP_VAULT_ENCRYPTION_KEY`. Losing this key prevents decryption. Provider credentials must not be used for real calls until STOP fencing, spend reservations, privacy/security review and provider adapters are complete.

# 2026-10-26 — Durable reservation-backed job outbox foundation
- Added migration 008, one-use worker leases, a stable provider idempotency key, stop/epoch fencing, conservative lease-expiry handling, append-only hash-chained job events, and atomic job + reservation + ledger outcomes. MySQL 8.4 run `40309790257` passed all seven suites, including 20 durable-job assertions; PHP run `40309790387` passed. Independent code review found a missing reservation-state recheck and missing release on queued STOP cancellation; both were fixed and covered by this green run. No scheduler, supervised worker, UI or live provider integration exists.

# 2026-10-26 — Provider route policy foundation
- Added a local deterministic router that rejects stale or non-AUD quotes, inactive keys, unsupported task/language/data class, low quality and over-cap estimates. The policy only selects an eligible offer; dispatch still requires an authorized reservation and outbox lease. PHP run `40310403543` passed all 13 route cases; MySQL workflow run `40310403535` passed. No live provider price, quality or Punjabi speech claims are embedded.

# 2026-10-26 — Owner HQ browser shell
- Added local static owner UI for login, financial/control snapshot, STOP, fresh-MFA resume review and masked provider-key inventory. Same-origin calls use secure cookies/CSRF; UI stores no secrets or resume tokens. Browser/staging/hosting validation remains pending.

# 2026-10-26 — Owner HQ review fixes
- Addressed independent read-only review: removed ineffective framing/cache meta directives and documented reliance on verified HTTP headers; logout/session expiry now clears resume challenge and review DOM; increased muted text contrast. Rechecked JS syntax and whitespace locally.
- Added the Owner HQ JavaScript syntax check to the PHP foundation GitHub workflow.
- PHP run `40311875586` and MySQL 8.4 run `40311875558` passed on commit `ca6d8ab`, including all seven DB suites and the workflow JavaScript syntax check.

# 2026-10-27 — Durable dispatch attempt and STOP boundary
- Changed provider initiation so the worker records one unique `dispatch_attempt_id` and commits `dispatching` before calling the adapter. Provider I/O no longer holds the global finance-control transaction lock; settlement happens in a second short transaction against that exact attempt.
- STOP cancels queued and leased-but-unstarted jobs and releases their reservations. It marks an authorized in-flight attempt `cancel_requested`, returns without waiting for provider I/O, and keeps funds reserved until a known result or uncertainty is recorded. A known late result for the exact attempt settles once; ambiguous outcomes and expired in-flight attempts stay uncertain and are never resent.
- Added migration 009, recovery/status handling, and audit events. Local PHP 8.5.12 + MariaDB 11.4.13 passed 27/27 durable-job and 15/15 owner-control assertions; pure suites passed 11/11 policy, 10/10 financial math, and 13/13 provider routing. PHP lint and `git diff --check` passed.
- Independent read-only agent review found no critical correctness issue in the refactored path. It flagged two documented operational limits: `dispatch_started` is the irrevocable authorization boundary (STOP cannot guarantee suppression if the process is paused immediately before callback), and the 30-second lease begins at claim time, so a slow adapter may be marked uncertain before returning. A real adapter must enforce a strict deadline; MySQL 8.4 CI for this change is pending. No real provider call or deployment is enabled.
