# Kamau Putt — Evidence & Test Log

## 2026-10-24 — Finance prototype continuation (feature branch, NOT staging verified)
- On local PHP 8.4.24, `php -l src/Core/FinancialMath.php` and `php -l src/Core/PdoSpendReservations.php` both returned no syntax errors.
- Local `php tests/financial_math.php`: **10/10 passed** (settled-cash and reserves arithmetic, budget, AUD 500 startup seed constraint).
- Added an opt-in MySQL test harness and a GitHub CI workflow to run original policy and new arithmetic tests. **GitHub Actions run #7 and subsequent run #17 for the feature branch reported FAILURE**; job steps/log could not be retrieved through the connector. Actual failure cause unverified. MySQL DB integration results not observed as passed.
- Local environment has PDO but no `pdo_mysql`/`pdo_sqlite`; cannot claim a MySQL database or concurrency test.
- Existing DB migration and reservation code are PROTOTYPES: no audited ledger, no authenticated owner stop/resume, no provider payment, no confirmed staging deployment, no real cash handling.
- See `docs/FINANCIAL_RESERVATIONS.md` for exact state machine and safety limitations.

## 2026-10-24 — Local PHP Core proof (not deployed)
- Environment: PHP CLI **8.4.24**, no Composer or external services needed.
- Commands: `php -l src/Core/SpendDecision.php`; `php -l src/Core/SpendGuard.php`; `php -l tests/run.php`; `php tests/run.php`.
- Observed result in local working container: **all three syntax checks pass, 11/11 test cases pass**.
- Coverage: approved action, zero/negative amount, owner stop, unauthorized scope, survival mode, per-action cap, approved budget cap, insufficient free settled funds, invalid financial snapshot and exact-boundary case.
- **Scope:** Pure local preflight decision function only. Not an implemented transaction ledger, concurrent spending control, database permission, durable emergency stop, Stripe sandbox test, hosting verification, autonomous agent, or production deployment.
- Next independent verification: DB-backed transactional budget reservation under simultaneous requests, process crash and duplicate webhooks.

## Completion policy
Each significant checkbox needs one of: linked automated CI result, reproducible local test evidence, verifiable staging report, or real production observation. Mark the level explicitly. Never infer production readiness solely from a passing unit test.

## 2026-10-25 — Disposable database validation implementation
- Added isolated MySQL 8.4 service CI workflow with explicit PDO driver install and empty disposable database; no production credentials or external payment APIs.
- Expanded integration harness with uncertain outcome retention, denied-release rollback, reconciled commit, conflicting provider reference, terminal transition and no-double-debit assertions (22 sequential database cases total).
- Status: Implemented/Untested until the new CI run is observed passing. Local PHP/MySQL runtime unavailable in this session; no test pass claimed. Prior failed CI logs returned unavailable; root cause unknown.
- Next: inspect new workflow result, fix concrete failures, then implement independent-process reservation and stop races. Authenticated stop/resume, full accounting and live deployment remain pending.

## 2026-10-26 — Actual local PHP verification and CI diagnosis
- Repository owner login changed to parmtech; repository id 1431801251 remains the same.
- GitHub check-run 121109803308 at head f3f0b3b9073cf791794598ed307260e94b6d89be explicitly reports: job not started because recent account payments failed or spending limit needs increasing. This is an account execution blocker, not an observed failing PHP assertion. Check annotations obtained through Code Review diagnostics; job logs absent because execution did not start.
- Downloaded official portable PHP 8.5.12 NTS x64; SHA256 verified against php.net: 2ab242af38d76bdf417cde5ec048b1bcd76a5a40f39a3d15d5f046f38571c477. Workspace-only runtime; no system PHP replacement.
- Materialized repository feature-branch source snapshot through connected GitHub read tools; interrupted clone had no commit and is not the working source. Local working snapshot: source/ in task workspace.
- Actual commands: php tests/run.php (11/11 PASS), php tests/financial_math.php (10/10 PASS), php -l on all seven source/test PHP files (all PASS). Runtime PHP 8.5.12 Windows x64. PDO MySQL extension loads successfully.
- Database integration/concurrency has NOT run yet. Next: workspace-only disposable MariaDB and independent-process race tests. No live spending/deployment.
