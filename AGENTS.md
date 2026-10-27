# Kamau Putt — instructions for future AI development sessions

This repository (made **public** by the owner on 2026-10-26) is the source of truth for implementation state. Read, in order:
1. `README.md`
2. `docs/PROJECT_TRACKER.md`
3. `docs/SESSION_HANDOFF.md`
4. `docs/DECISIONS.md`
5. `docs/CHANGELOG.md` and `docs/VERIFICATION.md`
6. `docs/MASTER_PLAN.md` and `docs/ARCHITECTURE.md`

Rules:
- Continue the first uncompleted, unblocked work item. Do **not** replan, restart, or create a duplicate system without checking the existing work.
- Dad explicitly decided **backend/core first and Android app last**. Do not silently change this.
- Preserve all prior decisions; record new/changed decisions and reasons in `docs/DECISIONS.md`.
- For **every** meaningful change, commit corresponding code + tests + `docs/PROJECT_TRACKER.md` + `docs/CHANGELOG.md` + `docs/SESSION_HANDOFF.md` updates. For validated tests also update `docs/VERIFICATION.md`.
- State exactly whether a feature is Planned, In Progress, Implemented/Untested, Locally Tested, Staging Verified, or Production Verified. A document or passing unit test is not proof of an end-to-end production feature. Never say "bug-free" without evidence.
- No live ads, payments, purchasing, supplier commitments, sensitive account access, or spending until owner authorization and the transactional financial/emergency-stop gates are independently verified.
- Do not commit API keys, authentication tokens, private customer/owner conversations, account identifiers, passwords, backups, or environment secrets. Public source and synthetic fixtures are separate from private runtime data.
- Hardware, banking authority, new capital, and resuming an owner emergency stop remain Dad's decisions.
- API routing must comply with provider terms; multiple owner-entered keys do not authorize quota evasion.
- If information is missing, flag it in the handoff and do the safe independent next step.
- Keep next-session notes compact, actionable, and accurate; include changed files, tests, blockers, and exact next task.

Important: This project aims for profitable business, but earnings, uninterrupted availability, and autonomous self-training are not guaranteed.
