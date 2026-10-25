# Kamau Putt — Ordered Build Checklist & Live Status

**Updated:** 2026-10-24 (Australia/Sydney)  
**Owner decision:** Build/test backend and web controls first. **Android app last**.  
**Current phase:** 01 — minimal backend foundation, **in progress**.  
**Source:** [Master Plan](MASTER_PLAN.md) · [Decisions](DECISIONS.md) · [Next session handoff](SESSION_HANDOFF.md) · [Verification](VERIFICATION.md).

## 2026-10-25 blueprint update
- [x] **Design document only:** [A to Z blueprint](BLUEPRINT_A_TO_Z.md) covers modules, data contracts, authority, financial/order state flows, threat controls, recovery, validation and delivery gates.
- [ ] Resolve owner configuration in blueprint section Y; verify hosting, design and staging gates. No implementation checkbox is completed by documentation.
- Existing unmerged Draft PR #1 contains financial reservation work; latest inspected CI failed. Reuse/review that work and preserve its branch documentation on merge.

## Status conventions
- [ ] **Pending / blocked / in progress** — see notes, not complete.
- [x] **Complete with stated evidence** — a completed *document*, *local unit test*, *staging test*, and *production operation* are different levels. Do not conflate them.
- Completion gate: implementation + recorded test result + security/cost review + tracker/changelog/handoff update.
- No automatic switch from local tests to real spending or customer operations.

## 00 — Project control and research (first)
- [x] **KP-000** Private GitHub repository and original owner requirements captured. Evidence: existing README, FEATURES, MASTER_PLAN, DECISIONS.
- [x] **KP-001** Numbered end-to-end sequence, session handoff, changelog, verification and AI continuation instructions. Evidence: this tracker + `AGENTS.md`.
- [x] **KP-002** Record revised build order: backend first, Android last. Evidence: DECISIONS and ROADMAP.
- [ ] **KP-003** Verify Namecheap Stellar Plus capabilities: minimum PHP/MariaDB versions, HTTPS, cron cadence, worker limits, memory, SSH/Composer, backup/restore. **No deployment claim yet.**
- [ ] **KP-004** Threat model / privacy review and Australian legal/commercial checklist for ABN, consumer law, refunds, privacy, tax, marketing, supplier disclosures.
- [ ] **KP-005** Finalize Core deployment conventions, database choice/migrations, local/staging environments and test automation. Provisional: PHP 8.2+ modular Core on existing PHP hosting; not a final production-stack commitment.

## 01 — Secure backend Core (START HERE)
- [x] **KP-010** Minimal, framework-independent PHP Core skeleton and local test runner. Evidence: `src/Core`, `tests/run.php`, `composer.json`; local syntax check and 11 unit cases on PHP 8.4.24.
- [x] **KP-011** Pure spending **preflight** policy: action cap, approved budget, free settled cash, scope, owner stop and survival mode. Evidence: 11/11 local unit tests. **NOT** a transactional lock, ledger or production safeguard.
- [ ] **KP-012** Durable DB schema, migrations, repositories, transaction tests and backups.
- [ ] **KP-013** Authenticated owner identity, 2FA and session security; roles/permissions.
- [ ] **KP-014** Append-only event and decision audit journal; trusted timestamps and retention.
- [ ] **KP-015** Secure encrypted credentials vault and audit; revocation/rotation.
- [ ] **KP-016** Secure web HQ admin shell, no unauthenticated sensitive endpoints.
- [ ] **KP-017** CI lint/unit/static/security checks and verified staging deployment.

## 02 — Financial control and survival (must precede live autonomy)
- [ ] **KP-020** Implement **atomic** reserve/commit/release spending ledger with database locks, idempotency and concurrency failure tests.
- [ ] **KP-021** Budget policy: single AUD 200–300 planned capital injection, **AUD 500 cumulative external seed ceiling**; earned funds may be reinvested only under approved envelopes and reserves.
- [ ] **KP-022** Track settled cash vs Stripe pending, customer liabilities, taxes, fulfilment, chargebacks, existing commitments and net contribution.
- [ ] **KP-023** Owner Emergency Stop persists across restarts; cancels/pauses discretionary jobs and verifies third-party state.
- [ ] **KP-024** **Only Dad** can resume after brief risk/cost summary + authenticated confirmation; negative/replay/race tests.
- [ ] **KP-025** Growth / Survival / Safe Shutdown, preserving prior paid-order/refund/legal duties.
- [ ] **KP-026** Reconcile external payment/supplier ledger; test refunds, duplicate webhooks, provider timeouts, crash recovery.
- [ ] **KP-027** Independent staging security & no-overspend verification gate.

