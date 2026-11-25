# Sign2Digital M25 — Stage 1: App Store readiness
Audit date: 2026-11-25. Status: requirements baseline, not release approval.
Audited Flutter: 2d89e53876dd19471d4eecf4a931c825a6e871c4 (fix/m20-android-live-reminder-integration-2026-11-06).
Audited Laravel: e0629235852b6f34723c4863ecf136aad9a03a04 (fix/m20-admin-ui-modal-filters-2026-11-06).
Scope: public iOS distribution. Preserve existing approved Flutter and admin UI, navigation, company permissions, signed-document integrity, Android, Stripe test integration and existing branches. Do not deploy, activate live payments or submit without approval.

## Evidence and decisions
- Flutter remote subscription repository and payment details screen start external Stripe checkout; management routes also support Stripe portal and changes. No StoreKit code was found in the audited tree. Existing company-based subscriptions are not evidence of enterprise-only eligibility. Default plan: native IAP on iOS, retain Stripe on permitted platforms, do not rely on a regional exemption or hide functionality just for reviewers. Product IDs, price points, storefronts and agreements require owner approval. The app may retain existing verified cross-platform entitlements under applicable rules.
- Flutter PrivacyDataScreen and Laravel M22AccountController currently demand a password to delete an account. Apple/Google-created users have random unusable passwords. Public deletion uses an email code and admin support queue. AccountErasureService protects owned companies and erases selected personal records, but does not revoke Apple provider tokens. Existing Apple bridge returns identity token and name, not authorization code. Native prompt seen; successful login unverified.
- AI endpoints include setup, jobs, quotes, addenda, notes, labour, invoices, deposits, expenses (including receipt), reports, general documents, equipment/loans, reminders and support chat/audio. OpenAI receives audio/transcripts or other user input. No explicit versioned consent guard found in inspected API routes/controllers. Stage 2 must inventory every outbound call, including indirect calls.
- Existing public site/legal views are committed, not verified live; legal operator/contact and retention details need confirmation. No app-owned PrivacyInfo.xcprivacy found in the audited Flutter tree; SDK-bundled manifests need archive inspection.
- iOS bundle com.sign2digital.app, iOS deployment target 15.0 and iPhone/iPad device family. User reported Google login working and supplied screenshots demonstrating foreground/background simulator notifications and native Apple prompt. No physical device, release archive or TestFlight result established.
- Apple requires Xcode 26+ and iOS 26 SDK+ for uploads as of this audit; use supported current Xcode on Mac. Recheck before upload. iOS deployment target is separate from SDK requirement.
- No live environment inspection, dependency build, regression run or legal approval during Stage 1.

