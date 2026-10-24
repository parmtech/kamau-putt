# Kamau Putt — Session Resume / AI Handoff

**Last updated:** 2026-10-24, Australia/Sydney  
**Repo:** `manbir6-ux/kamau-putt` (PRIVATE). Default branch `main`.  
**User instruction:** Maintain complete development evidence in GitHub so *any later session* can continue without restarting.

## 2026-10-24 incremental finance foundation (feature branch; not merged)
- Branch: `feat/kp-financial-reservations-foundation`. Draft PR proposed for review. GitHub workflow run #7 reported FAILURE; job logs/steps were unavailable through connector. Investigate GitHub Actions UI and re-run before merge.
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
1. **KP-003:** Verify actual cPanel runtime/extensions, database engine, SSL, cron, SSH, memory and backups against provider policy; do not upload secrets.
2. **KP-012/KP-020:** Review branch prototype; run disposable DB integration, 2-process concurrent reservation and owner-stop race checks; verify no double charge and rollback on crashes. Build reconcilable double-entry accounting before staging approval.
3. **KP-013/KP-023/024:** Implement durable auth/owner-stop controls with independent staging verification before enabling any live spending.

## Known limitations / open blockers
- No verified live hosting environment, database, cron behavior, Stripe integration, Android background-calling stack or vetted live suppliers.
- PHP foundation is **provisional**, chosen for lightweight shared-hosting compatibility; changing framework requires a documented decision.
- No trusted autonomous spending is safe until transactional reservations, journal, owner stop and E2E tests exist.

## How to finish any future session
- Read `AGENTS.md`, tracker, this handoff, decisions and relevant code.
- Verify current main branch HEAD; make focused changes + tests, capture outcomes.
- Update tracker, changelog, handoff and verification **in same commit as code whenever possible**.
- Clearly separate tested code from simulations, deploys and live operations.
