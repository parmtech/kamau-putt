# Durable job outbox foundation

Migration `008_durable_jobs.mysql.sql` adds a persistent outbox tied one-to-one to a reserved spend. It stores action type and an opaque payload reference; payload bodies and credentials stay outside the queue. Duplicate job keys and duplicate reservation links are rejected.

## State and safety rules

- `queued` jobs are eligible only while the owner stop is clear, operating mode is Growth, and the reservation authorization epoch still matches.
- A worker claim creates one 30-second lease and a stable provider idempotency key. Each job has at most one claim; expired leases become `uncertain`, hold their reservation and are never automatically retried.
- Dispatch rechecks the lease, reservation state, operating mode and dispatch epoch while locking the same financial-control row used by STOP. The adapter callback is intentionally inside this bounded critical section, so STOP waits for an already-started call and fences a later one.
- A provider `accepted` result commits the reservation and posts its ledger entry in the same transaction as job completion. `no_effect` releases the reservation and fails the job. `unknown` marks both reservation and job uncertain; funds remain reserved.
- If the process dies after a provider may have accepted a request but before the DB commit, the lease expires into `uncertain`; stable idempotency allows an operator to reconcile without an automatic duplicate call.
- STOP advances the epoch, cancels queued work, and marks leased work `cancel_requested`. A canceled or stale lease cannot dispatch after STOP. Resume never requeues canceled work; a new reservation and job intent are required.
- Job lifecycle events use a global SHA-256 chain and SQL triggers reject event updates/deletes. State rows are mutable projections; their audit event stream is the evidence source.

## Boundaries

The queue currently has no scheduler process, worker service, provider adapter, payload store, HTTP job API, owner dashboard or operational reconciliation tool. The callback must be narrowly scoped and bounded, and an actual adapter must validate the provider response before returning one of the three outcomes. No real job dispatch is enabled by this foundation. Before staging, add multi-process race/crash tests, independent review, safe payload handling, worker supervision, metrics and tested recovery procedures.
