# Sign2Digital — M22 Stage 2 Security & Release Readiness

Status: development-only. Nothing in M22 Stage 2 authorizes production deployment, Firebase activation, an App Store upload, or a database migration on Hostinger.

## Canonical working branches

- Flutter: `fix/m20-android-live-reminder-integration-2026-11-06`
- Laravel: `fix/m20-admin-ui-modal-filters-2026-11-06`
- Keep both branches in place; do not create a module branch or switch `main` without separate authorization.
- Approved Flutter screens, Inter typography, orange-free Sign2Digital palette, existing selection sheets, navigation and shared widgets must be preserved.

## Implemented and reviewed

- Authenticated profile viewing/editing, private photo normalization and retrieval, email-change OTP, password updates, session listing/revocation.
- Account-level notification preferences; M22 push opt-out controls M20 device registration/delivery in addition to company-level settings.
- Support FAQ publishing, private customer tickets/replies/uploads/downloads, platform-admin assignment, priorities, status, internal notes and FAQ management.
- Privacy export is account-scoped, omits staff-only notes and excludes company documents; output is copied to the clipboard on mobile.
- Delete Account requires password and explicit mobile confirmation, blocks company owners and platform administrators, erases private support records/files, revokes tokens/push devices, clears recovery grants, and anonymizes the personal account while retaining company evidence and legal audit references.
- Ticket changes are serialized against admin closure to prevent stale open-state writes; orphaned private upload files are removed on failed writes.
- Portrait layouts remain capped as originally designed; landscape M22 profile, security, preferences, app settings and support forms use the available device width.

## Automated CI gates (required before sign-off)

- Laravel `.github/workflows/m22-stage2-backend.yml`: PHP syntax, read-only route visibility, clean migrations, M22 stages 1 & 2 tests on SQLite and MySQL 8.4, authentication and reminders regressions.
- Existing M22 Stage 1, M20 Admin UI, M21 Stage 1/2 search workflows should remain green on the current commit.
- Flutter `.github/workflows/m22-stage2-flutter.yml`: strict Dart analysis, original M22 profile/security and responsive widget tests, authenticated session and M21 search tests, debug Android APK compile smoke.
- Existing M22 Stage 1 Flutter, M20 live reminder and M21 search workflows should remain green.
- All tests run only against CI fixtures. The CI Android APK is unsigned for release and is not distributed or deployed.

## Physical-device and browser acceptance — required separately

These tests **cannot be marked as executed from GitHub Actions**; a tester must run them on actual Android and iOS devices and the authorized admin website.

1. Android and iOS: login/OTP; More > Profile, Edit Profile, photo upload/removal, verified email change, password update and reauthentication.
2. Security: view sessions, attempt wrong-password revocation, revoke another session and verify the revoked device loses access.
3. Preferences: language/date/time/time zone, global push consent; verify unconfigured SMS is not advertised as deliverable. Do not enable Firebase push without explicit authorization.
4. Privacy & Data: confirm scoped export; cancel delete; test owned-company protection; with a dedicated disposable test account, confirm delete logs out and original credentials cannot be reused. Preserve all company invoices/signatures.
5. Support: published FAQs, create ticket, image/PDF upload and private retrieval, customer reply, closed-ticket behavior, wrong-user attachment URLs, and admin-only internal notes.
6. Admin: ticket search/filter/assignment/status, FAQ draft/edit/publish/delete, rejection of non-admin access, and no staff-only reply leakage.
7. On small portrait phones and landscape phones/tablets: check header, bottom navigation, all input fields, scroll reachability, keyboard and no overflow/clipping; rotate while editing.
8. Verify real email delivery and the SMTP configuration with a dedicated QA mailbox, not a production customer.

## Production readiness checks — **not performed**

- Obtain explicit deployment authorization, schedule a maintenance window and create an off-server encrypted database/private-storage backup with checksum and tested restoration.
- Confirm Hostinger PHP/Laravel runtime compatibility, HTTPS, environment flags, database credentials, private storage permissions, quotas, mail transport and API base URL.
- Review pending migrations against a disposable copy of production data and record rollback/recovery steps; **never** run `migrate:fresh` on Hostinger.
- Confirm no pending uncommitted local work, branch divergence, broken client links or rollout of unconfigured SMS/FCM services.
- Verify final Android/iOS builds, codesigning, developer accounts, runtime permissions, and live network behavior separately from CI.
- Do not merge, deploy, activate Stripe/Firebase or change remote services as part of M22 verification.

## Explicitly deferred capabilities

Separate authenticator TOTP, biometric authentication, full offline caching/synchronization, unsupported SMS delivery, complete app language translation and any new M22-wide email delivery system are **not** implemented. UI must not claim they are active. Data export is a scoped clipboard export, not a full company archive; account erasure retains legally necessary company records/audit evidence under their own retention controls.

## Release decision

Stage 2 can be considered **code/CI complete** when all relevant workflows are green. **Production/device acceptance remains pending** until the physical-device and Hostinger checks above are completed and separately authorized. This distinction must be preserved in every handoff.
