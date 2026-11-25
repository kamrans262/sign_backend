# M25 Stage 2 — revised Stripe-only implementation

Owner scope amendment: use the existing Stripe integration for Android and iOS, as in Mahj Around Town. Defer native Apple purchases until explicitly requested. No deploy, live payment activation, or submission.

## Billing (M25-01–09)
- Exact pre-M25 Stripe Flutter subscription files and Laravel billing services/Admin view restored; original Android and iOS checkout, browser return, plans and management retained. The undeployed M25 StoreKit bridge, service/controller, JWS transaction verifier, routes, reconciliation scheduler, migrations, product identifiers and configuration removed. No production database change executed.
- M25-02–08 deferred insofar as they concern Apple purchases. Stripe server verification, tenant ownership, existing document allowance and Admin settings remain.
- App Review: Stripe-only digital subscriptions are not universally compliant. Check guideline 3.1 and target storefront eligibility before submission. No reviewer-specific bypass or hidden payment flow.

## Identity and privacy (M25-10–24)
- Keep Apple Sign In, native authorization code transport, token exchange, encrypted credentials and retry queue. Legacy accounts without refresh tokens receive manual revocation guidance, including admin completion email. Identity event signature checks and replay handling retained.
- Verified email code or fresh native Apple identity, existing 2FA, ownership transfer, explicitly authorized sole-owner closure, account anonymization, token/session revocation and retained signed business evidence. Public admin deletion can close a sole-owner company only after explicit holder authorization; shared ownership remains protected.
- Stripe subscription management accessible before deletion; deletion is independent from cancellation and never falsely confirms cancellation.
- Versioned account-specific AI consent, withdrawal, all known AI routes and OpenAI HTTP egress gate. Existing manual features retained. Reusable approved UI. Added consent and deletion regression tests.
- App privacy manifest and permission explanations retained. Actual SDK privacy report, archive and owner/legal details still require verification.

## Regression corrections
- Existing expense and labour test fixtures now include required job/module grants, with record-level privacy retained. Expense approval uses live custom-role grants rather than stale pivot grants.
- Invoice edit tests use authoritative server-assigned invoice numbers, keeping immutability and optimistic locking intact.
- Admin login test now expects existing dashboard redirect.
- Focused non-deploying CI added. New Flutter workflow treats analyzer errors/warnings as fatal and reports informational deprecations (latest SDK emits existing Material import deprecation infos). Existing strict-info workflows not silently modified.

## Verification and external prerequisites
- Flutter revision: 6825725e223de3e09b59152285ac320556fa935a. Backend code before this documentation: a4514198644ef538627ee10d1b02f782f71aeb16.
- Verify latest CI status on both existing active branches. No physical iOS device or signed release archive verified in this session.
- Sign in with Apple identity only: M25_APPLE_TEAM_ID, M25_APPLE_SIGNIN_KEY_ID, M25_APPLE_SIGNIN_KEY_PATH, M25_APPLE_SIGNIN_REQUIRE_EXCHANGE (enable only after verifying credentials). Keep private key outside public root. Apple identity events endpoint POST /api/v1/auth/apple/events; Laravel scheduler sign:m25-apple-revoke.
- Apple identity credentials, actual email delivery, privacy SDK manifest inspection, distribution eligibility, TestFlight and release owner approval remain external/release checks.
- No Apple purchase setup or App Store Server API credentials needed for the revised development scope.
