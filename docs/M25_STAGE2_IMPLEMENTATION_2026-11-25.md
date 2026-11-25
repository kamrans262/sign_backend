# M25 Stage 2 implementation handoff — 2026-11-25

Status: main development changes committed; release verification and identified gaps are open. No deploy, credentials installed, live payment activation or App Store submission. Existing branches only.

- Flutter: fix/m20-android-live-reminder-integration-2026-11-06, implementation head e462000fbd01945a6886c7845659fe50fab7dbec.
- Laravel: fix/m20-admin-ui-modal-filters-2026-11-06; functional head before this handoff 4601f81760c5b9ee6c590273831206fb72027101.
- Admin: existing billing and public deletion management, no separate repo.

## Requirements mapping
- 01–09: StoreKit 2 product catalog, localized purchase, restore, native management, renewal/refund reconciliation, JWS verification against configured Apple CA and direct App Store Server API, tenant token and original transaction binding, separate provider storage and existing entitlement read path. Apple management on iOS; Stripe preserved on Android. New StoreKit flag is false by default. Cross-provider race/duplicate purchase scenarios, real certificate chains and signed notification payloads require sandbox verification. Online certificate revocation checking is not implemented; review verifier before production.
- 10–18: app deletion using verified email code or fresh Apple identity and existing 2FA, ownership resolution, existing erasure service, encrypted refresh token exchange and revocation queue, signed account event audit and session invalidation. Missing legacy Apple token yields manual revocation instructions in the in-app flow. Public/admin deletion still requires testing of older Apple accounts and ownership cases. Email-enabled/disabled events are audited; relay state/delivery is not yet independently verified. Apple account-deleted event invalidates sessions rather than automatically erasing shared company records. This event workflow needs review before release. Deactivation remains separate. Shared companies with no eligible active administrator need support to arrange ownership.
- 19–21: explicit, account-bound, versioned AI consent; Flutter preflight for AI routes; API middleware plus Laravel HTTP client OpenAI egress guard; withdrawal. Existing manual flows remain. Test fixtures updated to explicitly record consent. Full inventory and tests of every AI path and admin use remain open.
- 22–24: app-owned PrivacyInfo.xcprivacy bundled via Xcode; microphone purpose string, legal links and updated site texts. Manifest and SDK Required Reason API inventory require archive inspection; legal operator identity/address/contact and privacy retention facts need owner approval.
- 25–36: release verification, signed device builds, App Store Connect metadata, screenshots, age rating, export/privacy declarations, review instructions, TestFlight and submission remain later-stage tasks.

## Secure configuration (do not enable during Stage 2)
- Apple Team GQAS52N9NZ; bundle com.sign2digital.app.
- Sign in with Apple: M25_APPLE_SIGNIN_KEY_ID, M25_APPLE_SIGNIN_KEY_PATH. Private key stored outside public root with restrictive permissions. After sandbox exchange verified, evaluate M25_APPLE_SIGNIN_REQUIRE_EXCHANGE=true. Existing login without exchange remains available by default.
- StoreKit: M25_STOREKIT_ENABLED=false, M25_STOREKIT_ENVIRONMENT=Sandbox, M25_STOREKIT_ISSUER_ID, M25_STOREKIT_KEY_ID, M25_STOREKIT_KEY_PATH, M25_STOREKIT_ROOT_CA_PATH (trusted Apple root in PEM format), M25_STOREKIT_APP_ID (numeric production ID). Use an isolated sandbox environment; never grant production entitlements from sandbox transactions.
- Proposed product IDs: com.sign2digital.app.business.monthly and com.sign2digital.app.business.yearly. Register in the same subscription group; prices and availability require business approval.
- HTTPS endpoints: POST /api/v1/billing/apple/notifications and POST /api/v1/auth/apple/events. Configure in Apple only after deployment authorization.
- Schedule existing Laravel cron tasks; new commands sign:m25-apple-revoke and sign:m25-storekit-reconcile.

## Verification status
Existing backend CI at 4601f817: AI support assistant, short quote prompts, site report QA, reminder AI, AI forms and multiple other focused workflows passed. Failures in expense reviewer permission (403), labour worker access (403), and invoice update/idempotency/deposit allocation (409) remain to investigate. Do not weaken permissions or version checks to satisfy tests. Initial Flutter analysis failed due to accidental duplicated source in SignApiClient introduced in the consent change; corrected in e462000f. Re-run results not yet confirmed. Dedicated backend compliance workflow added. Actual Xcode build, complete CI and sandbox purchase/revocation are unverified. Do not consider Stage 2 fully closed or release ready until these checks and gaps are resolved.
