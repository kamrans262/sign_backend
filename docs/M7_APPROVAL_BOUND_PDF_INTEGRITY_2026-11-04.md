# Sign2Digital M7 — Approval-bound PDF integrity (2026-11-04)

## Scope and intent
The customer's emailed PDF must be the exact PDF authorized when the quote was approved. Previously, `share-email-pdf` accepted a later client-uploaded file with only PDF header/EOF checks; another file could be substituted. The quote workflow, mobile Preview/PDF design, Laravel public web quote view, all company/job/client data, and signing module were kept intact.

## Implemented contract
1. Updated Flutter creates a PDF from the server-numbered, reviewed quote **before** the approval request. `RemoteQuoteRepository.approve` sends a multipart `POST /api/v1/quotes/{id}/approve` with `confirmed=1`, `expected_revision`, and `file=Sign2Digital_Approved_Quote.pdf`. Approval is still explicit; no automatic delivery.
2. Laravel checks the revision and completeness under the same quote-row lock as approval. A valid bounded PDF is stored under the private **local** disk, `storage/app/private/quotes/approved/{company}/{id}/{revision}/{sha256}.pdf`; hash, path and approved revision are recorded on the quote.
3. Add-only migration `2026_11_04_010000_add_approved_pdf_integrity_to_service_quotes.php` adds nullable `approved_pdf_path`, `approved_pdf_sha256`, `approved_pdf_revision` columns. No existing column or row is deleted or rewritten. No API keys, environment settings or Composer dependencies are added.
4. Laravel's `share-email-pdf` endpoint may accept the existing legacy `file` multipart field for compatibility, **but never attaches that later upload**. It retrieves the private approval-bound bytes, verifies SHA-256 and exact revision, then attaches those stored bytes. A corrupted or missing artifact fails closed with 503. An approved quote without a bound artifact fails with 409 instead of emailing an unverified PDF.
5. Old and admin-approved quotes without approval-bound PDF remain approved. They can still share an expiring secure link; PDF attachment email is unavailable unless there is a trusted approved artifact. This is a deliberate security compatibility restriction; do not silently substitute an uploaded attachment.
6. Flutter's existing PDF download and Quote Preview UI remain unchanged. Current Send Quote still prepares a local PDF for preview and may transmit a legacy multipart payload, but the server attachment is the frozen approval-time version.
7. No storage path or SHA is published as an unauthenticated URL. The quote JSON contains only a new boolean `approved_pdf_available` for future UIs.
8. Source-level regression tests cover invalid approval PDFs, private hash/revision storage, unchanged artifact after a different later client upload, corruption failure, legacy quote 409/secure-link fallback, and Flutter multipart approval.

## Critical security distinction / remaining sign-off
Approval-time freezing **guarantees the emailed bytes are unchanged from what the authenticated approver submitted at approval**. It does not independently prove that an arbitrary malicious PDF submitted by a compromised authorized client has the same visible text as the structured quote. For adversarial semantic equivalence, deploy a canonical server-side renderer (or independently validate rendered text) in a later controlled change. Never claim that a PDF magic-number check alone verifies content.

## Release and compatibility gates
- Run both M7 focused CI workflows on Flutter and Laravel; check PHP lint, view compile, SQLite and MySQL tests, Flutter analyze/widget and PDF tests. Track current results, not historical runs.
- **Do not deploy backend before updating the Flutter app**, or new attachments cannot be frozen from the old approve JSON request; coordinated release required.
- The backend migration must be applied to a backup-verified database before traffic on M7 is switched; no migration has been executed in production in this work.
- The local private storage root must exist and be writable on Hostinger. Verify no files are accessible via the web root, include private artifacts in backups, and maintain write/DB rollback.
- M6 already-approved records have null artifact metadata; keep their secure link operational, do not pretend their old uploaded PDF is approved.
- Real SMTP, live device flows, signed link expiry/rotation, and landscape/tablet acceptance remain explicit release checks.
- Only feature branches were changed. Neither M7 draft PR is merged. Hostinger remains untouched.

## Repositories
- Flutter: `kamrans262/sign_app` — `feature/m7-quote-review-approval-2026-11-03`.
- Laravel: `kamrans262/sign_backend` — `feature/m7-quote-review-approval-2026-11-03`.
