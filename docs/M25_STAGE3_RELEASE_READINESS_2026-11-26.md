# Sign2Digital — M25 Stage 3 release-readiness report
Date: 2026-11-26. Status: partial verification; NOT release sign-off.

## Scope and revision evidence
- Flutter code: 47c78da2fbad952a8b8c40e0ca439dcb55b23fb9, branch fix/m20-android-live-reminder-integration-2026-11-06.
- Laravel/Admin code: 2f340262e4a4bc56addf6809c6137678b5ab8a7d, branch fix/m20-admin-ui-modal-filters-2026-11-06.
- Stripe only, original UI/navigation/permissions retained. No StoreKit, new branch, deploy, live payment enablement, or App Store submission.
- Fixed equipment custom-value dialog and profile deactivation controller lifetime (internal TextFormField owns controller through exit animation). Corrected privacy landscape test to scroll to its target. Reminder voice analysis uses narrow existing Material import compatibility exception; other diagnostics remain fatal.
- Apple revocation queue rotates attempted records so an unavailable older token does not starve later requests. Added mocked HTTPS exchange, subject binding, ES256 signing, encrypted refresh-token and retry tests.

## Actual CI evidence
| Check | Result at report | Evidence |
|---|---|---|
| M25 privacy/Stripe Flutter | PASS, 63 tests | https://github.com/kamrans262/sign_app/actions/runs/45683341528 |
| M21 search stage 1 | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683342306 |
| M21 search/equipment stage 2 | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683342146 |
| M20 live reminders | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683342416 |
| M20 reminder AI | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683342172 |
| AI support | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683342205 |
| More production navigation | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683341512 |
| M22 profile/support | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683341588 |
| Global navigation | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683341455 |
| Approved bottom sheets | PASS | https://github.com/kamrans262/sign_app/actions/runs/45683341507 |
| M25 backend focused compliance | PASS | https://github.com/kamrans262/sign_backend/actions/runs/45683505050 |
| Admin UI | PASS | https://github.com/kamrans262/sign_backend/actions/runs/45683504962 |
| Full Flutter analysis | FAIL, detailed log not yet available when checked | https://github.com/kamrans262/sign_app/actions/runs/45683341688 |
| All Flutter tests, Android debug compile, iOS unsigned release compile | In progress, not verified | https://github.com/kamrans262/sign_app/actions/runs/45683341688 |
| Full Laravel first run | 431 passed, 8 failed, 4 warnings, 1 skipped; new token crypto tests passed | https://github.com/kamrans262/sign_backend/actions/runs/45683505170 |

Prior backend security/billing/profile/search workflows at e0568f58 passed. They are not a substitute for the new full suite. iOS CI uses an explicitly fake Firebase plist if the ignored local file is absent; it verifies compile only, never real Firebase, signing or App Store archive. Live website URL checks were inaccessible from the checking tool, not proof of a server outage. Physical device, signed archive, real Apple provider, actual email delivery and Stripe TEST checkout remain unverified.

## Stage 1 requirements disposition
| Requirements | Status / acceptance gate |
|---|---|
| M25-01,04,09 | Stripe-only decision implemented; original checkout and server entitlement preserved. Public iOS storefront eligibility remains open. |
| M25-02,03,05–08 | Native Apple billing deferred by explicit owner direction. |
| M25-10,11 | Passwordless deletion challenge and existing 2FA code committed; focused tests passed; real Google/Apple/OTP deletion still requires device/mail tests. |
| M25-12,13 | Apple token exchange, encrypted storage, revoke queue and legacy fallback implemented; new cryptographic/HTTP mock tests passed; actual Apple key and endpoint verification pending. |
| M25-14 | Signed Apple identity event handling and session invalidation present; real events, email relay changes and device credential-state handling need verification. No claim of complete provider lifecycle. |
| M25-15,16 | Company transfer/sole-owner closure and erasure logic committed. Focused backend tests passed; full ownership, retention, private file cleanup and real tenant/device cases require verification. |
| M25-17 | Public deletion OTP/admin processing code and focused tests present; real HTTPS/mail/admin completion still unverified. |
| M25-18 | Stripe management before deletion and cancellation warning committed; actual sandbox flow pending. |
| M25-19–21 | Versioned AI consent/withdrawal, known route guard and OpenAI egress guard; focused Flutter and backend tests passed; complete feature/device data-minimization checks still pending. |
| M25-22 | App privacy manifest present; compiled app/SDK manifests, required-reason API report and labels need archive review. |
| M25-23 | Permission descriptions present; denied/limited permissions and iOS background behavior require device checks. |
| M25-24 | Public legal pages and in-app links committed; live checks and legal operator/contact/retention approval pending. |
| M25-25,26 | Previous user-reported Google iOS login and native Apple prompt, simulator push; physical Apple/Google/email/2FA sign-in not verified. |
| M25-27 | Existing push integration retained; production APNs/device delivery not verified. |
| M25-28,29 | Focused regressions passed as above; full test/analysis gate and supported device acceptance still open. |
| M25-30 | Host production runtime, scheduler, migrations, TLS, private storage, mail and payment mode require authorized inspection. |
| M25-31 | Full build CI running; distribution signing and SDK privacy validation require actual Mac archive. |
| M25-32–36 | App Store Connect metadata, review account, disclosures, TestFlight, eligibility and release approval not performed. |

## Exit criteria
Do not label Stage 3 complete or submit before full analysis and applicable suites/builds pass, real integrations and supported physical devices are verified, the legal/privacy information is approved, and Stripe-only distribution eligibility is confirmed. No automatic release is configured. See separate external configuration guide.

## Subsequent full Laravel run findings
The first full run returned 431 passed, 8 failed, 4 warnings, 1 skipped. The two M25AppleRevocationTest cases passed. PHP syntax passed. The eight failures were traced to legacy assertions: manually supplied quote/addendum numbers replaced by server sequences, missing consent in note-document security tests, and outdated dashboard/sidebar/CSS version labels. Commit 9221b88bc6c0a459a5d7fe25b3dc63a692103f38 updates those six test files against existing production behavior, retaining foreign-record denial, immutable signed sources, invalid number rejection and tenant-scoped numbering. No production billing or permission code was changed by that commit. Re-run: https://github.com/kamrans262/sign_backend/actions/runs/45684028803 (started; result not yet established at handoff). Warnings/skipped acceptance tests remain subject to release verification. The full Flutter analysis failure remains open until the complete job log is accessible.
