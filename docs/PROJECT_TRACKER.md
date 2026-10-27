# Kamau Putt — Ordered Build Checklist & Live Status

**Updated:** 2026-10-27 (Australia/Sydney)
**Owner decision:** Build/test backend and web controls first. **Android app last**.  
**Current phase:** 01 — backend + financial reservation prototype, **in progress; not deployable**.  
**Source:** [Master Plan](MASTER_PLAN.md) · [Decisions](DECISIONS.md) · [Next session handoff](SESSION_HANDOFF.md) · [Verification](VERIFICATION.md).

## Status conventions
- [ ] **Pending / blocked / in progress** — see notes, not complete.
- [x] **Complete with stated evidence** — a completed *document*, *local unit test*, *staging test*, and *production operation* are different levels. Do not conflate them.
- Completion gate: implementation + recorded test result + security/cost review + tracker/changelog/handoff update.
- No automatic switch from local tests to real spending or customer operations.

## 00 — Project control and research (first)
- [x] **KP-000** Public GitHub repository and original owner requirements captured. Evidence: repository `parmtech/kamau-putt`; owner changed visibility to public on 2026-10-26.
- [x] **KP-001** Numbered end-to-end sequence, session handoff, changelog, verification and AI continuation instructions. Evidence: this tracker + `AGENTS.md`.
- [x] **KP-002** Record revised build order: backend first, Android last. Evidence: DECISIONS and ROADMAP.
- [ ] **KP-003** Namecheap provider documentation reviewed; shared hosting prohibits <5-min cron and stand-alone daemons. Actual account extensions, TLS, SSH, backup/restore and resource limits still UNVERIFIED. See [hosting preflight](HOSTING_PREFLIGHT.md).
- [ ] **KP-004** Threat model / privacy review and Australian legal/commercial checklist for ABN, consumer law, refunds, privacy, tax, marketing, supplier disclosures.
- [ ] **KP-005** Provisional InnoDB migration, PHP 8.2+ repository and initial GitHub PHP CI added on feature branch; actual environment, migrations and staging/CI results not verified. Do not finalise stack until account preflight.

## 01 — Secure backend Core (START HERE)
- [x] **KP-010** Minimal, framework-independent PHP Core skeleton and local test runner. Evidence: `src/Core`, `tests/run.php`, `composer.json`; local syntax check and 11 unit cases on PHP 8.4.24.
- [x] **KP-011** Pure spending **preflight** policy: action cap, approved budget, free settled cash, scope, owner stop and survival mode. Evidence: 11/11 local unit tests. **NOT** a transactional lock, ledger or production safeguard.
- [ ] **KP-012** InnoDB schema + PDO reservation/ledger prototype: MySQL 8.4 run `40247011094` passed all five reservation, accounting, concurrency, auth and owner-control DB suites. Migration/backup/restore and actual hosting still pending. See [financial reservations](FINANCIAL_RESERVATIONS.md).
- [ ] **KP-013** Authenticated owner identity, 2FA and session security; roles/permissions. CLI-only bootstrap and same-origin HTTPS auth routes are implemented. MySQL 8.4 run `40246815770` passed owner auth/controller 19/19 and owner-control 12/12. Recovery, roles, UI and independent review remain incomplete.
- [ ] **KP-014** Append-only owner STOP/resume audit journal, hash-chain verification and SQL update/delete guards passed on MySQL 8.4 run `40247011094` (owner-control suite 15/15). General business decision/event journal, retention and independent timestamp source remain.
- [ ] **KP-015** Encrypted provider-key storage, masked listing, pending/activate/revoke lifecycle and immutable hash-chained audit implemented; MySQL 8.4 CI run `40247547998` passed 16 vault assertions. No provider adapter, live key test, rotation UI or production key use.
- [ ] **KP-016** Same-origin static owner HQ shell now uses authenticated status/STOP/reviewed-resume and masked vault APIs; header, hosting and browser integration verification remain. No credential entry/activation UI or deployment.
- [ ] **KP-017** PHP lint/policy, Owner HQ JavaScript syntax check and seven MySQL integration suites passed GitHub CI on commit `ca6d8ab` (PHP run `40311875586`, MySQL run `40311875558`). Broader static/security audit and verified staging deployment remain open.

