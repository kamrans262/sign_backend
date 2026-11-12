# Sign2Digital M23 — Mahj-style Stripe TEST Checkout and app return

**Backend host:** `https://forestgreen-pigeon-673192.hostingersite.com` (the existing Flutter `SignApiConfig.baseUrl`)  
**Checkout:** Stripe-hosted external browser  
**App callback:** `sign2digital://billing/success?intent=<UUID>`  
**Scope:** Existing Flutter and Laravel branches. No webhook or new table is required for test mode.

## Flow

1. The user selects the published Business monthly or yearly plan in Flutter.
2. Laravel validates the plan's CHF amount, recurring period and Stripe Price in TEST mode and creates an authenticated, company-owned Checkout Session.
3. Flutter opens the returned `https://checkout.stripe.com` URL in the device browser.
4. Stripe redirects the browser to `https://forestgreen-pigeon-673192.hostingersite.com/billing/success?intent=<UUID>`, or `/billing/cancel?intent=<UUID>`. The public page attempts to launch the app's registered URL scheme; a visible **Open Sign2Digital** button is a fallback.
5. On Android/iOS the app returns to a branded Subscription screen after the existing auth and company gate; it calls `GET /api/v1/billing/checkout/{UUID}/status` under the authenticated `X-Company-Id` company. Laravel independently requests the canonical Stripe Checkout Session and Subscription and only enables verified paid access after successful verification. Cancel/redirect alone does not activate anything.
6. **View Subscription** loads the latest server-owned subscription/usage details. Manual Verify Again remains available if network or provider state is pending.

The app callback URI is navigation-only and carries a random UUID, not a Stripe key, a customer secret or verified payment state. No webhook is necessary for the initial test flow. Full production lifecycle synchronization still benefits from webhooks later.

## Private hosting setup (performed by operator, NOT by code deployment)

`APP_URL` in Laravel's `.env` must be:

```dotenv
APP_URL=https://forestgreen-pigeon-673192.hostingersite.com
M23_STRIPE_ENABLED=true
M23_STRIPE_LIVE_MODE=false
```

Keep the existing server-only `sk_test_...` key in `STRIPE_SECRET` or `M23_STRIPE_SECRET_KEY`. Never paste it in tickets/chat or commit it. `M23_STRIPE_SUCCESS_URL`, `M23_STRIPE_CANCEL_URL` and `M23_STRIPE_PORTAL_RETURN_URL` may stay empty: the new Laravel handler derives them from the public HTTPS `APP_URL`.

After pulling and clearing Laravel configuration:

```bash
php artisan billing:m23-setup-test
```

This previews the published Business monthly/yearly CHF prices and does not call Stripe or modify the DB. With explicit owner approval:

```bash
php artisan billing:m23-setup-test --apply
```

This creates missing products/prices in **Stripe TEST mode only**, validates the recurring amounts and intervals against published admin plans, attaches Price IDs (preserving revision history) and sets `m23_billing_settings.stripe_enabled=true`. It leaves document quota enforcement unchanged and refuses live keys/mode. If either provider Price fails verification, Stripe Checkout stays disabled.

### Android/iOS build

Flutter uses `app_links` and native URL scheme registration, so run `flutter pub get` and perform a **fresh native build/install**; hot reload cannot register OS URL handlers.

### Manual device QA

- Verify `https://forestgreen-pigeon-673192.hostingersite.com/billing/return` opens a branded browser page and the button returns to the app.
- In the installed app choose Business monthly, tap Continue to Secure Checkout, pay with Stripe test card `4242 4242 4242 4242`, any future expiry and valid test CVC.
- Verify the app returns automatically (or by its fallback button), Laravel confirms the payment, and Subscription Overview shows the provider-verified paid plan.
- Repeat for cancellation and yearly plan. Test background and cold-start app returns. A browser-only redirect must never grant an active subscription.
- Do not enable live keys, real payments, quota enforcement or deploy automatically.

**Note:** Source-level CI uses Stripe HTTP fakes and Flutter widget tests. Actual Stripe test-mode payment and iOS/Android real-device return behavior require operator verification after deployment.
