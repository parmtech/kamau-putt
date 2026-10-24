# KP-012 / KP-020 — Financial reservation foundation

**Status: implemented as prototype; PDO MySQL integration and concurrent safety NOT tested. No live spending permitted.**

## Purpose
The existing `SpendGuard` provides advisory policy checks, not durable money control. This change adds:
- InnoDB schema with singleton owner-stop/mode control (default stopped/survival), settled-cash/liability reserves, scope budgets, reservation records and append-only reservation events.
- `FinancialMath`: auditable integer-cent limits and AUD 500 cumulative external seed check. This is **not** a production funding intake API.
- `PdoSpendReservations`: exclusive control-row lock, scope row lock, atomic reserve, commit, release, mark-uncertain; transaction rollback on error.
- Per-action cap is also stored on the approved budget envelope (default 0 / deny); callers cannot raise it simply by supplying a larger parameter. Unauthorised scopes are rejected before reservation lookup.
- Repeated idempotency key **does not grant a second external paid call**. A changed request with the same key fails as a conflict.

## State transitions
`reserved -> committed`: only after a VERIFIED provider charge; records ledger counters, but does NOT create the charge.
`reserved -> released`: only after verified provider absence/no payment; returns funds to available position.
`reserved -> uncertain -> committed`: provider response missing, subsequent proof of charge.
`uncertain -> released`: deliberately **blocked** until a future independently authenticated reconciliation procedure is implemented.
`committed` and `released` are terminal; duplicate transitions are idempotent only when details match.

## Important financial limitations
- The financial position is a *reservation snapshot*, **not** a double-entry ledger. Existing cash, liabilities, tax and refund reserves must be independently reconciled before any real money use.
- The `commit` method decreases `settled_cash_cents`; this simplifies the prototype but does not represent all card settlement and supplier payment timing. No live provider calls are wired.
- Caller boolean `scopeAuthorized` is NOT authentication. A real caller must derive its permission server-side from validated owner/service identity; never expose raw methods to an LLM or public HTTP.
- The schema uses unsigned BIGINT and checks, but rigorous overflow, race, crash, transaction timeout, multi-store and restoration testing remain required.
- Control-state activation and funding are deliberately not implemented. The database defaults STOPPED and with zero settled money.
- Reconciliation process must protect a provider charge that occurred after a timeout; never retry it blindly.
- One owner-stop write transaction must lock the same `kp_finance_control` row to serialize against reservations.
- `kp_financial_events` tracks reservation lifecycle only, not all accounting entries; tamper-evident ledger, bank reconciliation and journal are outstanding.

## Staging-only setup
Use MySQL 8+ or MariaDB 10.6+ with an InnoDB dedicated TEST database and `pdo_mysql`; backup before applying `database/001_financial_control.mysql.sql`. Restrict migrations to CLI/admin, not a web route.
Run:
```sh
php -l src/Core/FinancialMath.php
php -l src/Core/PdoSpendReservations.php
php tests/run.php
php tests/financial_math.php
```
Integration tests require a dedicated disposable database and are still pending. First verify simultaneous reservations, overlapping scopes, duplicate keys, provider timeout, owner STOP race, charge/refund, backup/restore, and unauthorized access. No credentials may be committed to Git.

## Go-live blocker
No payment, ads, supplier purchases, owner funds intake, owner STOP resume, AI autonomous finance or production deployment until audited authorization, atomic ledger, independent QA, staged reconciliations and legal/supplier checks pass.
