# Sign2Digital M19 — Equipment Loan Management

**Status:** PREPARED ONLY. This commit adds a plan, not M19 code, migrations, or deployment.
**Date:** 2026-11-05
**Shared development branch in BOTH repositories:** feature/m12-site-reports-meeting-minutes-2026-11-04

## 1. Correct module, exact scope and screen contract

The original bilingual project scope §17 (Equipment Loan Management / Gestion du prêt de matériel) covers equipment lent to clients **or other people**, its borrower, checkout date, expected return date, status, full lending history, overdue reminders, and optional voice-assisted loan creation.

Original screens Q.60–62:
- **60 Equipment Loans List:** Active / Returned / Overdue tabs. Cards display equipment, borrower, loan date, return-due date and actual status.
- **61 Create Equipment Loan:** choose equipment/item and borrower, checkout date, expected return, notes; manual or voice entry.
- **62 Equipment Loan Details:** equipment, borrower, dates/status, history; Mark Returned, Extend Return Date, Send Reminder.

**Current numbering:** M16 Deposits, M17 Invoices, M18 General AI Documents, **M19 Equipment Loan Management**. Ignore obsolete legacy module numbering that labels invoicing M19 and equipment loans M21. The next planned module is M20 Reminders & Notifications; M19 should prepare its equipment-return integration, not implement the entire M20 module.

**Important distinction:** The approved existing Flutter Equipment feature is an **asset inventory/usage/maintenance module**, not an equipment-loan ledger. Keep both domains separate. Do not overwrite or relabel the existing Equipment asset screens just to meet the original loan-screen specification.

## 2. Live repository audit, November 5, 2026

- Backend kamrans262/sign_backend, branch HEAD **f1052b0ade829a904e75ee817a08b6a869414b04** after M18 Stage 2 CI. No complete persistent equipment-loan backend or equipment Admin module yet.
- Flutter kamrans262/sign_app, branch HEAD **66f114642a53375e0db3122e0d10d89ae7a64b55**, M18 Stage 2 green.
- Existing Flutter asset screens: lib/features/equipment/presentation/screens/equipment_screen.dart (asset list, tabs All/In Use/Available/Maintenance, search, existing reusable cards); add_equipment_screen.dart (asset code, name, category, brand/model, purchase date, current location/status, photos, notes and AI voice); equipment_details_screen.dart (asset edit, usage, maintenance, history).
- Existing domain lib/features/equipment/domain/equipment_record.dart: EquipmentRecord, EquipmentStatus.available|inUse|maintenance, usage, maintenance, events and EquipmentRepository methods listEquipment/saveEquipment/updateEquipment/logUsage/scheduleMaintenance.
- Existing lib/features/equipment/data/demo_equipment_repository.dart is session-only; sample Mini Excavator must NOT be mixed with real company assets after authentication. Preserve demo/injection behavior for signed-out approved UI previews.
- Existing lib/features/equipment/services/equipment_voice_extraction_service.dart prepares suggestions through a temporary client API-key path; real M19 recording must use Laravel-owned credentials and existing AiVoiceFlowDialog, without device-stored OpenAI secrets.
- Original Flutter test/features/equipment/equipment_module_test.dart tests asset listing, add, usage, maintenance and responsive views. Keep passing.
- Future M20 reminder Flutter data is currently demo-only, with ReminderType.invoice|job|client|document|other. M19 introduces loan-return reminder SOURCE data and an adapter; it must not falsely claim all M20 notification delivery/push is live.
- Reuse current backend auth:sanctum, active.user, company.access, company.permission, AuditLogger, transactions, private file storage, Admin layout and existing client/job models. Add appropriate equipment.read / equipment.write permissions in the existing permission scheme without granting broad unrelated access.

## 3. Nonnegotiable UI, branch and safety rules

1. Continue working only on the existing shared development branch in BOTH Laravel and Flutter repositories, commit tested code, no production deployment or Hostinger commands, no main/development modifications.
2. Preserve existing exact visual language: Inter, #166282 primary, #122536 dark, #667085 secondary, #8A9AA8 placeholder, #D9E2EA border, #E3ECF0 icon-circle, 10px radius and 20px horizontal padding. Use existing cards, tabs, modal sheets, headers, photo previews, voice popup, common buttons and Admin sidebar design; no UI redesign, no duplicate global navigation.
3. Enforce company isolation on every asset, loan, usage, maintenance, photo, borrower-linked client/job, history and reminder. Discard stale responses after Flutter active-company switching. No cross-company identity leakage.
4. Asset operational state **Available / In Use / Maintenance** is distinct from loan lifecycle **Active / Returned**, with **Overdue computed** from active state and local date. Do not store an overlapping permanent overdue flag or erase old loans when the equipment state changes.
5. No AI auto-loan issuance, reminder sending or invented borrower/dates. Manual workflows must be fully functional when OpenAI API is unconfigured or out of credits.

## 4. Stage 1 — Full asset persistence and functional Equipment Loan Management

**Goal:** Laravel backend + APIs + fully manageable Admin + existing Flutter Equipment asset UI + original three loan screens, committed with green automated tests. No production deployment.

