# Sign2Digital — Module 7 final engineering handoff

**As of 2026-11-04.** This is the current authoritative M7 implementation and rollout contract. It supersedes earlier Stage 1, Stage 2 and independent-audit status snapshots; the original bilingual scope is not changed.

## Scope (§6 bilingual specification): reviewed quotes and explicit approval
The user reviews the service quote before customer delivery, edits work descriptions/quantities/prices/dimensions, reviews VAT/deposit and totals, checks company terms, and explicitly approves. M7 adds no duplicate screens, controllers or theme components: it reuses the already approved M6 quote flow and the preexisting web/admin UI. Digital signature and verification remain M8.

### Implemented code
- Flutter: Quote Review/Edit, Quote Preview, Send Quote. Source of truth after approval is the original Flutter PDF **frozen at approval** and kept by Laravel. User-provided layout/styling is preserved, with the requested mobile date alignment, work details and deposit/balance parity.
- Laravel mobile API: tenant-scoped quote create/update/review, explicit approval guarded by server-side transaction row lock and optional optimistic `expected_revision`, complete item/terms/prices validation, immutable revision/audit snapshot.
- Laravel admin: existing quote management and review, audited approval/lock, optional existing-style PDF upload at approval, verified admin-only PDF download, archive/restore lock and link rotation.
- Approved PDF: add-only migration `2026_11_04_010000_add_approved_pdf_integrity_to_service_quotes.php`. Approved bytes are stored privately at `storage/app/private/quotes/approved/{company}/{id}/{revision}/{sha256}.pdf`; row stores path, hash and approved revision. No public path is generated.
- Mobile download: authenticated company-scoped `GET /api/v1/quotes/{id}/approved-pdf` returns **exact stored bytes**, verifies SHA-256 and revision, denies cross-company/public requests and sets no-store headers.
- Admin download: authenticated platform-admin `GET /admin/quotes/{id}/approved-pdf`, same SHA/revision validation.
- Email attachment: `POST /api/v1/quotes/{id}/share-email-pdf` attaches ONLY the private approved bytes; later client-uploaded multipart bytes are never emailed. Missing/corrupt/unbound files fail closed (409/503). Link-only email and signed secure links remain separately supported.
- Legacy M6 approvals without stored PDF: **do not silently backfill or send an unverified attachment**. Secure link still works. Flutter local Download and OS manual Share can produce an explicitly labeled unverified copy; backend verified PDF email remains blocked. Admin may attach a reviewed PDF when explicitly approving a new draft.
- No OpenAI API key changes, new package dependencies, replacement schema or destructive file operations. No production deployment or branch merge during M7 engineering.

## API and state
- `POST /api/v1/quotes/{id}/approve`: new Flutter sends multipart `confirmed=1`, `expected_revision` and `file`. Legacy JSON approvals remain accepted but cannot claim a verified PDF.
- `GET /api/v1/quotes/{id}/approved-pdf`: authenticated tenant-scoped binary download, only approved/sent.
- `POST /api/v1/quotes/{id}/share-email-pdf`: accepts existing optional multipart for compatibility but ignores client PDF contents after upload validation; actual attachment is frozen artifact.
- `GET /admin/quotes/{id}/approved-pdf`: platform-admin-only binary download.
- Review/approval status: `draft -> approved -> sent`, with archive/restore and signed-link invalidation on archive. Approved quote monetary fields are no longer editable.
- Private digest binding proves emailed/downloaded bytes are identical to the **document submitted at approval**, but without a second trusted rendering pipeline it does **not** prove arbitrary malicious PDF semantics independently match structured fields. Do not overclaim this.

## Source test acceptance
- M7 Flutter CI: focused Dart analyzer and entire `test/features/quotes` suite; previous passing run: 117 quote tests. Tests cover exact binary download, tenant scoping, invalid response, approval multipart, VAT 8.1% precision, deposit arithmetic, responsive preview, date column and PDF generation.
- M7 Laravel CI: PHP lint, compiled Blade and selected M4–M7 regressions in both in-memory SQLite and MySQL 8. Previous successful run: 40 SQLite tests (460 assertions), 34 MySQL tests (383 assertions). Includes admin attachment tests, cross-company protection, stale approval revision, signed links, approved-PDF authenticity, corruption rejection and legacy link fallback.
- **Always check newest CI runs after commits** before considering the release verified. Mocked mail tests do not establish live SMTP inbox delivery.

## Release gates — still outstanding on actual Hostinger
1. **Do not assume** which backend branch/commit is deployed; obtain read-only `git branch --show-current`, `git rev-parse HEAD`, and `git status --short` from production, without switching branches.
2. Record and verify restorable MySQL snapshot, private storage backup, app/env configuration and rollback paths. Never expose or rotate secrets/APP_KEY for this deployment.
3. Ensure `storage/app/private` is writable, never web-served, and included in backups.
4. Deploy a reviewed Flutter+Laravel compatible pair; M7 mobile approval multipart must reach M7 backend. Backend migration is **additive**; execute only during approved controlled rollout.
5. Run **read-only** `php scripts/m7_release_preflight.php --require-migrated` on the deployed release, and verify routes/DB/private storage; it cannot migrate, backup or deploy.
6. Validate one controlled real approved quote → exact PDF download → verified email attachment → signed link; no production customer emails without an intentional test recipient.
7. Verify app device landscape/layout as requested. Roll back application code if necessary while retaining the additive nullable columns and approved private files; do not delete historical records.
8. No merge into `main` or `development` or Hostinger changes are implied by feature-branch commits; production release is a separate authorized operation.

## Code branches
- Flutter: `kamrans262/sign_app` — `feature/m7-quote-review-approval-2026-11-03`
- Laravel: `kamrans262/sign_backend` — `feature/m7-quote-review-approval-2026-11-03`
- Draft verification PRs remain unmerged: Flutter #12, Laravel #1.

**Decision:** M7 engineering implementation is complete on dedicated feature branches. Production acceptance/deployment is not yet performed; do not describe M7 as live without the release gates above.
