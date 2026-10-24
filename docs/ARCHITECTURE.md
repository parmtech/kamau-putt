# Kamau Putt — Proposed Core Architecture (non-production)

**Ordering:** backend-first, Android-last. Architecture to be revised only with a dated decision.

## Components and separation
```text
Dad web HQ (initially)  -->  authenticated owner API
                                  |
                           permissions + consent
                                  |
   +-------------------- Kamau Putt Core --------------------+
   | Journal/Event log | State/Memory | Policy/Spend Gate   |
   | AI provider vault | Job scheduler| Audit/Monitoring   |
   +--------------+-------------------+---------------------+
                  |                   |
       research/store/creative   atomic financial ledger
             agents                  | 
                  +---- Stripe test/real & suppliers (LATER)

Android Dad app / incoming calls --> same secure backend API (LAST)
```

## Implementation priorities
1. No internet-facing sensitive interface until owner auth + authorization, CSRF/session/2FA and rate limiting are implemented.
2. Every spending authorization uses integer AUD cents; no floating point for money. One-time **external seed cap** is distinct from operating/reinvestment budgets.
3. **Pure SpendGuard is only advisory preflight.** Production permission must be determined inside one durable database transaction with row locks and atomic budget reservation, followed by an idempotent external call and reconciliation; distinguish charged, reserved, settled, liabilities and earned profits.
4. Emergency stop must be centrally durable and prevail over model decisions, queues and retries. Owner-only resume must be authenticated and require state/risk summary.
5. Long-lived jobs need persistence and retry safety; Namecheap shared hosting cron limitations must be verified before runtime choice.
6. One authoritative Brain state; encrypt/version backups outside active host; never back up plaintext keys to public folders.
7. Provider router stores keys server-side, supports owner-entered multiple provider credentials and privacy-aware failover without terms circumvention.
8. Web HQ first to operate/test the system; Android interface comes after core and pilot stability.

## Initial technology
A minimal PHP 8.2+ namespace-based library and dependency-free test runner is used only to establish Core boundaries and policy tests. It is **not** a final hosting/framework decision or a running AI agent. Hosting, database, queues, security, resilience and operational constraints must be verified before selection.

## Trust boundaries / must-not-do
- No direct database write, money transfer or external paid action by an LLM without non-LLM authorization.
- No third-party API secrets inside Android APK, Git history, logs or model prompts.
- No direct access from customer brand agents to Dad's private memory/financial authority.
- No self-change to verified money/permission controls without isolated tests, owner-approved releases and rollback.
- If hosting loses connectivity, new purchases default to DENY and paid customer obligations stay recorded.