### Schema and business rules
1. Persist equipment_assets, company_id, unique per-company asset code, name, category, brand, model, purchase date, status, location, notes, hours/meter, version/lock, archived_at, creators and timestamps. Do NOT remove existing asset usage/maintenance/photos.
2. Add equipment_usage_entries, equipment_maintenance_entries, private equipment_asset_photos (content-type/size/SHA-256), and append-only equipment_asset_events. Existing Equipment Details actions must save through Laravel instead of memory. An asset cannot be archived while actively lent.
3. Add equipment_loans keyed by company and real asset, borrower_type (linked_client or external), optional same-company client_id and job_id, mandatory borrower display-name snapshot, optional email/contact, loan_date, expected_return_date, optional returned_date/returned_at and returned_by, notes, status active|returned, lock_version, idempotency key and actor/timestamps.
4. Add append-only equipment_loan_events recording issue, extension old/new dates, edits, return, reminder preparation/handoff and actors. No destructive history update; current borrower name is snapshot-stable even if client's profile changes.
5. For a registered, individually tracked asset allow **at most one active loan** at any time. Lock the asset row inside every create/return/edit transaction and validate existing active loan, availability and maintenance. Add supporting DB constraints/indices. Cross-device simultaneous checkout and retries must never create two active loans. Prevent active-loan deletion/asset archival.
6. Dates are company-local calendar dates, not device UTC timestamps. Expected return >= loan date; return/extension must validate chronological rules. Overdue is a derived active-loan view when the due calendar date has passed in company timezone. Returning and extending update overdue results immediately.
7. A borrower can be an existing same-company M4 client OR an outside person with a name. Do not force creation of a fake client account. If a job and client are linked, verify the job belongs to that client/company. Require explicitly supplied contact before any later email.
8. Every company-scoped write accepts version and/or retry guards as appropriate; duplicate identical retries return the same canonical outcome, changed-payload reuse and stale edits fail closed (409). No unbounded strings/files. Use explicit short MySQL FK/index names <=64 characters and a valid reverse down().

### API and service implementation (names are proposals; reconcile with middleware)
9. Equipment: GET/POST /api/v1/equipment; GET/PATCH /api/v1/equipment/{id}; POST asset photo/usage/maintenance; GET audit/photo/download, loan history, and current borrowing summary. Status and asset details are genuine server data.
10. Loans: GET/POST /api/v1/equipment-loans (active, returned, overdue, borrower, asset, client/job filters and pagination); GET/PATCH /api/v1/equipment-loans/{id}; POST .../{id}/extend; POST .../{id}/return; GET .../{id}/events. Use one central EquipmentLoanService for API and Admin, transaction/locking for all mutations, no demo write fallback.
11. Permission matrix: company owner/admin, explicitly granted equipment.read and equipment.write; separate privilege for audit/reminder operations where needed. Unauthenticated 401, lack of grant 403, another-company resource 404, stale/overlapping loan 409, invalid form data 422. Rate-limit loan issuance, voice, file upload and email endpoints. Responses use existing JSON data wrapper and no-store.
12. Voice: opt-in server-owned transcription/extraction endpoint returning nullable **draft suggestion** for asset, borrower, loan/due date, notes. Human confirms every field before saving; preserve typed data on cancellation/provider 429. Reuse approved AiVoiceFlowDialog UI. Manual flow fully independent of OpenAI funding.

### Admin integration (fully manageable, not placeholders)
13. Add a properly integrated Equipment & Loans module in existing Admin layout: asset list/search/create/update/archive, asset usage/maintenance/photo/history; loan list with active/returned/overdue filters, create, edit allowed fields, extend, return, view events and linked same-company borrower/job. Reuse admin components, consistent module naming and one sidebar icon.
14. Admin actions use the same service and authorization invariants as Flutter/API; no direct-status edit that skips history. Display current loan, overdue totals and return events accurately. Audit all operator changes, make finance/Docs/Quotes unaffected.
15. A visible Send Reminder control may prepare a tracked manual reminder but must not report "sent" until a real transport exists. Full delivery is Stage 2.

### Flutter integration
16. Preserve the three existing asset UI screens and their navigation, cards, responsive behavior, photo controls, usage/maintenance tabs and voice entry. Implement RemoteEquipmentRepository for authenticated sessions matching existing EquipmentRepository; do not show sample EQP-0001 as company data.
17. Add the missing original loan screens 60–62 inside lib/features/equipment/ using **existing components and style**, not a redesign or second unrelated equipment module: Equipment Loans List (Active/Returned/Overdue), Create Loan (manual/voice, borrower/client picker, asset picker, loan/due dates, notes), Loan Details (history, Return, Extend Return Date and honest reminder availability).
18. Link asset details to its current loan and historical loans; do not conflate asset in-use/maintenance state and loan overdue status. Keep existing asset editing, usage logging, maintenance and photos working.
19. Handle auth/permission denial, empty/loading/error/retry states, pagination, active-company race, duplicate submits and version conflicts. After a successful return/extension refresh current asset and loan lists; do not leave stale "overdue" or "available" labels.

