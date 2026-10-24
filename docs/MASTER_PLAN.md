# Kamau Putt — Master Plan v0.1
_24 October 2026 | Private design specification | Subject to feasibility, testing and Australian legal review._

## 1. Vision and mission
Build one owner-controlled, persistent, multi-business AI operating system, privately called **Kamau Putt**, with warm Punjabi “Dad” conversation and genuine financial discipline. It may research, test, launch, optimize and administer brands with a strictly bounded pool of capital and explicit permissions. The owner is the human legal principal. No profit or permanent uptime guarantee.

### Success definition
Healthy net contribution and actual cash after landed product costs, postage, GST/tax treatment, payment fees, refunds, chargebacks, marketing spend, software/API usage and support. Survival, customer trust and legal compliance outrank vanity revenue, brand count and growth projections.

## 2. Founding resources and capital
Preferred one-time seed AUD 200–300, maximum AUD 500 cumulative, not monthly. Plan for no new capital injections for six months. Owner already has Australian ABN, unused Stripe/banking, Namecheap Stellar Plus shared hosting, 84hub.com (historically used in another project), fresh Instagram/TikTok presence with no existing following. Verify suitability of each resource. Separate operating cash, unsettled payment processor balances, supplier obligations, refundable customer money, tax reserves and emergency reserves. Buying inventory or ad impressions is never allowed based on projected or unsettled cash.

## 3. Brain architecture and legal authority
Private identity/persona, conversation memory, structured facts, agent state, plans, event-driven journal, financial ledger and a portable model-agnostic tool registry. One authoritative active state, protected by concurrency locks/idempotency, with independently encrypted versioned backups, restore rehearsals and owner-held recovery. Public brands use separate names and customer-facing agents without access to private Dad context. The AI is software, not a legal person, account holder or company director. Use owner-controlled ABN/entity and delegated permissions.

## 4. AI model/API orchestration
Universal provider vault supporting multiple keys per provider and several providers (Gemini, OpenAI, future APIs). Secrets remain encrypted server-side; Android holds only revocable session tokens. New credentials undergo owner-approved bounded smoke tests for latency, price, quality, Punjabi voice, privacy, and failure behavior; concise approval summary before activation. Route each task to the cheapest model meeting a quality threshold and data classification; log all costs. Provider quotas and terms must be obeyed, not circumvented through multiple accounts. Avoid sending banking/private customer records to consumer/free APIs with unsuitable data terms; local/private alternatives where appropriate. Model swap does not erase Kamau Putt's memory.

## 5. Business intelligence and candidate score
Scan current and seasonal Australian demand, geographic/weather/calendar factors, search intent, trends, competing Amazon/eBay/Temu offerings, verified shipping charges, supplier reviews, returns/risk, and regulatory restrictions. Candidate weighting is provisional: trends 20%, purchase intent 15%, pain points 10%, competition 10%, true profit 20%, supplier reliability 10%, creatives/marketing potential 10%, scale 5%; optimize based on actual outcomes. Classification: Reject / Research More / Organic Test / Capped Launch. Hard-veto recalled, unlawful, unsafe or unverifiable claims/products. Early products should be small, easy to ship, low-risk and low return likelihood.

## 6. Suppliers, pricing and fulfilment
Evaluate Australian warehouse vs overseas origin (example 3–5 vs 12–20 days only when verified). Choose risk-adjusted expected contribution profit rather than cheapest sticker price. For a materially equivalent SKU offer one clear product base price and explicit shipping options where feasible: Free Standard (slower) versus paid Fast Australia (faster); accurately disclose delivery estimates and total paid amount. Stock and supplier availability checked before orders; backups and switching with quality controls. Do not promise express service when no reliable supplier is available.

## 7. Landing pages, checkout and offer tests
One fast mobile-first page per primary SKU, rather than many full-page variants: honest headline and verified media/benefits, prominently visible price and delivery options, Stripe guest checkout and mobile wallets where supported, optional cart. Quantity selector, buy-two bundle and complementary cross-sell discounts only when combined margin stays positive. Legal and transparent email consent for abandoned cart reminders; no fake scarcity, fake reviews or hidden charges. Run controlled micro-tests of headline/media when sufficient traffic, not chaotic daily redesign.

## 8. Acquisition, creatives and attribution
Begin with organic, newly created channels; create roughly 3–5 differentiated creatives per SKU all pointing to tracked versions of the same landing page. Compare creative clickthrough, cost, add-to-cart, purchased orders, refund rates and contribution profit. Use paid ads only after product-margin, fulfilment and small-bet risk gates. Allocate caps per brand, SKU, campaign and day alongside global cumulative budget. Never spend AUD 300 casually to test a product when starting capital is AUD 500.

