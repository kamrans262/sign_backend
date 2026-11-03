# Sign2Digital — M8 Digital Signatures Stage 1 engineering handoff

**Date:** 2026-11-04.
**Backend:** kamrans262/sign_backend, branch feature/m8-digital-signatures-2026-11-04.
**Flutter:** kamrans262/sign_app, branch feature/m8-digital-signatures-2026-11-04.

## Boundary and lineage
- Backend forked from **release/m7-preserve-production-2026-11-04** at 664e9084b748e3121d93e7ab628a205322d7735c.
- Flutter forked from M7 at 2c8070a8fa82f66f3e790ea9693f884751b95c0d.
- All M8 code remains in dedicated feature branches; **not** deployed to Hostinger, main or development.
- Existing approved quote PDF is frozen at M7 approval, with private path, revision and SHA-256. Its file remains unchanged by any signature.
- Public M7 quote view remains unchanged when it is not opened through an M8 signing token. The approved document styling is reused for the customer web signing page.

## Implemented Stage 1 behavior
1. Request for signature is authorized by an active tenant and quotes.write permission. It requires an approved PDF, matching revision/hash, approved signer email and non-empty recipient details. Duplicate active or already-signed requests are rejected.
2. The backend issues 256-bit random opaque capabilities, stores only their SHA-256, enforces expiry and records request/view/send/reminder/rotation/revocation events. An owner can rotate a copyable link or send a reminder without ever retrieving an old token. Archive and re-approval changes invalidate pending signing context.
3. Public signing uses a dedicated /sign/{token} browser page containing the original quote layout followed by the existing visual treatment of signature capture (Draw, Type, Upload), explicit consent and a normal CSRF-protected form. The Flutter public signing gateway uses /api/public/signing/{token} and submits to /submit. No customer account is required; the token is a bearer signing capability, **not** high-assurance verified identity.
4. In-person signing uses a separate authenticated API session, two distinct trusted signer IDs and an authenticated business user bound to the session. Both customer and business stroke sets are required.
5. A verified server submission obtains the exact M7 approved source bytes, checks digest and revision again while holding a transactional request lock, imports the original PDF pages with FPDI/FPDF and appends a signature evidence page. The original M7 approved bytes are not edited. The new signed PDF is written privately with a SHA-256-bound path, read back and verified before the server persists final artifact and completion events. Double submissions cannot create a second signed artifact.
6. The signed document and receipt are available to the customer through the original unexpired capability and to authenticated tenant members via /api/v1/signed-documents. Job Details and Documents tab use existing Flutter cards and verified remote data/PDF downloads, rather than demo signing statuses. Downloaded bytes are checked against the server's signed SHA-256. Authorized customer PDF email is attempted after completion; mail failure does not undo signing or pretend delivery succeeded.
7. Admin Digital Signatures is a separate sidebar entry using existing theme and includes search/filter, request details, event history, revoke, resend, signed-PDF verification/download and resend signed PDF.

## API routes
Tenant-protected:
- GET/POST /api/v1/quotes/{quote}/signature-requests
- GET /api/v1/signature-requests/{signature}
- POST /api/v1/signature-requests/{signature}/rotate-link
- POST /api/v1/signature-requests/{signature}/revoke
- POST /api/v1/signature-requests/{signature}/resend
- POST /api/v1/quotes/{quote}/in-person-signing
- POST /api/v1/signature-requests/{signature}/complete-in-person
- GET /api/v1/signed-documents, /{document}, /{document}/pdf
- POST /api/v1/signed-documents/{document}/resend

Public bearer-capability:
- GET /sign/{token}, POST /sign/{token}/submit, GET /sign/{token}/receipt, GET /sign/{token}/signed-pdf
- GET /api/public/signing/{token}, POST /api/public/signing/{token}/submit
- GET /api/public/signing/{token}/pdf, /receipt, /signed-pdf

Admin:
- GET /admin/signatures and /admin/signatures/{signature}
- POST /admin/signatures/{signature}/revoke, /resend, /resend-signed
- GET /admin/signatures/{signature}/signed-pdf

## Schema and dependencies
- Add-only M8 migration 2026_11_04_020000_create_signature_workflow_tables.php: signature_requests, signature_events, signed_document_artifacts.
- Dependencies added and composer.lock resolved: setasign/fpdf 1.9.0 and setasign/fpdi 2.6.9.
- Flutter SHA-256 uses crypto 3.0.7 already resolved in pubspec.lock; direct dependency declared.
- PDF output is an **evidenced electronic signature**: it is NOT a cryptographic PAdES, eIDAS qualified signature or third-party identity certificate. Do not market it as legally certified without independent provider/identity/legal validation.

## Regression verification
- Backend focused CI: M7+M8 PHP lint, Blade compilation, SQLite tests, MySQL 8 tests.
- Backend feature tests cover token hashing/rotation/expiry, authorization, company isolation, real PDF generation from valid fixture, one-time remote and in-person signing, final PDF byte/hash equality, archive revocation, corrupted PDF refusal and admin access.
- Flutter focused CI: analyzer for changed Quote, Documents, Job Details and network files; complete test/features/quotes suite and signed document repository tests.
- **Always inspect the latest GitHub run on each feature head**. Prior passing runs are not proof of a later modified commit.

## Stage 2 release and acceptance gates — NOT DONE
1. Test real M7 Flutter-generated approved PDFs on target Hostinger runtime using exact frozen bytes. FPDI free support is not universal across encrypted/object-stream PDFs; verify compatibility against representative real documents. No fallback to silently regenerated quote bytes.
2. Real Android/iOS and mobile-browser portrait/landscape tests of customer and in-person signing, expiry, browser CSRF and PDF previews; verify every approved UI button/spacing.
3. Intentional test email to a controlled inbox with real SMTP; audit delivery and attach exact signed PDF digest.
4. Backup live MySQL, private PDFs and app configuration. Check production branch/commit and storage visibility, writable permissions, Composer extensions/zlib and available space before migration.
5. Explicit authorized M8 code rollout with additive migrate, scoped smoke tests, read-back, and rollback procedure. Preserve signed artifacts and never run destructive tests on live Hostinger.
6. Do not change main, development or production merely because M8 feature-branch commits exist.