### Stage 1 acceptance and CI
20. Laravel tests: company isolation, client/external borrowers, client/job mismatch, asset unique code, maintenance block, active double-loan concurrency, optimistic lock, duplicate retry, date validation/overdue boundary, extension old/new event, return exactly once, photos/hash, usage and maintenance persistence, admin role parity and audit. Fresh SQLite and **disposable MySQL 8.4** migrations + FK/index checks.
21. Flutter tests: original asset UI screens unchanged, real repository contract, loan list/create/detail including return/extend, date and borrower pickers, voice failure/manual fallback, 320px phone and landscape, company switch, no fixtures when logged in; run M1–M18 regression checks. Correct test bugs without skipping real behavior checks.
**Stage 1 exit:** inventory persisted; real loans listed, created, extended, returned with immutable events; full Admin and Flutter wiring; all targeted and previous-module CI green; no deployment.

## 5. Stage 2 — Overdue reminder delivery, operational reliability, release checks

1. Build company-timezone overdue checker and per-loan reminder policy (user-controlled opt-in). Create equipment_loan_reminders / delivery_attempts records (or a reusable shared reminder source abstraction) with due-date version binding, actor, recipient, audit and states pending/submitted/failed/unknown/cancelled.
2. The original Loan Details **Send Reminder** action: preview, explicit human approval, transport-configured email where borrower email is present and authorized, or real in-app reminder where supported. Never equate mailer queue acceptance with recipient inbox delivery; no fake notification.
3. Optional automated overdue notifications run only with business opt-in and scheduler/queue configured, with at-most-once logical dispatch per asset/loan/due-date revision/interval. Do not send to real customers during development or testing. Cancel/deduplicate pending overdue notices when a loan is returned, extended, revoked or borrower/contact changed; protect send/return races.
4. Establish a clean M20 Reminders adapter for equipment-return items and future ReminderType.equipment without implementing unrelated payments, job follow-ups, push provider, preferences or complete M20 UI in M19. Do not silently enable OS push or claim scheduled delivery when infrastructure is absent.
5. Complete retry-safe transport and event history, email failure/uncertain-outcome reconciliation, updated asset/loan screen affordances, accessibility and viewports, admin workflows and company-permission matrix. No visual redesign.
6. CI: fresh MySQL 8.4 schema + FK/index checks (catch 64-character automatic names), race tests, late return/extend vs queued email, duplicate dispatch and opt-out tests, timezones/day boundaries, private photos/hashes, malformed/external borrower validation and M1–M18 regressions. Commit both repos only after green CI.
7. Real OpenAI/SMTP acceptance only with legitimate configured service and test recipients. Manual lending and offline-error handling must still work without either. No Hostinger production migrations, live emails or deployment without explicit user permission.
**Stage 2 exit:** borrower follow-ups are accurate, explicitly controlled, auditable and retry-safe; complete equipment/loan UI and Admin preserve prior styles and data; CI/MySQL green; separately authorize production release.

## 6. Acceptance examples

1. Register excavator EQ-001. Lend to a same-company client; loan appears Active, and asset detail shows that current loan.
2. Concurrent second checkout of EQ-001: 409 conflict and exactly one active loan, even if different staff members submit.
3. Lend a registered asset to an external person without creating a company Client account; preserve snapshot name/contact.
4. After due date, active loan appears Overdue; extend the date to future => immediately Active, with old/new date event.
5. Mark Returned => Returned and no overdue; second return with conflicting details => 409 and no duplicate events.
6. Set an asset to Maintenance => new checkout denied, prior history preserved. Attempt to archive an actively lent asset => denied.
7. Company A member cannot inspect Company B asset, borrower, loan, photos or events; active-company switch drops stale mobile responses.
8. User chooses Send Reminder: preview/confirm, correct contact, audit entry and no duplicate dispatch. Return or extend cancels stale pending reminders.
9. Server AI quota exhausted => no fabricated voice loan, manual create still works; never ship OpenAI API key in Flutter.
10. M1–M18 UI, backend and data remain intact, including current Equipment usage/maintenance/photo features.

## 7. Exclusions and starting instruction

- No Stripe, subscription changes, charges, invoice, deposit, signed general-document PDF, quote signature or public loan-agreement portal required by original M19 scope.
- Do not merge with future M20 general Reminders; implement only equipment-overdue-specific capability and a compatible reference seam.
- No new branches, no main/development changes, no production database migrations, real customer emails, secrets or deployment.

**Next step:** Start Sign2Digital M19 Stage 1 — Equipment Loan Management. Follow docs/M19_EQUIPMENT_LOAN_MANAGEMENT_PREPARED_PLAN_2026-11-05.md, use the existing exact shared Flutter/Laravel branches, implement persistent Equipment inventory plus company-isolated loan backend/APIs, fully manageable Admin, original Equipment and Loan mobile screens, manual and voice-suggestion entry, return/extend/history, preserve all established UI, run and fix tests including disposable MySQL CI, commit both repos, and do not deploy.
