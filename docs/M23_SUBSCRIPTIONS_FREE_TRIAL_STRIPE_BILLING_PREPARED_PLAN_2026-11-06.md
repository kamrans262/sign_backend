# Sign2Digital M23 — Subscriptions, Free Trial & Stripe Billing

**Status:** PREPARED ONLY (architecture, contracts, acceptance criteria). No migrations, Stripe integration, credentials, live billing or deployment in this preparation.
**Date:** 2026-11-06
**Module numbering:** M21 Global Search & Advanced Filters; M22 Profile, Security, Settings & Support; **M23 Subscription, Free Trial & Stripe Billing — final development module**. Ignore obsolete scope labels M24/M25 for development sequencing.
**Existing development branches — do not create new ones:**
- Flutter `kamrans262/sign_app`: `fix/m20-android-live-reminder-integration-2026-11-06`
- Laravel `kamrans262/sign_backend`: `fix/m20-admin-ui-modal-filters-2026-11-06`

## 1. Boundary and current audit

- Flutter already has `lib/features/subscription/presentation/screens/subscription_overview_screen.dart`, `choose_plan_screen.dart`, `subscription_management_screen.dart`; reusable feature/action/plan cards and `subscription_plan.dart` / `subscription_overview_repository.dart`. These are approved designs, but repository data and catalogue prices are **explicit demo fixtures**. They are NOT evidence of paid status.
- Existing path is **Subscription Overview -> Choose Plan (Monthly / Yearly) -> Payment Details -> Confirmation -> Subscription Active**. Management supports Change Plan, Payment Method, Billing History, Usage and Cancel Subscription. The missing payment / confirmation flows must be connected rather than invented as successful fake payments.
- Example CHF 20/month, CHF 200/year and CHF 49/month figures currently in Flutter are demo design values, not approved sellable prices. **Real amounts must be loaded from the Admin-managed plan catalogue and validated against configured Stripe Price objects.** Never bill demo amounts.
- Laravel currently has **no** M23 subscription billing controllers/routes, Stripe SDK or subscription tables. Existing M16 deposits and M17 invoice/manual-payment ledger belong to customers of Sign2Digital businesses, not the SaaS owner's subscription revenue. No auto-processing of M16/M17 payments through Stripe in M23.
- Existing multitenancy uses company membership, `X-Company-Id`, `company.access`, scoped role/permissions, `AuditLogger`, Laravel Sanctum, private storage, and Admin's established layout. Account profile deletion/ownership rules in M22 must remain compatible.
- Original project scope includes **three free documents** before subscription is required. A separate arbitrary **30-day** Sign2Digital trial is **not approved** here (that value belongs to another project). Support an Admin-configurable trial policy without inventing its calendar duration.
- The user already has a Stripe publishable key and secret key, but explicitly wants to **add them after M23 code completion**. Treat them as unavailable throughout Stages 1–2. All external Stripe calls disabled by default, with mocked gateway contract tests.

## 2. Nonnegotiable preservation and tenant rules

1. Work and commit exclusively on the **current branches listed above** for BOTH repositories. No branch creation, no merge/main changes, no Hostinger deployment, no Firebase activation, no Stripe live calls or real charges without separate authorization.
2. Preserve exact approved Flutter screens/design tokens: Inter, primary `#166282`, dark `#122536`, secondary `#667085`, placeholder `#8A9AA8`, border `#D9E2EA`, radius 10px, horizontal padding 20px, existing cards/header/tabs/selection sheets/buttons/nav. Admin uses existing sidebar, cards and forms without redesign. Landscape full-width and portrait layout need acceptance.
3. **Company-scoped subscription** proposal aligns with existing multi-company architecture: billing belongs to each company, active owner / explicitly authorized billing admin may subscribe/change/cancel, other members only see appropriate plan/access state. If contractual billing ownership differs, resolve against the signed scope **before** charging anyone; never infer billing company from untrusted request data.
4. Same-company IDs throughout: Stripe Customer/Subscription/Price references are never selected by a client-supplied customer ID; no cross-company status, invoice or payment-method leakage. Admin platform management is separately audited.
5. **Fail safely:** unconfigured Stripe must disable real purchase/method-update actions, not mark accounts paid. Feature flag `M23_BILLING_ENABLED=false` (or project-equivalent) defaults off. Migrating tables must not automatically lock existing tenants out; a deliberate migration/grandfathering policy precedes activation.
6. Use server-sourced entitlements on every chargeable issue/finalize action, not just a Flutter paywall. Already issued quotes, reports, invoices, signatures, receipts and legally necessary records must remain readable/exportable after trial expiry, payment failure or cancellation. Editing drafts and access to account/support must not become inaccessible unexpectedly.
7. Do not collect raw card number, expiry or CVC into Laravel/Flutter; use Stripe-hosted Checkout/Customer Portal where compatible with the approved navigation. Do not log Stripe secrets, webhook signatures, full payment data, or sensitive checkout URLs.
8. Keep **subscription billing history separate** from customer-facing M17 invoices and manually recorded M16 deposits. The Stripe billing invoice PDF/receipt is not automatically a Sign2Digital user's business invoice.
9. Idempotency and Stripe webhook signature verification are mandatory before granting paid access. Return URL is navigational only; it must NEVER independently activate a subscription. Stripe documentation: https://docs.stripe.com/payments/existing-customers?platform=web&ui=stripe-hosted and https://docs.stripe.com/api/events.
10. Every important operation has loading, success, incomplete, canceled, failed, retry and unknown-state handling. Do not invent successful payments or active subscriptions.

