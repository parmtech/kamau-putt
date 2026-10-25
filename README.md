# Kamau Putt (ਕਮਾਊ ਪੁੱਤ)

> **Public backend-first development repository — October 26, 2026.** The feature branch contains experimental financial reservations, a double-entry prototype, STOP/dispatch gate and owner-auth routes. These are development foundations, not a production system. **No production app, store, API integration, autonomous agent or live business has been deployed.**

## Vision
Kamau Putt is the private name of a long-term AI business operator that addresses its owner as **Dad**. The AI researches, launches and operates profitable digital businesses, starting with a small Australian dropshipping experiment, then expands only when actual funds and evidence justify it. Public brands may have completely different names.

**Operating principle:** Preserve capital, meet customer obligations, earn genuine sustainable net profit, and improve measured business performance. Profit is a goal, **not a guarantee**.

## Start here
- [Short feature checklist](docs/FEATURES.md)
- [Complete master plan](docs/MASTER_PLAN.md)
- [Phased roadmap](docs/ROADMAP.md)
- [Confirmed decisions and safeguards](docs/DECISIONS.md)
- [Owner authentication routes and provisioning](docs/OWNER_AUTH.md)
- [LIVE ordered checklist and verified progress](docs/PROJECT_TRACKER.md)
- [Continue across sessions: next actions](docs/SESSION_HANDOFF.md)
- [Developer/AI continuation rules](AGENTS.md)
- [Evidence/test log](docs/VERIFICATION.md)
- [Development changelog](docs/CHANGELOG.md)

## Starting constraints
- **AUD 200–300** preferred seed budget; **AUD 500 absolute cumulative startup ceiling**, **not per month**.
- Leverage existing Australian ABN, Stripe/bank account and Namecheap hosting where feasible, subject to compliance/technical verification.
- Build and stabilize a **minimal financially controlled Core first**; launch **one** evidence-selected product/brand; diversify later.
- No debt, uncontrolled ads, speculative large tests, fake reviews, hidden fees or supplier purchases without sufficient funds.
- Every discretionary paid action passes a cumulative budget authorization check.
- Owner-controlled emergency stop: **only Dad can resume**, after a brief risk/cost summary and explicit confirmation.

## Architecture
Persistent encrypted AI identity/memory + event journal + immutable accounting audit trail; autonomous business modules; provider-agnostic secure API gateway; customer-facing brand agents; Android voice/call app; web HQ dashboards. Multiple model keys per provider can be entered by the owner, with testing, approval, policy-compliant failover and global cost limits. No secrets or live customer data in Git.

## Status
- [x] Public GitHub repository created and connected
- [x] Product scope and requirements captured
- [ ] Architecture review and threat model
- [x] Minimal Core preflight code skeleton (11 local unit cases; **not** live money-safe)
- [ ] **KP-012** Financial reservations + double-entry ledger + prototype dispatch gate: MySQL 8.4 CI passed earlier feature-branch suites; current exact head must pass again after owner-auth controller changes. No production/staging approval; see [financial reservations](docs/FINANCIAL_RESERVATIONS.md)
- [ ] **KP-013** Owner-auth core and same-origin HTTPS login/session/logout routes implemented; fresh controller CI, recovery, STOP/resume binding and independent review remain pending; see [owner authentication](docs/OWNER_AUTH.md)
- [ ] Durable Core implementation and staging verification
- [ ] Sandbox integrations
- [ ] Test APK / real incoming call proof-of-concept
- [ ] Supplier, pricing and AU legal/compliance verification
- [ ] Live product validation and launch

**Status clarification:** The branch contains tested prototypes in addition to plans; it is not evidence that any business will be profitable or that an autonomous production agent exists. **Android app is LAST in the development sequence** per the owner's latest decision. Start future sessions with [AGENTS.md](AGENTS.md) and [SESSION_HANDOFF.md](docs/SESSION_HANDOFF.md).

