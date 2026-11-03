# Sign2Digital — M5 Stage 2: Final Testing & Release Readiness

**Date:** 2026-11-03  
**Module:** M5 — Job Management  
**Two-stage delivery:** Stage 1 implementation, Stage 2 testing/security/release gate  
**Deploy decision:** NO PRODUCTION WRITE in M5 Stage 2. Previous M3/M4 live deployment status is not independently verified. A later consolidated M3+M4+M5+future-modules deployment requires an explicit user-approved release.

## Source integrity and inheritance

| Repo | Stage 2 working branch | Source inherited from |
| --- | --- | --- |
| Flutter | `kamrans262/sign_app`, `feature/m5-job-management-2026-11-03` | `feature/m4-client-management-2026-11-03` |
| Laravel | `kamrans262/sign_backend`, `feature/m5-job-management-2026-11-03` | `feature/m4-client-management-2026-11-03` |

GitHub compare verified both branches are ahead of M4 and **behind by 0**. No earlier M3/M4 changes need to be cherry-picked or reimplemented, and `main` is not used as a release source.

## Verified CI evidence

| Check | Evidence | Status |
| --- | --- | --- |
| M5 Laravel PHP syntax, Blade compilation, M4+M5 tenant regression and Stage 2 security tests on SQLite/MySQL 8 | [Backend CI run 41662410816](https://github.com/kamrans262/sign_backend/actions/runs/41662410816) | PASS |
| M5 Flutter focused analyzer, repository/UI/navigation regression and new Stage 2 tests | [Flutter CI run 41661984557](https://github.com/kamrans262/sign_app/actions/runs/41661984557) | PASS |
| Native Android debug APK compilation (no signing/publishing) | [Mobile build run 41661270228](https://github.com/kamrans262/sign_app/actions/runs/41661270228), Android job | PASS |
| iOS Simulator debug compilation without signing/publishing | [Mobile build run 41661270228](https://github.com/kamrans262/sign_app/actions/runs/41661270228), iOS job | PASS compile step |
| Actual Android physical phone and iPhone end-to-end acceptance | Requires connected devices, real authenticated test accounts and deployed compatible API | NOT EXECUTED |
| Real Hostinger PHP/DB/migrations/SMTP, production uploads and recovery backups | Requires live host inspection and operator access | NOT VERIFIED |

The native build workflow began at Flutter commit `f57676f8b2a831bb0d9a5660cdee5b899dad8d75`. The later focused Flutter test run includes the final local-note isolation fix; its analyzer/tests pass. Native builds must be rechecked at the final release SHA if any future platform/dependency changes occur.

## M5 implemented behaviour validated

- Laravel uses `service_jobs` and `service_job_user`, never overwrites the framework's `jobs` queue table.
- Job create/list/detail/update/archive/restore and admin management use the original approved Flutter/admin visual language.
- Job title defaults to first description line when the unchanged mobile Create Job form does not provide a title. Updates cannot save blank title.
- Server assigns company, creator, reference and initial progress (0%) at creation; a spoofed mobile request cannot set another tenant or fake completion.
- Clients and team assignments require current same-company records and active membership; archived clients cannot be assigned.
- Members need `jobs.read` or `jobs.write`; permission revocation is effective without stale cached access. Unknown or foreign job IDs are not distinguishable.
- Job lifecycle covers planning, in-progress, effective overdue, completed, archive and restore. Repeating completion updates preserves the original completed-at timestamp.
- Audit logs are emitted for creation/edit/archive/restore and existing admin management is included in the actual `Job Management` sidebar.
- Authenticated Jobs tab uses saved API records. Client Detail counts/history are backed by related records, not screenshot examples. Tenant switches invalidate stale responses.
- Job budget preserves 2 decimal places on live UI; null means **Budget not set**, not invented CHF 0.
- All original Jobs, Create Job, Details, photo/note screens and section cards remain; landscape full-width handling is improved in Jobs and Create Job.
- Photos and notes currently persist only as local/session drafts and are separated by active company. They do not claim remote upload.
- Quote, addenda, documents, expenses, labour, reports, deposits, invoice and acceptance backend data remain dependent on their future modules; empty/unsupported sections never fabricate records or amounts.

## One-time mobile acceptance matrix (real devices required)

Perform on **Android and iPhone**, portrait and landscape, against a backend that contains the same approved M3+M4+M5 schema:

1. Launch, log in using email OTP, and verify a new user cannot enter Home until company creation succeeds.
2. Create or switch between two companies with separate clients. Confirm jobs, counts, search, notes and photos from Company A never appear under Company B.
3. Create one real client, then Create Job both via Home and Client Details. Verify all original fields, AI voice button UI, date picker, CHF decimal value, company member selection and validations.
4. Open Jobs Active, Completed and Archived tabs, search by job/client/reference, open Job Details, edit address/dates/description/CHF/team and verify server readback survives relaunch.
5. Start, complete, reopen, archive and restore a job; confirm status and progress correspond with server state and client history remains intact.
6. Verify read-only team members cannot write jobs and revoked/foreign users cannot read job IDs or history.
7. Open quote, addenda, document, photos, notes and other existing section cards; confirm accurate future-module placeholders, no fabricated financial data, no cross-company local files and no unrelated example uploads.
8. Confirm app header/back navigation, fixed bottom navigation, keyboard overlays, search, dropdown sheets, orientations and no render overflow.
9. Open admin `Job Management`: search/filter by company and status, create/edit, invalid foreign client/team rejection, archive and restore without altering company ownership.
10. Verify logout/relogin, offline network errors and retry. Ensure no tenant data is silently replaced with demo data.

**Do not mark device acceptance PASS until a human has performed and recorded these steps.** Building an unsigned simulator or debug APK is not evidence of physical device verification.

## Read-only server inventory (future release window)

Run this **only when an authorized operator has host access**. It does not deploy or migrate:

```sh
ssh -p 65002 u777477188@147.93.42.143 'cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && php -v | head -n 1 && git branch --show-current && git rev-parse HEAD && git status --short && php artisan migrate:status && php artisan route:list --path=api'
```

After deploying the exact reviewed code in a future approved release, run `php scripts/m5_release_preflight.php` as a **read-only** check. It verifies PHP >= 8.4.1, production settings, HTTPS, MySQL connectivity, routes, authorization middleware and M3/M4/M5 schema presence. Before migrations, pending schemas are informational. After a separately approved, backed-up migration use `php scripts/m5_release_preflight.php --require-migrated` to insist all schemas are present. No secret values are displayed.

## Combined future deployment: blocking conditions

- Obtain user's explicit approval for a production release and pin the final consolidated Flutter and backend SHAs; do not assume M3/M4 have already deployed.
- Inspect the *actual* hosting branch, PHP 8.4.1+ runtime, Composer locked dependencies, website root, existing Laravel migrations, MySQL version/identity, SMTP, private file storage and web health. Don't print `.env` secrets.
- Independently back up the **live** MySQL schema+data, private uploads/storage and release-relevant configuration. Check integrity and recovery procedure; the project ZIP is not a database backup.
- Confirm migration chronology: M3 `2026_11_03_200000`, M4 `2026_11_03_300000`, M5 `2026_11_03_400000`, plus other dependencies as declared in the real migration table. NEVER use `migrate:fresh` or regenerate `APP_KEY`.
- Only then, with approval, deploy the reviewed consolidated backend, run its migrations and post-deploy read-only preflight; test email OTP, admin, company switching and job CRUD on real devices.
- Keep a schema/data rollback plan, previous Git SHA, private uploads restore location and signed-off release owner. No release is authorized by this document.

## Honest release status

**M5 Stage 2 code/CI verification: PASS. Physical device acceptance: PENDING. Production migration/backup/Hostinger state: NOT VERIFIED. Deployment: NOT PERFORMED BY THIS STAGE.** Core M5 work is ready for later integration; it is not a statement that unrelated downstream modules are already fully backend-connected.
