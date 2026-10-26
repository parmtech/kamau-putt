# Durable job outbox foundation

Migration `008_durable_jobs.mysql.sql` adds a persistent outbox tied one-to-one to a reserved spend. Migration `009_durable_job_dispatch_attempt.mysql.sql` adds durable dispatch-attempt state. The queue stores action type and an opaque payload reference; payload bodies and credentials stay outside it. Duplicate job keys, reservations and dispatch-attempt IDs are rejected.

## State and safety rules

- `queued` jobs are eligible only while the owner stop is clear, operating mode is Growth, and the reservation authorization epoch still matches.
- A worker claim creates one 30-second lease and a stable provider idempotency key. An unstarted expired lease is canceled and its reservation released. Each job has at most one provider attempt; expired in-flight attempts become `uncertain`, keep their reservation and are never automatically retried.
- Dispatch rechecks and locks the lease and reserved spend, validates operating mode and dispatch epoch, records a unique `dispatching` attempt, and commits. Provider I/O then runs outside the DB transaction. The result is settled in a second short transaction against that exact attempt ID; known accepted results still settle correctly if STOP happened while I/O was in flight.
- A provider `accepted` result commits the reservation and posts its ledger entry in the same transaction as job completion. `no_effect` releases the reservation and fails the job. `unknown` marks both reservation and job uncertain; funds remain reserved.
- If the process dies after a provider may have accepted a request but before DB settlement, the attempt expires into `uncertain`; its durable attempt ID and stable idempotency key support later reconciliation without an automatic duplicate call. A late known result for that exact attempt can still settle once.
- STOP advances the epoch, cancels queued and leased-but-unstarted work and releases its still-reserved funds, and marks `dispatching` work `cancel_requested` while retaining its reservation. STOP fences later attempts and returns without waiting for provider I/O. An already-authorized attempt may still complete externally; the UI reports it as open work until its result is stored or it becomes uncertain. STOP cannot recall a request already sent to a provider. Resume never requeues canceled work; a new reservation and job intent are required.
- Job lifecycle events use a global SHA-256 chain and SQL triggers reject event updates/deletes. State rows are mutable projections; their audit event stream is the evidence source.

## Boundaries

The queue currently has no scheduler process, supervised worker, provider adapter, payload store, HTTP job API or operational reconciliation tool. The callback must be a provider adapter with an enforced connect/read deadline and strict response validation; the core cannot forcibly time out arbitrary PHP callbacks. No real job dispatch is enabled. A DB epoch cannot undo a provider action already sent. Before staging, add independent-process race/crash tests, external idempotency-contract checks, safe payload handling, worker supervision, metrics and tested recovery procedures.
