# Sign2Digital — M4 Stage 2 verification and deferred M3+M4 release handoff

**Date:** 2026-11-03  
**Modules:** M3 — Company Setup & Management; M4 — Client Management  
**Development process:** Exactly **two stages per module**. M4 Stage 1 implementation and the Stage 2 code/release-readiness checks are recorded here.  
**Explicit user instruction:** **DO NOT DEPLOY M3 OR M4 YET.** They must be included in a **single consolidated deployment at the end of the development work**, after the user approves the final rollout. No independent M3 or M4 production release.

## Source and traceability

Project scope, English §2–3 and §20 (also French §2–3 and §20):
- Individual account with multiple companies, separate company data, switching, membership permissions: **M3**.
- Clients: manual creation, searchable records, device contacts import, review before saving, prior jobs/documents, strict per-company segregation: **M4**.
- Preserve all approved Flutter labels, text fields, dropdown selections, screens, navigation and design. Landscape widths are corrected only on affected screens.
- Admin sidebar uses actual module names; no redundant Mobile Authentication sidebar module, Platform Console or Secure Console. Users retains access to mobile OTP activity via its account-management subpage.

## Consolidation already verified

Both M4 feature branches were created directly from their corresponding M3 feature branch and have no missing intervening M3 commits. GitHub compare reports **ahead** of M3 and **behind 0** on both repositories. The M4 branch therefore contains M2/M3 as well as M4. **Do not deploy M3 separately first or cherry-pick M4 over unrelated production.**

| Repository | Combined branch (future source of truth unless superseded by later modules) | Parent |
| --- | --- | --- |
| Laravel | `kamrans262/sign_backend` → `feature/m4-client-management-2026-11-03` | `feature/m3-company-management-2026-11-03` |
| Flutter | `kamrans262/sign_app` → `feature/m4-client-management-2026-11-03` | `feature/m3-company-management-2026-11-03` |

Later modules should build on this consolidated M4 baseline or a reviewed integration branch. Do not lose older M3/admin changes when merging later.

## M4 Stage 2 results — isolated code and tests

- Existing M4 functionality preserved: field-complete client add/edit, paginated search, company-scoped list/detail, explicit reviewed phone-contact import, admin create/edit/filter/archive/restore, audit logging, membership authorization and denial of foreign record IDs.
- **Additional security verification:** same user belonging to two companies cannot mix records by switching the company header; revoked members cannot read/import; archived and company admin filters are independent; M3 and M4 routes coexist; unknown address-book country is **not** incorrectly fabricated as Switzerland.
- **Laravel CI:** combined M3+M4 feature/tenant/permissions tests passed on SQLite and MySQL 8; M2 mobile OTP/admin auth regression tests passed; PHP syntax and existing Blade compilation verified.
- **Flutter CI:** combined M3 company API/landscape/auth tests and M4 API/Add Client/Clients/Client Details/import UI tests passed. Additional widget test covers device permission denial, reviewing selected contacts without an upload, cancelling without upload, and explicit confirmation sending only one chosen contact.
- **Native Android compile:** validate the `flutter_contacts` integration with the dedicated Stage 2 Android **debug APK compile** workflow. This is a build/compile check, not phone permission acceptance or distribution.
- Existing UI-only demo client repository is preserved for isolated previews. Real mobile sessions use the authenticated `RemoteClientRepository`; company context is enforced on requests and checked on responses.
- **Known future module boundary:** Previous Jobs, Quotes, Documents and Invoice tabs stay present, but their actual history will be populated only by their corresponding modules. Do not report demo history as real database history.

## Combined future deployment: blocking preflight

The final deployment is **deferred**. When ALL desired modules are implemented and the user explicitly authorizes a release, complete these checks in order:

1. Determine the actual Hostinger backend branch, commit, PHP version, active vhost, MySQL database engine, current migrations, clean working tree, application environment, SMTP and storage permissions. Never output `.env` secrets.
2. Confirm the production PHP is **8.4.1+** with the locked Composer dependencies; PHP 8.3 is not compatible with the *current* `composer.lock`. Check the actual host, not merely the CI runner.
3. Back up the **actual** production MySQL database and ALL private app storage (company logos/terms and any later documents/uploads). Keep a verified recovery location, integrity checks and restoration procedure. Record a rollback point and current backend commit. Do not regenerate `APP_KEY`.
4. Select **one final consolidated backend commit containing M3, M4, and all later reviewed modules**. Do not deploy an earlier M3 branch over a later M4 branch; do not run `composer update`. Review dependency-lock compatibility, code changes, env keys and add-only schema migrations before any write.
5. Use **read-only** `php artisan migrate:status` plus `php scripts/m3_m4_release_preflight.php` to check the final code/environment; verify the migration order: M3 `2026_11_03_200000_create_company_invitations_and_active_context` before M4 `2026_11_03_300000_create_company_clients_table`. Preflight intentionally reports PENDING schemas before migration.
6. **Only after user approval and verified backup**, run reviewed production migrations for the consolidated release, then run `php scripts/m3_m4_release_preflight.php --require-migrated` and confirm `M3_SCHEMA_PRESENT` and `M4_SCHEMA_PRESENT`. Do not run migration commands in the current stage.
7. Confirm M2 real email OTP and admin password-only auth, M3 company create/switch/team/invites/files, M4 client CRUD/search/admin/phone import, foreign-tenant denial, revoked membership denial, portrait/landscape width and mobile/device permissions. Test physically on Android and iOS devices and check SMTP real mail delivery.
8. Verify `/api/health`, Laravel logs, storage permissions, generated routes and live API response correctness. Record live release commit, database backup reference, migration ledger, results, and release/rollback owner; only then mark **Deployed**.

### Read-only Hostinger inventory command for the later deployment window

```sh
ssh -p 65002 u777477188@147.93.42.143 'cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && php -v | head -n 1 && git branch --show-current && git rev-parse --short HEAD && git status --short && php artisan migrate:status'
```

**This is inventory only.** It makes no source, database, config or file changes. Do not execute a deployment or migration as part of M4 Stage 2.

### Read-only server gate (once final consolidated code is present)

```sh
php scripts/m3_m4_release_preflight.php
```

After a separately approved backed-up deployment/migration, use `php scripts/m3_m4_release_preflight.php --require-migrated`. Both commands are read-only. The script deliberately hides connection exception details.

## Real-world acceptance still deferred until release

These cannot be honestly checked through GitHub alone: Hostinger production PHP and SMTP, recoverable production backup, real Android/iOS permission dialogs, actual system phone contacts and user-approved imports, authenticated real user login against migrated production, company invitation emails, live admin UX and complete smoke testing after migration.

**Current truthful status:** M4 code/tests **verified in CI**; M3 remains **undeployed**, M4 remains **undeployed**. Combined production deployment is **explicitly deferred** by user request. Do not label either module live or production-verified.
