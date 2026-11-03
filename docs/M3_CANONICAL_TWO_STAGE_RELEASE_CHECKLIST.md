# Sign2Digital — Module 3 canonical two-stage plan and release checklist

**Date:** 2026-11-03  
**Module:** M3 Company Setup, Management, Switching & Team Permissions  
**Source of truth:** User-approved **two stages per module** (25 modules total). Prior M3 documents labelled Stages 1–4 are retained as historical implementation notes; they are **not** additional required stages.

## Final agreed plan

1. **Stage 1 — Complete Implementation:** requirements, backend, MySQL schema, authenticated APIs, Flutter integration, fully manageable administrator UI, existing design and relevant landscape fixes.
2. **Stage 2 — Testing, Verification & Deployment:** targeted code checks, traceability/negative security checks, production environment preflight and backup, approved migrations, real device/email tests, and verified live release.

### Mapping of earlier M3 work

Earlier "M3 Stage 1", "M3 Stage 2", and "M3 Stage 3" commits together represent the completed **Stage 1 implementation**. The previous "M3 Stage 4" was an incorrect label; its remaining work is the **final Stage 2**, started here. No files or code from earlier work are discarded.

## Requirements verification checklist (M3 only)

| M3 requirement | Current code/test evidence | Live release status |
| --- | --- | --- |
| Separate company profiles under one account | M3 Laravel API test creates two companies, switches server-side active ID | Device/server acceptance pending |
| Complete company fields (name, address, Swiss ID, VAT, IBAN, QR-IBAN, phone, email, document numbering) | M3 company creation and Flutter remote API tests; no fields removed | Live DB acceptance pending |
| Company switching with real tenant ID and active membership checks | M3 negative membership/tenant tests, Flutter response validation | Two-account device acceptance pending |
| Team roles owner/admin/member and explicit permissions | Middleware, role restrictions and platform admin member edit; focused tests on both DB engines | Live role change acceptance pending |
| Owner immutability; revocation and suspension | M3 tests deny owner demotion, foreign company access; revoke selected tenant on member removal/deactivation | Live API acceptance pending |
| Email invitation, expiry and verified email acceptance | Hashed single-use token, code only in email, fake-mail feature tests | Real SMTP and recipient acceptance pending |
| Private logo and terms files | Private Laravel disk, MIME+2 MB limit and access control; fake storage tests | Actual Hostinger file permission/upload/download pending |
| Administrator fully manages companies, owners, users, invitations, files and grants | Existing admin forms/routes + new Edit Access, audited by M3 tests | Live admin UI acceptance pending |
| Preserve Flutter approved styles and responsive layout | Existing widget components, targeted M3 screen checks, wide landscape tests | Physical phone/tablet acceptance pending |
| M2 email OTP unaffected, admin password-only unaffected | M2 regression tests in backend and Flutter focused suites | Real SMTP/auth acceptance pending |

**Boundary:** Later Clients, Jobs, Quotes, Invoices, Documents etc. still use their existing demo/session repositories until their modules are connected. M3 does not make those business records tenant-persistent by itself.

## CI evidence

- Laravel existing Stage-1 focused run, **passed**: [run 41624971801](https://github.com/kamrans262/sign_backend/actions/runs/41624971801) — SQLite + MySQL 8, M3 + M2 targeted regressions.
- Flutter, **passed**: [run 41625628643](https://github.com/kamrans262/sign_app/actions/runs/41625628643) — analyzer, company APIs, setup screens, M2 auth, responsive landscape checks.
- Additional final release compatibility gate (verified): PHP **8.4.1 or newer** is required by the **current composer.lock** (locked Symfony 8.1 components). Although composer.json declares ^8.3, PHP 8.3 fails Composer platform checks. **PHP 8.4 CI passed**: [run 41626826060](https://github.com/kamrans262/sign_backend/actions/runs/41626826060) — Composer install, syntax/view checks, SQLite, MySQL 8 and M2 OTP targeted regressions. No dependency versions were changed. Actual Hostinger PHP remains unverified.

## Production deployment gate — DEFERRED / superseded by combined release

**Important update (November 3, 2026):** The user explicitly deferred M3 and M4 production deployment until the end of the development work. **Do not use the earlier M3-only rollout steps below as authorization to deploy M3 independently.** The combined, authoritative release gate is [M4 Stage 2 + deferred M3/M4 handoff](M4_STAGE2_AND_M3_M4_DEFERRED_DEPLOYMENT.md). The following steps are retained as historical safety checks only.

**Do not claim a release until every item has been verified.** Production environment access, MySQL backup and live email/device acceptance are not available solely through the GitHub connection.

1. Identify deployed Laravel branch and commit and ensure a clean git working tree; check `php -v`, MySQL connection and `php artisan migrate:status`. Never echo `.env` or credentials.
2. Back up the *actual* production MySQL database in Hostinger hPanel and the private Laravel uploads/storage to recoverable locations. Verify backup exists and that a restore route is available. Keep the existing application key and domain routing.
3. Confirm PHP **>=8.4.1**, Composer platform requirements, SSL app URL, `APP_DEBUG=false`, non-log email mailer and write permissions for `storage/app`.
4. Deploy exact M3 backend feature branch code only with a reversible source revision and installed locked Composer packages; never run `composer update` or `php artisan key:generate` in production.
5. Run `php scripts/m3_production_preflight.php` (no data mutations), `php artisan migrate:status`, and only after the backup gate `php artisan migrate --force`. Rerun preflight and require `M3_SCHEMA_PRESENT`.
6. Verify read-only health/route listing, log in using a verified mobile test account, create/edit and switch two companies, invite recipient by email, accept once, revoke and verify denial, view admin audit trail, and test private logo + terms upload/download access.
7. Run the reviewed M3 Flutter branch on actual portrait and landscape devices/tablets and confirm no existing forms, headings, dropdown options, styles or navigation were lost.
8. Record production backend commit, deployment date/time, backup reference, health check, migration result, SMTP and device pass/fail. Mark module released **only** after these gates.

### Safe Hostinger discovery command (one line, read-only)

```sh
ssh -p 65002 u777477188@147.93.42.143 'cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && php -v | head -n 1 && git branch --show-current && git rev-parse --short HEAD && git status --short && php artisan migrate:status'
```

This command does **not** switch code, back up data, run migrations or send email. An empty `git status --short` is the normal clean-working-tree result. Do not proceed with a migration until backups and actual PHP compatibility are verified.

## Status

- **Stage 1: code implementation complete; targeted prior CI passed.**
- **Stage 2: started; release preflight tooling and compatibility verification added.**
- **Live deployed/production verified:** **not yet established**, not a green status.