## 3. Stage 1 — Complete Subscription & Trial functional foundation (NO Stripe keys)

**Exit goal:** all DB, API, Admin and Flutter flows exist, real local trial/usage gates and management state function without Stripe; gateway stays disabled. DB/CI acceptance green. Nothing deployed.

### Data model and transactional business rules

- M23 plan catalogue with stable `plan_code`, monthly/yearly interval, display name, currency (CHF initially per current UI; configurable only if actually supported), minor-unit amount, feature/entitlement manifest, document quota, active/archived state, explicit Stripe `price_id` **optional until activation**, version/updated_by and audit. Admin can create/edit/publish/archive plan content with history; changing a live Stripe price is a new plan/Price revision, not silently mutating existing subscriptions.
- Per-company subscription ledger: one current billing relationship per company; separate append-only transition/change log and immutable Stripe identity fields. Track statuses such as `free`, `trialing`, `incomplete`, `active`, `past_due`, `unpaid`, `paused`, `canceled`, and `pending_activation`; period start/end, trial and cancellation dates, plan revision and last provider reconciliation. Treat provider status and derived entitlement state separately.
- Free-document entitlement: default **3 unique finalized/issued documents per company** before a subscription is required; central action-specific `M23EntitlementService` defines eligible document types (quotes, site reports, invoices, AI-generated documents and approved document issuance), counts each unique document **once**, and never double-counts a PDF regeneration/signature. Configurable eligible types and free limit in Admin, with clear initial policy and user-facing remaining count. **Do not count previews, unsaved forms, drafts or failed issuance.**
- Protect issuance with a company-row lock, unique `company_id + source_type + source_id + issue_event` ledger key, and atomic allowance consumption. Concurrent requests and retries must never exceed the free allowance or charge twice. All existing business validation and immutable issued-document controls remain authoritative.
- Migration/grandfathering policy: until an explicit authorized activation, existing tenants retain their current access; new plans and counts are stored without imposing access restrictions. Establish how legacy already-issued documents and imported records are treated before enabling the gate; never retroactively subtract trial allowance without notice.
- Server-side authoritative usage endpoint for current-cycle and lifetime usage, with counters derived from ledger and reconciled to original source tables. Document quota semantics (unlimited or numeric, calendar reset based on subscription cycle, per-feature entitlements) must be explicit.
- Company ownership and billing permissions enforced on read/write and admin surfaces; no self-assignment of free overrides or price. Audit every plan/override/entitlement change with actor, reason and effective dates.
- Read-only access to own issued documents and support remains available to inactive/canceled subscriptions unless existing security/role rules forbid it.

### Proposed API (align with existing v1 routing/middleware)

- `GET /api/v1/billing/overview` — company-scoped current status, plan, period/trial dates, free-document remaining, features and usage; never demo data.
- `GET /api/v1/billing/plans` — published plan catalogue in local currency/interval, public benefits, server prices; no client-supplied amount accepted.
- `GET /api/v1/billing/usage` — company-scoped usage and relevant issue counts.
- `GET /api/v1/billing/history` — billing ledger and provider-origin invoices when available; empty until confirmed, never fabricated.
- `GET /api/v1/billing/eligibility` / authoritative service checks before actual document issue/approval, with consistent `subscription_required` response and current allowance.
- Reserve contracts for checkout, customer portal, cancellation, plan changes, verified return reconciliation and webhook, but **never report provider success without verified provider data**.
- Add `Cache-Control: private, no-store`, pagination, validated filters, exact tenant access, rate limits and structured 401/403/404/409/422/503 behavior.

