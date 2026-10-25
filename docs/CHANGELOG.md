# Kamau Putt — Development & Decision Changelog

All dates Australia/Sydney. Append a new dated entry for each substantive session. Keep code history in Git commits. **Do not delete or silently rewrite previously recorded decisions.**

## 2026-10-26 — Double-entry ledger prototype
- Added AUD chart of accounts, append-only balanced journal entries, source/idempotency uniqueness, hash chaining and DB-level update/delete guards.
- Reservation commit now posts expense and settled-cash entries in the same DB transaction; rollback covers both records.
- Added 29 accounting checks and expanded reservation integration to 25 checks; CI now runs reservation, accounting and concurrency suites in separate MySQL 8.4 jobs.
- Local MariaDB 11.4.13 passed all three suites (25 + 29 + 33). Latest GitHub MySQL CI is pending. This does not verify real cash, refunds, liabilities, owner authentication, provider dispatch, hosting or production readiness.
- First MySQL 8.4 CI run exposed its binary-logging trigger-creator restriction before accounting assertions; updated the disposable CI service to trust its test trigger creators. Fresh CI run required.

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

