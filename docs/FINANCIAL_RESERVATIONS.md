# KP-012 / KP-020 — Financial reservation foundation

**Status: prototype locally tested on MariaDB 11.4.13 and MySQL 8.4 CI. No live spending permitted.**

The dispatch-gate prototype is implemented and validated by MySQL 8.4 CI; provider calls in tests are simulated, and the gate is not approved for live use.

## Purpose
The existing `SpendGuard` provides advisory policy checks, not durable money control. This change adds:
- InnoDB schema with singleton owner-stop/mode control (default stopped/survival), settled-cash/liability reserves, scope budgets, reservation records and append-only reservation events.
- `FinancialMath`: auditable integer-cent limits and AUD 500 cumulative external seed check. This is **not** a production funding intake API.
- `PdoSpendReservations`: exclusive control-row lock, scope row lock, atomic reserve, commit, release, mark-uncertain; transaction rollback on error.
- Per-action cap is also stored on the approved budget envelope (default 0 / deny); callers cannot raise it simply by supplying a larger parameter. Unauthorised scopes are rejected before reservation lookup.
- Repeated idempotency key **does not grant a second external paid call**. A changed request with the same key fails as a conflict.
- A successful reservation commit now posts a balanced double-entry journal in the same DB transaction: debit `operating_expense`, credit `cash_settled`. The journal has stable source/idempotency keys, a SHA-256 chain, and append-only triggers. This records the prototype's spend event; it is not bank reconciliation or proof of an external charge.
- Reservations capture the current dispatch epoch. The dispatch gate locks the shared finance-control row, checks STOP/mode/epoch and reservation state, then runs its bounded provider callback while holding that lock. STOP waits for an already-started callback, increments the epoch, and makes older reservations stale. A stable provider idempotency key is supplied to the callback.

## State transitions
`reserved -> committed`: only after a VERIFIED provider charge; records reservation counters and a balanced ledger posting, but does NOT create the charge.
`reserved -> released`: only after verified provider absence/no payment; returns funds to available position.
`reserved -> uncertain -> committed`: provider response missing, subsequent proof of charge.
`uncertain -> released`: deliberately **blocked** until a future independently authenticated reconciliation procedure is implemented.
`committed` and `released` are terminal; duplicate transitions are idempotent only when details match.

## Important financial limitations
- Opening cash in tests is synthetic. Real cash, liabilities, tax, refunds and supplier balances have not been reconciled. The new journal is append-only application accounting, not a complete audited accounting system.
- The `commit` method decreases `settled_cash_cents`; this simplifies the prototype but does not represent all card settlement and supplier payment timing. No live provider calls are wired.
- Caller boolean `scopeAuthorized` is NOT authentication. A real caller must derive its permission server-side from validated owner/service identity; never expose raw methods to an LLM or public HTTP.
- A separate owner-auth core prototype now exists (`PdoOwnerAuth`), with 14 MySQL 8.4 CI cases passing. It has not been independently security-reviewed or exposed through an HTTP layer; financial authorization still must be bound to authenticated server-side identity before any live action.
- A same-origin HTTPS owner-auth controller is implemented with Secure/HttpOnly session cookies and Origin/CSRF checks; MySQL 8.4 passed 19 auth/controller assertions on run `40246429206`. Owner STOP/resume is now bound through a fresh-MFA one-use challenge prototype, pending its own CI suite; none of these routes are deployed or independently reviewed.
- Reservation and ledger schemas constrain monetary values to the PHP signed 64-bit range. Local overflow and transaction rollback checks pass; migration upgrades, timeout behavior, multi-store accounting and backup/restore remain unverified.
- Control-state activation and funding are deliberately not implemented. The database defaults STOPPED and with zero settled money.
- The STOP primitive has no owner authentication yet. The dispatch callback runs inside a database transaction and can hold the global finance lock during network latency; it is a prototype gate only, not suitable for live provider wiring before a durable outbox/reconciliation design and bounded adapter exist.
- Reconciliation process must protect a provider charge that occurred after a timeout; never retry it blindly.
- One owner-stop write transaction must lock the same `kp_finance_control` row to serialize against reservations.
- `kp_financial_events` tracks reservation lifecycle. `kp_ledger_transactions` and `kp_ledger_entries` hold balanced postings with a hash chain; independent tamper evidence, bank reconciliation, refunds, liabilities and tax journals remain outstanding.

## Staging-only setup
Use MySQL 8+ or MariaDB 10.6+ with an InnoDB dedicated TEST database and `pdo_mysql`; backup before applying `database/001_financial_control.mysql.sql`. Restrict migrations to CLI/admin, not a web route.
Run:
```sh
php -l src/Core/FinancialMath.php
php -l src/Core/PdoSpendReservations.php
php tests/run.php
php tests/financial_math.php
```
Integration tests require a dedicated disposable database. The reservation/ledger suites passed on MariaDB 11.4.13; all MySQL 8.4 suites passed, including 40 independent-process concurrency assertions on run 40245586343. These tests cover sequential reservations, accounting invariants, STOP/dispatch serialization, epoch fencing, idempotency and DB rollback. Provider reconciliation, charge/refund, backup/restore and authenticated access still require separate work. No credentials may be committed to Git.

## Go-live blocker
No payment, ads, supplier purchases, owner funds intake, owner STOP resume, AI autonomous finance or production deployment until audited authorization, atomic ledger, independent QA, staged reconciliations and legal/supplier checks pass.

## 2026-10-26 validation update
`tests/mysql_integration.php` passes 25 sequential DB assertions, `tests/mysql_accounting.php` passes 29 ledger assertions, and `tests/mysql_concurrency.php` passes 33 local MariaDB assertions. The latest MySQL 8.4 CI run 40245586343 passes all three jobs, with 40 concurrency assertions including STOP waiting for an in-flight callback and blocking later callbacks. CI uses its disposable root account only to enable trigger creation under binary logging; test suites run as the restricted test user. No external provider, authenticated stop/resume, reconciled real cash or production-readiness claim follows from these tests. STOP is not exposed through a privileged public API.

The dispatch gate adds STOP serialization, epoch fencing and stale-reservation denial; MySQL 8.4 CI verifies STOP waits for an already-started callback and no callback begins after STOP returns. The callback is simulated and intentionally runs under the control-row lock; this can hold that global lock for its full duration.