## 02 — Financial control and survival (must precede live autonomy)
- [ ] **KP-020** Reservation + append-only double-entry ledger prototype passed 25 sequential, 29 accounting and 33 local independent-process assertions; latest MySQL 8.4 CI passed 40 concurrency cases. Full real cash reconciliation, provider dispatch/reconciliation and authenticated stop/resume remain pending.
- [ ] **KP-021** Budget policy: single AUD 200–300 planned capital injection, **AUD 500 cumulative external seed ceiling**; earned funds may be reinvested only under approved envelopes and reserves.
- [ ] **KP-022** Track settled cash vs Stripe pending, customer liabilities, taxes, fulfilment, chargebacks, existing commitments and net contribution.
- [ ] **KP-023** Owner Emergency Stop persists across restarts; cancels/pauses discretionary jobs and verifies third-party state. Authenticated STOP/epoch audit passed run `40247011094`; local MariaDB 11.4 verifies STOP returns during unlocked provider I/O, flags the in-flight attempt, and permits exact one-time settlement. MySQL 8.4 run `40423702670` passed owner-control and all six other DB suites. Third-party cancellation/reconciliation and staging races remain.
- [ ] **KP-024** **Only Dad** can resume after brief risk/cost summary + fresh password/TOTP, exact confirmation phrase and one-use state-bound challenge. Fifteen owner-control assertions passed on run `40247011094`, including audit immutability, changed-summary denial, replay denial and Safe Shutdown refusal. Android/UI, concurrency race tests and independent security review remain.
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
- [ ] **KP-040** Multi-provider credential vault foundation for Gemini/OpenAI/future providers implemented; only one immutable credential version may be stored per provider/key fingerprint. Health smoke-test transport, multiple key versions, usage, UI and real provider integration remain.
- [ ] **KP-041** Pure privacy-aware route policy now filters inactive keys, stale/non-AUD quotes, data class, task/language capability, quality floor and action cost ceiling; cheapest eligible offer wins. PHP run `40310403543` passed 13 route cases and MySQL workflow `40310403535` passed. Provider benchmarks, measured Punjabi speech and live quote provenance remain.
- [ ] **KP-042** Owner approval before activating new keys; policy-compliant provider fallback and quota monitoring.
- [ ] **KP-043** Token/audio/image/video costs journaled and reconciled with hard spend reservations and fallbacks.

## 05 — Reliable autonomy infrastructure
- [ ] **KP-050** Durable reservation-backed outbox with one-use leases, stable idempotency keys, atomic ledger settlement, STOP fencing, append-only audit and no automatic retry after ambiguity implemented. Local MariaDB 11.4.13 passed 27/27 job cases and 15/15 owner-control cases. First MySQL 8.4 run `40423366112` exposed JSON key-order instability in audit hashing; recursive canonicalization fixed it, and rerun `40423702670` passed all seven suites. PHP CI run `40423702753` passed. Scheduler, supervised workers, bounded real adapter, payload store and reconciliation UI remain.
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

## 2026-10-25 — Disposable database validation implementation
- Added isolated MySQL 8.4 service CI workflow with explicit PDO driver install and empty disposable database; no production credentials or external payment APIs.
- Expanded integration harness with uncertain outcome retention, denied-release rollback, reconciled commit, conflicting provider reference, terminal transition and no-double-debit assertions (22 sequential database cases total).
- Status: Implemented/Untested until the new CI run is observed passing. Local PHP/MySQL runtime unavailable in this session; no test pass claimed. Prior failed CI logs returned unavailable; root cause unknown.
- Next: inspect new workflow result, fix concrete failures, then implement independent-process reservation and stop races. Authenticated stop/resume, full accounting and live deployment remain pending.

## 2026-10-26 current evidence
- 11 policy + 10 arithmetic + 22 sequential DB + 33 process checks pass locally; prior sequential DB and pure PHP GitHub checks green at 9ef0c3b. See VERIFICATION.md for exact runtime and scope.
- KP-012/KP-020 stay incomplete: reservation tests do not complete an accounting system, deployment or authentication.