## 03 — Persistent Brain and learning
- [ ] **KP-030** Stable model-agnostic identity, memories, search, preferences, provenance and owner edits.
- [ ] **KP-031** Single authoritative agent state; append-only life journal and structured decision/outcome evidence.
- [ ] **KP-032** Nightly reflection, measurable proposed workflow updates, approval/tests/rollback.
- [ ] **KP-033** Versioned encrypted off-site backups, owner recovery, tested restore drills.
- [ ] **KP-034** Schedule/availability memory and owner collaboration logic independent of Android.

## 04 — AI providers and cost router
- [ ] **KP-040** Multi-provider, multi-key vault: Gemini, OpenAI, future services; masked key health and usage.
- [ ] **KP-041** Bounded privacy-aware model/key test suite with comparable quality, latency, price and Punjabi speech metrics.
- [ ] **KP-042** Owner approval before activating new keys; policy-compliant provider fallback and quota monitoring.
- [ ] **KP-043** Token/audio/image/video costs journaled and reconciled with hard spend reservations and fallbacks.

## 05 — Reliable autonomy infrastructure
- [ ] **KP-050** Durable scheduler/cron and retry-safe queue with leases, idempotency and crash recovery.
- [ ] **KP-051** Tool authorization scopes, action plans, evidence, audit, sandbox/testing and rollback.
- [ ] **KP-052** Owner web HQ chat, decisions, financial reporting, approvals and notifications.

## 06 — Australian market research engine
- [ ] **KP-060** Australia-aware trend/demand/intent/seasonality, compliant sourcing and citation trail.
- [ ] **KP-061** Competitor delivered-price, supplier/returns/recall checks; documented SKU score and hard veto.
- [ ] **KP-062** Risk-adjusted unit margins, source reliability and go/no-go research report; no paid experiments by default.

## 07 — Single-store ecommerce staging
- [ ] **KP-070** Fast single-SKU mobile landing page, brand separation and honest claims.
- [ ] **KP-071** Verified standard/fast shipping option, supplier stock and fulfilment lifecycle.
- [ ] **KP-072** Stripe TEST-mode checkout, order/webhook/idempotency, refund/support and accounting end-to-end tests.
- [ ] **KP-073** Owner-controlled first-live-order gate, regulatory and fulfilment verification.

## 08 — Acquisition + evidence
- [ ] **KP-080** 3–5 differentiated creatives, tracked traffic, organic-first socials, mobile UX tests.
- [ ] **KP-081** CAC, refunds, attribution and true contribution-profit measurement.
- [ ] **KP-082** Paid ads only when finances, business evidence and bounded authorization pass.

## 09 — Controlled live pilot and stabilization
- [ ] **KP-090** Deploy and independently verify logging, monitoring, backups and stop/resume.
- [ ] **KP-091** One validated low-liability product, supplier, buyer disclosure and low-risk live test under owner go/no-go.
- [ ] **KP-092** Reconciliation, customer support, incident simulation and sustained stability before scale.

## 10 — Multi-brand growth, only from proven economics
- [ ] **KP-100** Independent customer-facing brands and per-brand P&L with central AI HQ.
- [ ] **KP-101** Profit reinvestment, reserves, owner distribution rules and brand-level kill switches.
- [ ] **KP-102** More digital ventures only after liquid capital, risks and actual support capacity justify it.

## 11 — Android Dad app (LAST major planned delivery)
- [ ] **KP-110** Android premium app connected to proven backend: chat, journal, portfolio and alerts.
- [ ] **KP-111** Punjabi voice and mutual incoming-call proof-of-concept on real Android device in foreground/background.
- [ ] **KP-112** Calls respect learned next-day schedule, missed/rejected calls and rare emergencies.
- [ ] **KP-113** Authenticated mobile Emergency Stop and **owner-only** reviewed restart.
- [ ] **KP-114** APK build, installer, QA for notifications, battery restrictions, voice cost/privacy and secure release.

## 12 — Optional far-future, not seed-budget scope
- [ ] **KP-120** Dad-approved dedicated server/private AI, advanced compute, physical robotics, regulated authority review.

## Session working method
Read `AGENTS.md` + `docs/SESSION_HANDOFF.md` at the start. Work on the first practical, unblocked task. After **each** working session, update this checklist, add a dated `CHANGELOG.md` entry, record tests in `VERIFICATION.md`, and leave one concrete next step in `SESSION_HANDOFF.md`. A checked box must have traceable evidence, not confidence alone.