### Fully manageable Admin

- Integrate a dedicated **Subscriptions & Billing** module using the existing Admin layout, one icon and consistent labels. Tabs/pages for Plans, Company Subscriptions, Trials/Usage, Billing Transactions, Webhook Events, Configuration and Audits as appropriate.
- Plan create/edit/activate/archive with monthly/yearly display price/feature/quota settings; configured Stripe Price IDs shown only as IDs, secret never visible. Audit changes and prevent destructive edits to subscribed plans.
- Company subscription lookup, filter by status/plan/period/company, read-only provider reconciliation, safe grant/override with reason+expiry, trial/free-allowance configuration, tenant usage review, receipts; admin cannot counterfeit a Stripe payment or override its provider event history.
- Settings for disabled/enabled payment integration, mode information **without exposing credentials**, webhook health, trial policies, retry periods and feature flags; admin cannot turn live billing on until required secrets/Prices/webhook have been verified and separate authorization exists.
- No new Admin UI design, no duplicate menu pages or dummy action buttons.

### Existing Flutter screen integration

- Replace logged-in demo repositories with real company-bound remote repos in the approved Overview, Choose Plan and Management screens. Keep existing demo repository only in explicit isolated design/test previews.
- Show genuine trial/remaining documents/current plan/payment status and accurate usage/feature availability; avoid fake CHF amounts, paid badges, billing dates and receipts.
- Implement the missing **Payment Details** checkout-review/confirmation state using existing widgets and layout; keep payment capture delegated to Stripe-hosted checkout in Stage 2. In Stage 1, show a clear disabled/unavailable state rather than process money.
- Wire Change Plan, Payment Method, Billing History, Usage and Cancel actions to server contracts without false success. Maintain selected monthly/yearly state and the active company across navigation. Company switching cancels/discards stale responses.
- Centralize subscription-required navigation/CTA when a real eligible document-issue action reaches its allowance; do not scatter incompatible paywalls across screens. Show non-destructive warning before blocking the specific write.
- Portrait 320px phones and landscape full-width, real scroll/retry, deep-link/back resume, no overflow, existing theme retained.

### Stage 1 acceptance

- SQLite & disposable MySQL 8.4 migrations; tenant authorization, owner/admin permission, trial 3-document boundary, supported counted-types matrix, idempotent issuance and concurrent attempts, historical/immutable read, plan CRUD/audit, expired overrides, legacy data preservation, absence of secrets.
- Flutter widget/integration tests for existing subscription screens and new review/details screens, monthly/yearly selection, company switch, loading/error/empty states, no demo billing for authenticated sessions, 320px portrait and wide landscape, failed API and cache invalidation. Run targeted M1–M22 smoke/regression checks. Fix incorrect tests rather than skipping real assertions.
- Stage 1 closes only when the full non-Stripe module is functional, CI green, changes committed on current branches, and `M23_BILLING_ENABLED` remains off.

## 4. Stage 2 — Stripe adapter, lifecycle, security and release readiness (NO real keys)

**Exit goal:** payment/billing integration code complete behind disabled flags, with fake provider and signed fixture acceptance, green CI. Live Stripe validation **cannot be declared complete** until separate post-code credential activation.

