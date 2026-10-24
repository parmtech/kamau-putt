# Kamau Putt — Development & Decision Changelog

All dates Australia/Sydney. Append a new dated entry for each substantive session. Keep code history in Git commits. **Do not delete or silently rewrite previously recorded decisions.**

## 2026-10-24 — Session: financial reservation prototype and hosting preflight
- Kept work in existing private repository on `feat/kp-financial-reservations-foundation`; no replacement project.
- Reviewed current Namecheap shared-host policy; created `docs/HOSTING_PREFLIGHT.md`, noting 5-minute minimum cron interval and prohibition on long-lived daemons; account checks still pending.
- Added MySQL/MariaDB InnoDB migration schema, integer-cents financial calculations, PDO reservation/uncertain/commit/release prototype, lifecycle events, default stopped/survival mode and idempotency-safe replays.
- Added optional **staging-only** MySQL test harness and PHP CI workflow. In local PHP 8.4.24, new finance arithmetic passed **10/10**, syntax checked. Actual DB concurrency and GitHub CI result remain unverified.
- Updated tracker, verification, and handoff. No live financial permission or deployment has been enabled.

## 2026-10-24 — Session: formal build tracker and start of backend Core
- Confirmed private repo `manbir6-ux/kamau-putt` and existing planning/specification files.
- New owner build priority: **first build/test stable backend and controls, Android app last**. Captured in `docs/DECISIONS.md` and replaced earlier phase ordering in `docs/ROADMAP.md`.
- Established step-by-step acceptance-driven `docs/PROJECT_TRACKER.md`, AI continuation contract in root `AGENTS.md`, `docs/SESSION_HANDOFF.md`, `docs/VERIFICATION.md` and initial architecture outline.
- Started *only* a pure, dependency-free PHP Core spending-preflight guard; it makes no external purchases and has no database, secrets or production powers.
- PHP **8.4.24**: syntax checks clean and **11/11** policy test scenarios passed locally. **NOT** proof of production no-overspend enforcement.
- Next: environment verification and durable transactional ledger + authorization tests.

## 2026-10-24 — Earlier conversational design work
- Captured identity, autonomy, financial safety, Australian dropshipping plan, provider-agnostic API vault, memory, collaboration, call scheduling, owner emergency stop and future physical-hardware aspirations in Master Plan/Features/Decisions.
- These were **requirements only**, not implemented commercial features.
