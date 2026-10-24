# Kamau Putt — Evidence & Test Log

## 2026-10-24 — Local PHP Core proof (not deployed)
- Environment: PHP CLI **8.4.24**, no Composer or external services needed.
- Commands: `php -l src/Core/SpendDecision.php`; `php -l src/Core/SpendGuard.php`; `php -l tests/run.php`; `php tests/run.php`.
- Observed result in local working container: **all three syntax checks pass, 11/11 test cases pass**.
- Coverage: approved action, zero/negative amount, owner stop, unauthorized scope, survival mode, per-action cap, approved budget cap, insufficient free settled funds, invalid financial snapshot and exact-boundary case.
- **Scope:** Pure local preflight decision function only. Not an implemented transaction ledger, concurrent spending control, database permission, durable emergency stop, Stripe sandbox test, hosting verification, autonomous agent, or production deployment.
- Next independent verification: DB-backed transactional budget reservation under simultaneous requests, process crash and duplicate webhooks.

## Completion policy
Each significant checkbox needs one of: linked automated CI result, reproducible local test evidence, verifiable staging report, or real production observation. Mark the level explicitly. Never infer production readiness solely from a passing unit test.
