# Sign2Digital M23 Stage 2 — Stripe Billing Implementation & Deferred Activation

**Date:** 2026-11-06  
**Module:** M23 — Subscriptions, Free Trial & Stripe Billing (final module)  
**Development branch:** `fix/m20-admin-ui-modal-filters-2026-11-06`  
**Flutter branch:** `fix/m20-android-live-reminder-integration-2026-11-06`

## Scope and truthful status

This handoff is for Stage 2 **code and automated acceptance**. The integrations and mocked Stripe workflows are meant to be implemented on the existing development branches, with no production deployment and no Stripe credentials supplied. **Real Stripe test-mode verification, Apple/iOS and Android device tests, callback domain setup, pricing approval and production deployment are separate manual gates.** Do not claim these external tests succeeded because mocked HTTP fixtures pass.

M23 Stage 1 remains intact: company-specific plans, three-free-document allowance, immutable first-issuance usage ledger, Admin plans/grants/revision history, original Flutter Overview/Choose/Manage screens, and quota enforcement OFF. M16 deposits and M17 customer invoices remain completely separate from SaaS subscription bills.

## Stage 2 architecture

- `config/m23_billing.php`: feature flags and server-only Stripe secrets, **OFF** by default, API constrained to `https://api.stripe.com/v1`.
- `app/Services/M23StripeGateway.php`: Laravel HTTP client with Bearer secret, Stripe idempotency header, fixed Stripe API origin, bounded timeouts, safe error redaction, provider Price ID checks, HTTPS hosted URL allowlisting, constant-time signed raw webhook HMAC with timestamp tolerance and test/live event mode rejection.
- `app/Services/M23StripeBillingService.php`: company-owner checkout, customer mapping, confirmed plan/price validation, retry-safe Checkout Session intents, explicit hosted Checkout, canonical provider subscription verification (not success-URL trust), Customer Portal, at-period-end cancellation/resumption, no-proration plan-change preview/confirmation, separate verified subscription invoice ledger, provider event replay/processing.
- `app/Http/Controllers/Api/M23StripeBillingController.php`: company.access and authenticated owner billing actions; public signed webhook route verifies the raw body and only then reaches company data. Checkout, status polling, Portal, cancellation/resume, plan-change preview/change API operations have explicit throttles.
- `database/migrations/2026_11_06_130000_create_m23_stripe_checkout_intents.php`: company-scoped unique retry UUID, Checkout Session ID, timestamps and replay state, provider sync fields and webhook diagnostics. Original and new migrations are reversible and tested on fresh SQLite/MySQL.
- `app/Console/Commands/M23ReconcileStripeBilling.php`: **dry-run default** command `php artisan billing:m23-reconcile`. `--apply` explicitly reconciles persisted subscribers with canonical Stripe state and only works after billing gates are on. `--company=ID` scopes the check. Never prints credentials. `M23_RECONCILE_ENABLED=false` prevents the scheduled hourly reconciliation until approved.
- Admin subscriptions page shows configured test/live mode, pending checkout and failed webhook counts, SaaS invoice history, plan revisions, temporary grants. It never exposes secret keys and cannot activate Stripe from the UI.
- Flutter adds externally hosted Checkout (via url_launcher), explicit status verification, trusted-host checking, changing plans only after an explicit no-proration preview, Billing Portal, cancellation/resume and genuine payment history while preserving approved fonts, colors, padding, responsive layout and navigation. A redirect alone never marks the plan active.

## Server API contract (authenticated, active tenant header)