1. Implement a typed provider adapter and fake Stripe gateway in CI; real Stripe SDK/service provider on Laravel only. All secrets stored in server environment/secret manager, not source control, Flutter binaries or Admin HTML. No Stripe outbound traffic until configuration/authorization.
2. Implement one company-specific Stripe Customer mapping and server-created **Checkout Sessions in `subscription` mode** using backend-owned published Stripe Price IDs, callback success/cancel routes, idempotency key per logical checkout intent and protection against duplicate subscriptions.
3. Use an approved **Payment Details -> Stripe-hosted Checkout -> confirmation/pending -> verified subscription active** flow. Confirmation fetches authoritative server state, tolerates delayed webhook receipt, and never grants entitlement solely from success URL or `checkout.session.completed` if payment still needs verification.
4. Implement **Stripe Billing Customer Portal** for updating payment methods, viewing Stripe billing invoices and eligible plan/cancellation management; confirm portal configuration/URLs under server control. Preserve existing app Management actions and return-to-app state.
5. Support monthly/yearly upgrades/downgrades, clear provider proration previews and `proration_behavior` choices, cancellation at period end by default, immediate cancellation only with explicit separate rule, resumption, failed payment recovery and entitlement grace windows with documented policy. Never apply unverified price changes on the client.
6. Build a dedicated public **webhook** endpoint with Stripe signing-secret verification over the exact raw request body; verify livemode, event IDs, provider account and company/customer mapping. Secure unique event IDs, idempotent ordered processing/reconciliation, retries, dead-letter state, operator-safe reprocessing and constant-time rejection of invalid signatures. Never publish an unsigned test endpoint.
7. Handle relevant billing events, including `checkout.session.completed`, asynchronous checkout payment updates where applicable, `customer.subscription.created/updated/deleted`, `invoice.paid`, `invoice.payment_failed` and trial ending. Reconcile out-of-order events to current provider state before modifying entitlements. Avoid assuming event delivery is exactly once.
8. Persist billing invoices/receipts, currency, minor-unit amounts, Stripe invoice ID, paid/failed status and provider timestamps; safe download/link policies and user-company isolation. Do not generate fake Stripe receipts. Keep M17 business invoices unchanged.
9. Add scheduled reconciliation (off by default until deployment), renewal/grace-period checks and observability: sanitized event logs, retries, mismatch alerts and Admin diagnostics. Existing M20 notifications may surface billing reminders only when notification delivery is configured.
10. Explicit test cases: duplicate/out-of-order webhook events, bad/missing signatures, incorrect Stripe customer-company mapping, mismatched Price IDs, pending checkout, SCA/incomplete payments, payment failure, canceled plan, retry, concurrent checkouts, plan downgrade/upgrade, proration, customer portal, unauthorized company members, Stripe outage, unavailable provider credentials, app relaunch during Checkout and sign-out.
11. Run SQLite + MySQL 8.4 backend security suites, Flutter widget/integration and Android debug compile. Include a separately authorized iOS simulator compile if supported; **real iOS/Android device + browser/Stripe Dashboard tests remain manual**. Preserve M20/M21/M22 passing regressions.
12. Stage 2 is **code/CI complete** only when all acceptance passes without secrets. Production rollout and actual Stripe test-mode/live-mode verification are explicitly post-stage activation activities, not simulated assertions.

## 5. After code completion — **separate Stripe credentials & activation**

User has **two values**, normally:
- `STRIPE_PUBLISHABLE_KEY` = `pk_test_...` or `pk_live_...`; suitable for client-facing payment integrations if needed (hosted Checkout may not need it in Flutter).
- `STRIPE_SECRET_KEY` = `sk_test_...` or `sk_live_...`; **Laravel server only**.
- A **third credential**, `STRIPE_WEBHOOK_SECRET` = `whsec_...`, is issued when the Stripe webhook destination is configured; it is separate from the two API keys and required for safe webhook processing.
- Monthly/yearly `price_...` IDs and the intended Stripe Product(s) must also be configured, with supported CHF amounts/tax and interval confirmed by the account owner.

**Do NOT paste API secret or webhook signing secret into chat, GitHub, Flutter code, Admin preview, test logs or a public document.** Configure credentials through the approved Hostinger private environment/secret-management process *after* Stage 2 approval, first in **Stripe test mode**. Validate no accidental live-mode mix, actual test Checkout, signed webhook delivery, subscription renewal/cancel, customer portal, billing invoice, callback deep links and reconciliation. Only later, with separate explicit authorization, provision live keys and authorize production deployment. No real card test on live mode.

## 6. Dependency, deployment and handoff checklist

- M1–M22 features stay intact and retain approved screen styles. M22 settings/profile must remain usable even after billing lapse. Admin plan settings are editable in Admin; no price labels hardcoded into mobile.
- Do not activate quota blocking across existing production tenants, run migrations on Hostinger, send trial-ending mail, change Stripe Dashboard settings, or create billable subscriptions during M23 development.
- Deployment requires a verified off-server encrypted backup, migration dry run against disposable sanitized data, production env/security/storage checks, rollback plan, iOS/Android real-device QA, test-mode billing checks, feature flag staged rollout, manual reconciliations, and explicit authorization.
- If Stripe testing can't run without credentials, state exactly **pending external validation** rather than claiming it passed.
- Final module M23 completes the development sequence. No future module number is silently invented.

## 7. First execution request

`Start Sign2Digital M23 Stage 1 — complete subscription plans, three-free-document trial and entitlement ledger, real Laravel APIs, fully manageable Admin, and approved Flutter subscription screens on the same existing branches. No Stripe keys yet, preserve existing UI, run targeted tests, commit, no deployment.`