## 9. Accounting / survival engine
Event-sourced financial entries for authorization, commitments, settlement, purchase, fulfilment, advertising fees, APIs, hosting, refunds, tax and distributions. Double-entry or equivalent auditable controls; append corrective entries instead of rewriting history. Daily reconciliation with Stripe/bank/supplier exports. Break-even and customer acquisition costs use all variable costs, including refund provision. Modes: Growth (validated and solvent); Survival (stop discretionary paid actions, low/no-cost research and organic only if resources genuinely available); Safe Shutdown (no new discretionary spend or new paid orders if fulfilment can't be guaranteed; retain backups, incident response and mandatory customer service/refund obligations). Automatic owner notification/escalation and decision evidence. No new borrowing or unsupervised personal money movement.

## 10. Android app and collaboration
Professional Android app with AI CEO text chat, portfolio cards, live ledger, alerts, searchable diary, Punjabi conversational speech, owner-initiated and AI-initiated incoming calls. During daily conversations, proactively ask Dad for next-day work/free windows; store them with expiry/timezone and respect busy/quiet hours. Emergency call exceptions for genuine fraud, security or material cash/order risk; missed/rejected calls respected. True background incoming-call reliability depends on OS notification permissions/push and device restrictions; test on actual phones. Automatic call transcript/summary and per-call cost entry, with retention/privacy controls.

## 11. Emergency stop and recovery
Owner can trigger Stop via authenticated Android button or authenticated voice command. Immediately gate and pause new paid ads, paid APIs, supplier orders not already committed and other discretionary financial actions (attempt cancellations at external providers with clear verification). Preserve existing lawful customer obligations, refunds, data protection and required support. Owner stop cannot automatically resume: Dad must review a **brief summary** of halted tasks, current state, risks and maximum potential spend, request more detail as desired, then explicitly authenticate approval. Existing external operations may need separate provider-side cancellation; never claim atomic control over third-party systems.

## 12. Journaling, learning, improvement
Continuous dated journal: observations, sources, choices, expected outcome, actual cost/outcome, unexpected errors, unmet capability needs and open questions. Every day run reflective analysis that proposes workflow/prompt/tool changes. Keep immutable change history; compare before/after on cost, latency, quality, sales and risk; test changes in staging with backup/rollback. Journaling improves a software system's decisions but does not imply the underlying foundation model has self-retrained. Dad can mentor the system and supply ideas it did not invent.

## 13. Security, privacy, resilience
Least privilege OAuth/API permissions; segregate business/public-brand and Dad/private contexts. Encrypted sensitive data at rest/in transit, audit logs, secret rotation and emergency revocation, 2FA for owner, secure device auth, idempotent payments/orders, abuse/fraud checks and restore tests. Do not store passwords/API keys in repository, Android build, or public marketing. Third-party consent and age/account requirements remain applicable, regardless of who supplies credentials.

## 14. Roles / roadmap
Dad decides legal identity, funding additions, expensive hardware, strategic upgrades, exceptional permissions and manual emergency restart. Kamau Putt handles authorized routine research/commerce and proactively reports decisions, risks and support needs. Core-first staged delivery: (0) architecture and compliance, (1) secure Core/ledger/memory, (2) Android calls/chat/control, (3) one store with Stripe test mode and fulfilment, (4) controlled marketing proof, (5) profitable multi-brand portfolio, (6) optional personal hardware/robot body with technical and legal controls. See [roadmap](ROADMAP.md).

## 15. Open questions / implementation validation
Check shared hosting long-running worker feasibility; voice/telephony and push options for Android; paid API rate/pricing/privacy terms; AU tax/GST, consumer law and direct marketing consent; dropship supplier agreements; Stripe payouts and disputes; insurance and business names/branding; secure backups and disaster recovery target; initial paid traffic minimum and customer acquisition assumptions. All depend on verification rather than promises.

## 16. Acceptance standards before any live spend
Tests must demonstrate no overspending (including network retries), complete auditability and cash reconciliation, emergency stop and authenticated owner-only resume, accurate honest delivery/payment disclosures, backups/restore, customer refunds/support and privacy protection. Pilot with a single low-liability product; scale only based on real results.

**Development note:** Business plans and checklists are product requirements; they are not a built or deployed system.
