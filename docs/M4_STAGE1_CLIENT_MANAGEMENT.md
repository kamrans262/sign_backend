# Sign2Digital — Module 4, Stage 1: Client Management

**Date:** 2026-11-03  
**Source of truth:** Sign2Digital – Project Scope, English Section 3 / French Section 3; Section 2 company data ownership; Section 20 data isolation; approved existing Flutter client UI and the agreed **two stages per module**.

## Scope and implementation

| Source requirement | Stage 1 implementation | Stage 2/live release gate |
| --- | --- | --- |
| Manually create clients | Existing Flutter Add Client screen → authenticated company-scoped Laravel CRUD; preserves six fields and notes | Live Android/iOS submit and persistence |
| Search existing clients | Live company-scoped paginated API, case-insensitive full name/company/email/phone search; existing app search field still filters all fetched pages | Large real data and offline verification |
| Import from phone contacts | Android/iOS read-only permission is requested only on deliberate button tap; existing Import Clients cards display name/phone/email | Real device permission and selection tests |
| Review before saving | Existing selected-contacts review bottom sheet now requires an explicit second Import action for signed-in accounts | Verify cancel never writes and confirm writes only chosen contacts |
| Previous jobs/documents | Client Details keeps all tabs/cards; server returns empty real history until the separate Jobs/Quotes/Documents modules are connected, rather than fake job/document history | Linked history integration in their respective module |
| Separate company data | Every client belongs to `clients.company_id`; `company.access` + `company.permission:clients.read/write`; no client-supplied company ID trusted; guessed foreign IDs fail | Live multi-company and revoked-member test |
| Admin fully manageable | Existing admin UI/sidebar + Client Management: list/search/filter, add/edit, immutable client-company assignment, reversible archive/restore and audit | Hostinger admin acceptance |

## Laravel implementation

- Migration `database/migrations/2026_11_03_300000_create_company_clients_table.php`: company FK, full name, optional company name, email, phone, ISO/dialing code, address, notes, source, timestamps and soft-delete. Existing tables/columns are untouched.
- Model: `App\\Models\\Client`; `Company::clients()` relationship.
- Authenticated API routes (all require `X-Company-Id`, active company membership, explicit read/write permission):
  - `GET /api/v1/clients?q=&page=&per_page=` (paginated search)
  - `POST /api/v1/clients` (manual create)
  - `GET /api/v1/clients/{client}` (actual client and empty historical link arrays; history modules follow)
  - `PATCH /api/v1/clients/{client}` (edit)
  - `DELETE /api/v1/clients/{client}` (soft archive)
  - `POST /api/v1/clients/import` (reviewed batch, 1–100 records, all-or-nothing transaction)
- Existing platform admin panel adds `Client Management` in desktop + mobile menus and uses shared input/card/table/button styles. Administrator routes are protected by existing `auth` + `platform.admin`.
- Focused `tests/Feature/M4ClientManagementTest.php` covers tenant isolation, foreign ID denial, readonly/writeonly membership, suspended/inactive access, import validation, field parity, admin lifecycle, and no admin access for normal users.
- CI: `.github/workflows/m4-client-focused.yml`; PHP 8.4, isolated SQLite and MySQL 8; no production DB touched.

## Flutter implementation

- Remote repository `RemoteClientRepository` implements existing `ClientRepository` interface; the original `DemoClientRepository` remains available when explicitly injected or for unsigned UI previews.
- Existing Clients dashboard uses remote client records when the M2 user is signed in. It reloads after manual save, phone import, edit, and verified company switch.
- Existing Add/Edit Client, Client Details, and Import Clients controls/labels/cards remain intact. The affected client screens now fill usable landscape width but retain portrait styles.
- Native plugin `flutter_contacts: ^2.6.0` is limited to **read** access, with Android `READ_CONTACTS` and iOS `NSContactsUsageDescription`. App never uploads entire address book without review/confirmation and never writes contacts back to the device.
- Device contacts are loaded on demand, not when entering the app. The existing injected demo contacts and UI-only widget tests remain supported.
- Existing client history tabs stay visible, but real clients do not show fabricated demo jobs/quotes/documents. Links come when their respective backend modules are built.

## Stage 1 focused CI verification (passed)

- **Laravel M4:** [GitHub Actions run 41632340553](https://github.com/kamrans262/sign_backend/actions/runs/41632340553) passed client CRUD/import, tenant isolation, permissions, admin management, PHP syntax/Blade, SQLite and MySQL 8.
- **Flutter M4:** [GitHub Actions run 41633538467](https://github.com/kamrans262/sign_app/actions/runs/41633538467) passed package resolution, scoped analyzer and focused client repository, existing Add Client, Client Details, Clients navigation and import UI regressions.
- The prior dashboard test quiescence and lazy menu visibility checks were fixed in **tests only**; no approved UI controls or layout styles were removed.
- These tests use isolated CI/test data and do **not** prove Hostinger migration, SMTP, or Android/iOS phone-contact permission behavior in a real device build.

## Known boundaries

- No phone contacts, Laravel clients, or MySQL production records were read or modified in the connected GitHub-only implementation. Production Hostinger migration must occur after backups and PHP/composer compatibility checks.
- The current app has demo Created Jobs/Quotes/Documents repositories. M4 does not convert those into real company-owned backend records; those modules retain their planned milestones.
- Manual/real device permission UX and store build checks are final Stage 2 gates. Focused CI is not a substitute for physical iPhone and Android verification.
- Branches: `sign_backend/feature/m4-client-management-2026-11-03` and `sign_app/feature/m4-client-management-2026-11-03`. No deployment or main merge.

## Next Stage

**Stage 2 — Testing, Verification & Deployment**: confirm focused branch CI on both sides, inspect production Hostinger PHP >=8.4.1, verified DB+private storage backups, dry-run migration status, deploy only after safe checks, validate live client create/edit/search/import/permission revocation/company switching in admin and on phones/tablets, then record release SHA.
