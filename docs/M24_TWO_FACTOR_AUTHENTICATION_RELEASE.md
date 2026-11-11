# Sign2Digital — M24 Authenticator-based Two-Factor Authentication

## Scope

M24 adds RFC 6238 TOTP as a *user-enabled third login step* after the existing password and email OTP. New accounts remain unenrolled. Administrator web sessions are not changed.

- Password → email OTP → TOTP/recovery code → 30-day Sanctum bearer.
- Setup requires the current account password, a new encrypted 160-bit secret, and a valid authenticator code before activation.
- QR URI supports standards-compliant authenticator apps; manual secret is offered if the QR cannot be scanned.
- 10 random 64-bit recovery codes are displayed once, stored only as keyed hashes, consumed once and rotated as a set.
- Encrypted secret is never included in profile, audit, logs, backups sent to customers, or subsequent API responses.
- TOTP verification accepts ±30 seconds, with monotonically increasing accepted timesteps to prevent replay.
- Login ticket is random 256-bit, stored as SHA-256 only, expires in 5 minutes, allows 5 tries, and is single-use.
- Credential changes revoke other tokens and outstanding authenticator login tickets.
- Disabling or regenerating requires password and a valid TOTP/recovery code.
- A password reset preserves enrolled authenticator protection.

## Deployment prerequisites

1. Take and independently verify a production database, .env and private-file backup.
2. Preserve the existing APP_KEY; changing it would make encrypted TOTP secrets unreadable.
3. Verify PHP OpenSSL/encryption and accurate UTC time/NTP on the server.
4. Deploy the coordinated backend and Flutter code; migrate with `php artisan migrate --force`; clear config cache using the project's deployment process.
5. Confirm the mail transporter sends login OTP. TOTP uses no Firebase, Stripe, webhook, paid API or external secret.
6. Test first with a non-production user: enable, scan QR, verify setup, save recovery codes, sign out, password + email OTP + authenticator, incorrect code, one-use recovery code, rotation and disable.
7. Confirm previous and alternative app versions cannot receive a bearer without the second factor after enrollment.
8. Rollout is opt-in. Never set 2FA enabled in the UI until the server confirms verification.

## Operational security and recovery

If a user loses their authenticator, use one of their saved recovery codes. In cases where all codes are lost, escalation requires a separately reviewed identity-verification and audited administrative recovery process; do not bypass 2FA using a password reset or the customer-support ticket alone. An administrator reset endpoint is deliberately **not** exposed without that process.

No production deployment was performed as part of development.
