# Sign2Digital — external configuration and acceptance
This is a procedure, not authorization to deploy, enable payments, revoke real accounts or upload an archive. Use isolated test accounts and sandbox services first.

## 1. Apple identity credentials (not billing)
- Apple Developer > Certificates, Identifiers & Profiles > Identifiers > com.sign2digital.app. Confirm Sign in with Apple and Push Notifications capabilities, team GQAS52N9NZ.
- Under Keys, create a Sign in with Apple key associated with the primary app ID. Save Key ID and download the .p8 securely. Never commit/upload it to GitHub or paste it in chat. Store outside the public root on Hostinger with owner-only read permission (600; enclosing directory 700).
- Once backend deployment is separately authorized, set private environment values:
  M25_APPLE_TEAM_ID=GQAS52N9NZ
  M25_APPLE_SIGNIN_KEY_ID=<key id>
  M25_APPLE_SIGNIN_KEY_PATH=<absolute private key path>
  M25_APPLE_SIGNIN_REQUIRE_EXCHANGE=false
- After configuration and authorized cache refresh, verify only the boolean with php artisan tinker --execute='echo app(\\App\\Services\\M25AppleIdentity::class)->configured() ? "READY" : "NOT READY";'
- On an isolated physical iPhone test first/repeat login, Apple Hide My Email, cancel, collision, 2FA, fresh deletion and provider revocation. Only after working token exchange confirm whether to enable M25_APPLE_SIGNIN_REQUIRE_EXCHANGE=true. Older apps lacking authorization code must be considered before enforcing.
- Register Apple server-to-server notification endpoint https://forestgreen-pigeon-673192.hostingersite.com/api/v1/auth/apple/events once deployment is authorized. Ensure scheduler lists sign:m25-apple-revoke. Verify successful revocation and queued retry using test accounts, without logging tokens.
- Apple Developer private email relay: register actual outbound email domains or addresses, configure SPF/DKIM per provider, test both privaterelay.appleid.com and private.icloud.com when applicable. https://developer.apple.com/help/account/capabilities/configure-private-email-relay-service
- Reference: https://developer.apple.com/documentation/technotes/tn3194-handling-account-deletions-and-revoking-tokens-for-sign-in-with-apple

## 2. Firebase and push
- Use existing Firebase sign-kamran and iOS bundle com.sign2digital.app. Keep real GoogleService-Info.plist locally at ios/Runner/GoogleService-Info.plist; the CI compile-only fake is never used for release.
- Check APNs authentication key in Firebase Console > Project settings > Cloud Messaging. Preserve existing push entitlements, team, Android config and Laravel private FCM credentials. Do not rotate working credentials unnecessarily.
- Test real iPhone foreground, background, locked, notification tap, denied permission and logout/device revocation. Test distribution APNs via authorized TestFlight later.
- On authorized server: php artisan schedule:list; php artisan tinker --execute='echo app(\\App\\Services\\M20FcmClient::class)->available() ? "READY" : "NOT READY";' (boolean only).

## 3. Stripe (no other payment provider)
- Retain M23 Stripe TEST configuration. M23_STRIPE_LIVE_MODE=false. Do not replace keys with live keys. Keep existing monthly/yearly price mappings and success/cancel/portal URLs; verify sign2digital://billing returns.
- With owner test account, verify test checkout, cancel, failed payment, paid plan activation only after server proof, renewal/cancel at period end, free plan and tenant isolation. Verify deletion warning and separate billing management.
- Confirm Stripe-only digital subscriptions eligibility for each intended App Store storefront under guideline 3.1. Do not hide payment links for review or assume business classification qualifies. https://developer.apple.com/app-store/review/guidelines/
- No StoreKit products, Apple purchase server credentials or in-app purchase setup needed for current development scope. Distribution eligibility remains a release gate.

## 4. Public site and server
- Owner confirms SITE_LEGAL_OPERATOR, SITE_LEGAL_ADDRESS, SITE_PRIVACY_EMAIL and retention/processor facts before publication. Confirm accessible Support contact for users unable to sign in. Ensure appropriate privacy disclosure and legal review.
- Once deployment authorized, verify GET /, /terms-and-conditions, /privacy-policy, /account-deletion, /admin/login, authenticated back-navigation, logout and direct dashboard redirect.
- Confirm HTTPS, private file access, email delivery, scheduler, database backups, migration status, APP_DEBUG=false, no test records in production. Do not print private config values.
- Use disposable accounts to verify public OTP, wrong/expired code, admin review and actual account erasure including owner transfer, retention and Stripe cancellation instructions.

## 5. Mac and physical-device acceptance
- From local sign_app current branch, back up any local Xcode project modifications before pulling. Preserve real ignored Firebase plist. Check xcodebuild -version, xcrun --sdk iphoneos --show-sdk-version and flutter doctor -v.
- Apple currently requires Xcode 26+ and iOS 26 SDK+; recheck https://developer.apple.com/news/upcoming-requirements/ before submission.
- Run flutter pub get; python3 tool/m25_strict_analyze.py lib test; flutter test; flutter build apk --debug; flutter build ios --release --no-codesign. Unsigned compile is not a release archive.
- Use real configured iPhone/iPad and Android. Test auth, company roles, clients, jobs, photos/notes, quotations, addenda, signatures, reports, meeting minutes, work acceptance, documents, labour, expenses, deposits, invoices, reminders, equipment, search, AI support, subscription and profile. Test manual paths with AI declined, withdraw consent, then verify AI again only after acceptance.
- Test create/edit/approve/sign/preview/download/share, PDF integrity and tenant isolation, denied permissions, empty/loading/error states, orientation and keyboard. Review Xcode archive privacy report and all bundled SDK manifests/required-reason APIs.
- When separately authorized, create signed distribution archive via Xcode with real provisioning, build number and credentials. Validate it; no upload yet.

## 6. Stage 4
- App Store Connect record, screenshots, description, age rating, privacy labels, export compliance, actual support/privacy URLs, demo reviewer account and instructions.
- Confirm storefronts and payment eligibility, legal approval and release owner sign-off. Only then authorize TestFlight and submission.