All endpoints under `/api/v1/billing`, authenticated with Sanctum and `X-Company-Id`:
- `GET /overview`, `/plans`, `/eligibility`, `/usage`, `/history` — real, company scoped, no fake paid data.
- `POST /checkout` — `{plan_id,request_id: UUID v4}` creates a checkout only when both provider AND billing DB switches are enabled; returns `intent_id`, allowlisted `checkout_url` and pending status; server verifies Price amount, CHF currency, billing interval and company/customer mapping; no client-supplied price accepted.
- `GET /checkout/{requestId}/status` — canonical provider polling, returns `verified` only if actual Stripe Checkout and live subscription match.
- `POST /portal` — owner-only, allowlisted `billing.stripe.com` customer portal.
- `POST /cancel` and `/resume` with `{confirm:true}` — only provider-confirmed state reflected in app; cancel at period end by default.
- `POST /change-preview` with `{plan_id}`; `POST /change` with `{plan_id,request_id:UUID,confirm:true,proration_behavior:"none"}`; don't activate from a local callback.
- Public `POST /api/v1/billing/webhook` — raw `Stripe-Signature` HMAC verification, event ID idempotency and canonical provider reconciliation; invalid signatures, mismatched livemode and tenant identities rejected before entitlement changes.
- Operator command `billing:m23-reconcile` is not a public endpoint.

## Configuration: **do not perform this until the user explicitly authorizes**

The two Stripe API keys the user already possesses are not sufficient for webhook safety. A separate signing secret, allowed callback HTTPS URLs, validated Price IDs and opt-in switches are required:

```dotenv
M23_STRIPE_ENABLED=false
M23_STRIPE_LIVE_MODE=false
M23_STRIPE_SECRET_KEY=
M23_STRIPE_WEBHOOK_SECRET=
M23_STRIPE_SUCCESS_URL=
M23_STRIPE_CANCEL_URL=
M23_STRIPE_PORTAL_RETURN_URL=
M23_RECONCILE_ENABLED=false
```

- The publishable key (`pk_test_...`) need not be embedded in Flutter for **hosted Checkout**. The server-only secret is `sk_test_...`; the webhook destination generates `whsec_...`. Never paste either private value into chat, GitHub, client artifacts, logs or Admin HTML.
- Plan `stripe_price_id` values must be real `price_...` IDs for server-approved **CHF amounts, tax settings, interval and currency**. There are no real prices pre-provisioned by this Stage 2 commit.
- Approved return URLs must be owned HTTPS paths with proper app-return handling. Return URLs are navigational, **not payment proof**. Configure the Stripe Billing Customer Portal separately.
- **Two switches required:** private `M23_STRIPE_ENABLED=true` and `m23_billing_settings.stripe_enabled=true`, only after explicit deployment/setup authorization. They are independently OFF by default. Admin edits cannot activate these switches. Never set `m23_billing_settings.enforcement_enabled=true` until existing-company usage, legacy issued documents and business terms are reconciled with the owner.
- Live mode later requires separate `sk_live_...`, live-mode `whsec_...`, live `price_...` IDs, explicit billing agreement, approval and real-device smoke tests. Do not mix live and test keys or prices.

## Offline acceptance / manual release gates

CI tests use Laravel `Http::fake` and deterministic Stripe-shaped fixture responses; no real Stripe network calls or cards. Verify:
1. Disabled gateway rejects all charges and signed-webhook processing; no DB subscription is fabricated.
2. Tenant-owned customer and Checkout Session, duplicate UUID retry, mismatched price and client-supplied amount refusal.
3. HMAC/replay, stale/tampered signature, wrong livemode, unknown customer, canonical subscription status, failed/out-of-order webhook retries.
4. Separate subscription invoice from M17 client invoice; payment failure and grace policy are never mistaken for an active plan.
5. At-period-end cancellation/resumption, plan preview and explicit confirm; no false immediate plan upgrade.
6. Flutter real/authenticated company API, hosted URL allowlist, pending checkout, manual verification, plan-change consent, portrait 320px and landscape, and existing M20–M22 regressions.
7. Migrations on fresh disposable MySQL 8.4 and SQLite; Android debug compile. Separate iOS simulator and real-device acceptance remains mandatory before deployment.

**Post-code activation sequence (only on future explicit instruction):** take verified off-server backup -> authorized release/preflight and migration dry run -> configure private Stripe TEST secret/whsec, published prices and callback URLs -> verify provider test payments/SCA, billing portal, webhook delivery/replay, wrong account, renewal/failure and cancellations -> observe reconciliation -> review legacy company allowance and quota activation separately -> seek explicit authorization before LIVE/production rollout.

**Deployment:** NONE performed. **Secrets:** NONE provided or embedded. **Production database:** untouched. **Main branches:** untouched.