## Requirements and acceptance evidence
| ID | Requirement | Closure evidence | Stage |
|---|---|---|---|
| M25-01 | Choose public iOS billing approach; default native IAP, exception only with documented eligibility | Approved distribution/billing decision | 2 |
| M25-02 | Monthly/yearly StoreKit products in one subscription group, map to existing business entitlement | Sandbox catalog and mapping | 2/4 |
| M25-03 | Localized StoreKit pricing, renewal duration, terms/privacy links and purchase disclosures | iOS UI and sandbox tests | 2/3 |
| M25-04 | Prevent noncompliant iOS Stripe checkout/portal/upgrade links throughout UI and deep links; retain permitted non-iOS routes | Route and platform regression tests | 2/3 |
| M25-05 | Server verify signed App Store transaction, bundle, product, environment and expiry; no client-only unlock | Valid/invalid sandbox transaction tests | 2/3 |
| M25-06 | Bind verified purchase to authorized company; enforce idempotency and prevent reassignment/cross-tenant reuse | Ownership and replay tests | 2/3 |
| M25-07 | App Store Server Notifications V2 verification, renewal, expiry, refunds, revocation, grace and retry/reconciliation | Signed event fixtures and sandbox tests | 2/3 |
| M25-08 | Restore purchases, pending/canceled/failed purchases, finish verified transactions and manage Apple subscriptions | Device tests | 2/3 |
| M25-09 | Preserve Stripe/Apple provider separation, shared entitlements, existing allowance and Admin provider visibility; prevent duplicate billing | Regression and authorization tests | 2/3 |
| M25-10 | In-app permanent deletion for password, Google and Apple users without forcing creation of password | Each auth-method deletion test | 2/3 |
| M25-11 | Recent identity proof and existing 2FA for destructive actions; challenge expiry/replay protection | Security tests | 2/3 |
| M25-12 | Apple authorization code exchange and encrypted refresh/access token lifecycle; private client secret/signing key | Sandbox/real-device token tests | 2/3 |
| M25-13 | Revoke Apple tokens through official endpoint on all deletion paths; safe retry and existing-user reauthorization/fallback | Revocation success/failure and erasure tests | 2/3 |
| M25-14 | Handle Apple credential status/revocation and account change events as applicable | Signed notification/device state tests | 2/3 |
| M25-15 | Resolve company ownership, sole-owner closure and admin restrictions without blocking ordinary account erasure indefinitely | Owner/member/admin scenario tests | 2/3 |
| M25-16 | Erase or anonymize eligible personal data; revoke sessions, tokens, push devices; preserve required business evidence and explain retention | Database/files/security tests | 2/3 |
| M25-17 | Verify public deletion request email delivery, OTP limits, admin handling, receipt and privacy-safe completion | End-to-end dummy-account test | 3 |
| M25-18 | Subscription cancellation explanation and direct management access; immediate deletion option independent of billing period | UI and erasure tests | 2/3 |
| M25-19 | Explicit informed AI consent before any personal data is sent to OpenAI, including audio and attachments | First-use approval/decline UI tests | 2/3 |
| M25-20 | Versioned server consent record and centralized fail-closed checks across all AI egress, including support and pre-company setup | Route/provider-mock tests; denied calls never reach provider | 2/3 |
| M25-21 | Consent withdrawal and manual/non-AI alternatives; data minimization, purpose/retention disclosures | Revocation, fallback and payload tests | 2/3 |
| M25-22 | Complete data inventory, SDK privacy manifests, required reason API justifications and privacy report | Archive manifest and privacy report | 2/3 |
| M25-23 | Accurate camera, mic, photo, contact and notification usage prompts; denied permissions handled gracefully; no unnecessary background access | Device permission tests | 2/3 |
| M25-24 | Privacy/terms/support links reachable in app and on public site; legal operator, contact, processors, retention and transfer disclosures reviewed | Live HTTPS checks and owner/legal approval | 2/3 |
| M25-25 | Apple sign-in end-to-end, Hide My Email, repeat login, cancel, 2FA and existing account collision; compliant equivalent button | Physical iPhone auth tests | 3 |
| M25-26 | Google/email login, OTP, sessions, logout, tenant boundaries and existing security regression | Tests on iOS/Android and backend | 3 |
| M25-27 | Production APNs, TestFlight delivery, foreground/background/locked state and notification tap navigation | Physical device evidence | 3/4 |
| M25-28 | All modules: create/edit/approve/sign/download/share; PDF integrity, no duplicate cards, error/empty/loading states, permissions | Existing suite + physical device checklist | 3 |
| M25-29 | Verify physical iPhone/iPad supported layouts, accessibility, keyboard, text and app lifecycle | Device matrix; fix or narrow support with approval | 3 |
| M25-30 | Production HTTPS, private storage, migrations, config, cron, email, error handling and removal of test/demo leakage | Controlled environment readiness check | 3 |
| M25-31 | Xcode 26+/iOS 26 SDK+, valid distribution signing/profiles, entitlements and version/build; validate archive | Mac build and Xcode validation evidence | 3 |
| M25-32 | App Store Connect record, business agreements, tax/banking if paid, distribution territories and product setup | Account owner checks | 4 |
| M25-33 | Accurate metadata, localized screenshots, category, age rating, icon, support/privacy URLs and copyright | Completed listing | 4 |
| M25-34 | Privacy nutrition labels, export compliance and applicable regional declarations based on actual data practices | Completed questionnaires | 4 |
| M25-35 | Active isolated reviewer account, sample data and reliable OTP/2FA access; review notes covering AI, billing and signatures | Reviewer login verified, no global security bypass | 3/4 |
| M25-36 | TestFlight internal review, external beta review if needed, final defect closure and owner approval before submission | TestFlight evidence and release signoff | 4 |

## Owner inputs
Legal entity and contact details; distribution markets and subscription product/price approval; Apple key access for Sign in with Apple server exchange/revocation and App Store Server API as needed; App Store Connect agreements and products; physical devices and release signoff. Do not upload private keys to GitHub or chat. Do not enable live payments as part of implementation.

## References
- Apple App Review Guidelines: https://developer.apple.com/app-store/review/guidelines/ (3.1, 4.8, 5.1, 2.1)
- Account deletion: https://developer.apple.com/support/offering-account-deletion-in-your-app/
- Token revocation: https://developer.apple.com/documentation/technotes/tn3194-handling-account-deletions-and-revoking-tokens-for-sign-in-with-apple
- Submission SDK: https://developer.apple.com/news/upcoming-requirements/
- Privacy manifests: https://developer.apple.com/documentation/bundleresources/privacy-manifest-files
- Third-party SDK requirements: https://developer.apple.com/support/third-party-SDK-requirements/
- Privacy details: https://developer.apple.com/app-store/app-privacy-details/
- Review information: https://developer.apple.com/help/app-review/before-submitting-for-review/complete-review/
