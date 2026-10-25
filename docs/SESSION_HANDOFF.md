# Kamau Putt — Session Resume / AI Handoff

**Last updated:** 2026-10-24, Australia/Sydney  
**Repo:** `manbir6-ux/kamau-putt` (PRIVATE). Default branch `main`.  
**User instruction:** Maintain complete development evidence in GitHub so *any later session* can continue without restarting.

## 2026-10-25 documentation handoff
- Owner requested A to Z blueprint and an interactive discussion of gaps. Read BLUEPRINT_A_TO_Z.md; status is PLANNED design baseline, not implemented/verified production.
- Proposed design supplies data contracts, authorization and money/order flows, security, recovery, tests and staged delivery. Owner configuration questions are in section Y; ask one at a time.
- First question pending: research/recommendations only versus bounded routine operations after explicit owner live-launch approval. Existing budget/Android-last decisions remain unchanged.
- IMPORTANT: Draft PR #1 on feat/kp-financial-reservations-foundation already contains additional finance code and branch-specific progress docs. Latest inspected Actions runs 40064013299 and 40064010910 failed. Cause not established. Review/reuse existing work; reconcile overlapping documents when merging, do not restart.
- Next independent task: diagnose failing CI and obtain redacted hosting capability evidence. No live actions or costs enabled.

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
1. **KP-003:** Verify actual Namecheap hosting PHP/MySQL/cron/worker/deploy constraints before choosing background scheduler or framework. Ask for access only if it is actually required, and do not upload secrets.
2. **KP-005/KP-012:** Propose and implement a schema/migration + **atomic reserve/commit/release** budget protocol with transaction isolation, idempotency keys, customer liabilities, no credit against unsettled funds. Test concurrency/duplicate/timeout cases using an available DB/test harness.
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
