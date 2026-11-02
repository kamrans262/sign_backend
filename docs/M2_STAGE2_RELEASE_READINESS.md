# Sign2Digital — Module 2 Stage 2
## Security audit, targeted regression verification and release readiness

**Status:** implementation and code-level verification; production deployment and SMTP tests remain separate.
**Backend branch:** feature/m2-email-otp-backend-admin-2026-11-03
**Flutter branch:** feature/m2-email-otp-flutter-2026-11-03

### Scope and preserved behavior

- Mobile authentication: create account, password + mandatory email OTP login, resend, forgot/reset password, remembered sessions, authenticated profile and sign-out.
- Admin Panel: existing password-only login, same Hope UI-inspired styling and functionality. Mobile Authentication monitoring is read-only.
- Existing Flutter Login, Create Account, Forgot Password and six-digit OTP screens are reused without changing approved visual styles. The Reset Password screen uses existing shared fields and buttons.
- New company API integration belongs to M3; M2 registration retains the approved onboarding destination.

### Security traceability

| ID | Requirement | Implementation |
| --- | --- | --- |
| 01 | No token before OTP | Registration and login create a challenge, only successful verification issues a token |
| 02 | Limited, short-lived codes | 6 digits; 5 minutes; 5 guesses; 60-second resend cooldown |
| 03 | Avoid races | Per-user database lock when issuing or superseding a challenge |
| 04 | Protect low-entropy OTPs | Server-keyed, challenge-bound HMAC-SHA256 stored instead of plaintext |
| 05 | Avoid recovery enumeration via resend | Unknown email gets a stored decoy reset challenge with matching resend behavior |
| 06 | Invalidate old access after password change | Single-use password reset grant, tokens revoked, outstanding OTPs consumed |
| 07 | Bound bearer lifetime | Mobile Sanctum token expires after 30 days |
| 08 | Secure logs | OTP email delivery failure records only sanitized exception type |
| 09 | Secure session persistence | Flutter uses secure device storage and rechecks bearer with server at launch |
| 10 | Prevent offline bypass | Network failure never opens authenticated dashboard |
| 11 | Handle secure-store failure | Old token cleared before code consumption; no insecure fallback persistence |
| 12 | Protect admin OTP monitor | No OTP plaintext or hashes rendered; platform administrator only |
| 13 | Safe demo provisioning | Interactive secret password; existing accounts not overwritten; mobile still requires OTP |
| 14 | OTP retention | Preflight and prune commands; prune records expired longer than 24 hours |

### Focused verification

- Laravel: run php artisan test --filter=M2 on SQLite AND MySQL 8. Test registration, login, concurrent issue cooldown, account enumeration, 30-day expiry, resend, OTP failure, reset, demo, authenticated profile, logout and admin password-only sign-in.
- Laravel: run php artisan test --filter=M1AdminUiRebuildTest to preserve previous admin forms and shell.
- Flutter: run analyzer only on M2 integration files and test secure session, API, profile, OTP, login, signup, forgot and reset widget screens.
- Targeted workflows: .github/workflows/m2-email-otp-focused.yml in both repositories.
- CI mocks outbound email and HTTP. A green CI run does NOT establish live SMTP deliverability, actual phone behavior or live browser rendering.

### Mandatory production release gates (NOT executed here)

1. Back up production MySQL and current backend deployment. Do not overwrite .env or use destructive migrations.
2. Confirm PHP 8.5, HTTPS, APP_ENV=production, APP_DEBUG=false, correct MySQL connection and a stable APP_KEY.
3. Verify MAIL_MAILER uses a real mail transport (e.g., SMTP) and a valid MAIL_FROM_ADDRESS. Do not use MAIL_MAILER=log or array in production.
4. Deploy the verified M2 backend branch. Run php artisan migrate --pretend --force to inspect changes before php artisan migrate --force.
5. Run php artisan sign:otp-preflight after migrations. This read-only command reports only driver/mailer/key/table readiness, not secrets. A real inbox test is still essential.
6. Provision the requested demo email using php artisan sign:demo-account followed by the email argument; enter the chosen password at the secure prompt. No password goes in Git.
7. Build Flutter from the tested M2 feature branch, install on a device and verify registration, login, resend, expiry, wrong codes, reset, remembered session, profile and logout using an actual mailbox.
8. Confirm password-only Admin Panel login still works and the Mobile Authentication page does not display OTP values or hashes.
9. Configure Hostinger cron or existing scheduler to run php artisan sign:otp-prune at least daily. The command removes only stale OTP records, not audit records.
10. Inspect Laravel and mail delivery logs safely. No OTP, bearer token or SMTP password should be shared as a screenshot.
11. Review responsive Admin Panel in a live browser and verify existing Flutter screen design on actual phones/tablets.
12. Merge into development/main only when specifically approved.

### Exclusions and limitations

- No administrator OTP is required, as expressly requested.
- No live account, SMTP test, database migration, release, host-side operation or production credential changes were made.
- M3 live company creation is separate. Google/Apple sign-in are outside this email-OTP implementation.
- Rotating APP_KEY invalidates current OTP codes (5-minute lifetime).
- Decoy challenges mitigate straightforward email enumeration through resend API responses; network timing of outbound mail cannot be guaranteed identical.
