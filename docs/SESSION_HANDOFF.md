# Kamau Putt — Session Resume / AI Handoff

**Last updated:** 2026-10-26, Australia/Sydney  
**Repo:** `parmtech/kamau-putt` (PUBLIC; visibility changed by owner). Default branch `main`.  
**User instruction:** Maintain complete development evidence in GitHub so *any later session* can continue without restarting.

## 2026-10-24 incremental finance foundation (feature branch; not merged)
- Branch: `feat/kp-financial-reservations-foundation`. Draft PR proposed for review. GitHub workflow runs #7 and #17 reported FAILURE; job logs/steps were unavailable through connector. Investigate GitHub Actions UI and re-run before merge.
- Added `database/001_financial_control.mysql.sql`, `src/Core/FinancialMath.php`, `src/Core/PdoSpendReservations.php`, `tests/financial_math.php`, optional `tests/mysql_integration.php`, workflow `.github/workflows/php-foundation.yml`.
- Local PHP 8.4.24 syntax checks passed on FinancialMath and PdoSpendReservations. **10/10** new pure financial arithmetic tests passed locally. **Original 11/11** policy tests were verified in the prior session only. GitHub CI, actual MySQL transactions, concurrency and crash tests NOT YET VERIFIED.
- No external charge, bank, supplier, ads or customer operations implemented. All database defaults fail closed. Opt-in staging integration test is not a live money test.
- Provider-level Namecheap cron/daemon/PHP constraints researched: `docs/HOSTING_PREFLIGHT.md`; actual account unverified.

## Current verified state
1. Planning docs exist: `README.md`, `docs/MASTER_PLAN.md`, `docs/FEATURES.md`, `docs/DECISIONS.md`, `docs/ROADMAP.md`.
2. Ordered task board: `docs/PROJECT_TRACKER.md`; completion conventions are mandatory.
3. Minimal PHP-only code: `src/Core/SpendGuard.php`, `src/Core/SpendDecision.php`, `tests/run.php` and `composer.json`.
4. **11/11** pure spending-preflight unit scenarios passed locally in PHP **8.4.24**; syntax/lint passed. They do NOT prove overspend-safe real transactions, live payments or emergency controls.
5. No actual deployment, payment API, provider integrations, secrets, customer orders, Android build, or live autonomous business.

## Critical owner decisions
- **Backend/core first, Android app LAST.** Existing older documents may contain an earlier Android-first order; this later decision supersedes it.
- One-time seed **AUD 200–300 preferred, AUD 500 max external seed**, not a monthly allowance. Safe earned-profit reinvestment later.
- Owner emergency stop; **only Dad** can resume after reviewing short risks/cost report.
- Persistent brain, memory/journal/backups, daily voice availability, owner-configurable multiple AI API keys, policy-compliant model routing.
- No customer-facing identity assumption: public brands may have distinct names.

## Exact next actions
1. Verify the MySQL 8.4 trigger-creation configuration fix. The first ledger CI attempt failed before test assertions because binary logging requires `log_bin_trust_function_creators` for trigger creation; tests and pure PHP checks passed. The CI fix uses only the disposable test server and awaits a fresh run.
2. Add and test transactional stop/dispatch epoch semantics so an already approved action cannot dispatch after STOP; add race, timeout and restart cases.
3. Implement authenticated owner identity, MFA/session controls and reviewed one-use resume; preserve default STOPPED state.
4. Then build journaled audit/credentials and hosting preflight. Do not enable external payments or spending before independent staging review.

## Known limitations / open blockers
- No verified live hosting environment, database, cron behavior, Stripe integration, Android background-calling stack or vetted live suppliers.
- PHP foundation is **provisional**, chosen for lightweight shared-hosting compatibility; changing framework requires a documented decision.
- No trusted autonomous spending is safe until transactional reservations, journal, owner stop and E2E tests exist.

## How to finish any future session
- Read `AGENTS.md`, tracker, this handoff, decisions and relevant code.
- Verify current main branch HEAD; make focused changes + tests, capture outcomes.
- Update tracker, changelog, handoff and verification **in same commit as code whenever possible**.
- Clearly separate tested code from simulations, deploys and live operations.

## 2026-10-25 — Disposable database validation implementation
- Added isolated MySQL 8.4 service CI workflow with explicit PDO driver install and empty disposable database; no production credentials or external payment APIs.
- Expanded integration harness with uncertain outcome retention, denied-release rollback, reconciled commit, conflicting provider reference, terminal transition and no-double-debit assertions (22 sequential database cases total).
- Status: Implemented/Untested until the new CI run is observed passing. Local PHP/MySQL runtime unavailable in this session; no test pass claimed. Prior failed CI logs returned unavailable; root cause unknown.
- Next: inspect new workflow result, fix concrete failures, then implement independent-process reservation and stop races. Authenticated stop/resume, full accounting and live deployment remain pending.

## 2026-10-26 authoritative continuation
- Latest work adds `database/002_double_entry_ledger.mysql.sql`, `src/Core/PdoDoubleEntryLedger.php`, and atomic reservation-to-ledger posting; fresh local MariaDB suites passed: 25 reservation, 29 accounting, 33 concurrency assertions. Latest MySQL 8.4 ledger CI is not yet observed.
- Local PHP 8.5.12 syntax lint, 11/11 policy checks, and 10/10 financial arithmetic checks passed again. A further DB rerun attempt hit local MariaDB authentication configuration (not a test assertion); prior fresh-database run remains evidence. Restore the local disposable DB login if re-running.
- Canonical public repo: `parmtech/kamau-putt`; feature branch `feat/kp-financial-reservations-foundation`. Latest ledger work and documentation are uncommitted at handoff and must be committed/pushed, then verify CI.
- Runtime lives outside repo in task workspace .tools/: PHP 8.5.12, MariaDB 11.4.13. source/ is a connector-materialized snapshot, not a Git checkout. Never commit .tools/ or database contents.
- Local test server stopped after suite. For process tests use pdo_mysql configured in PHP ini (child workers use same configuration), proc_open, explicit test opt-in and a NEW EMPTY disposable database per suite/run. Never point it at a real account database.
- After ledger CI passes, implement durable stop/dispatch epoch semantics, then authenticated owner login/MFA/session/step-up and reviewed one-use resume; use existing KP items, not a replacement project. Actual hosting preflight remains an owner/account dependency.
- Existing A to Z blueprint is Draft PR #2; merge/reconcile its overlapping docs before final design acceptance. Owner intent is automatic routine operations inside scoped budgets after live gates, not suggestion-only operation. No live spending yet.

