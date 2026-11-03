# Sign2Digital M8 — Stage 1 implementation status

Date: 2026-11-04. Feature branch: feature/m8-digital-signatures-2026-11-04.
**Engineering in progress. Do not deploy these changes or represent customer signing as complete.**

## Preserved starting points
- Backend from release/m7-preserve-production-2026-11-04 at 664e9084b748e3121d93e7ab628a205322d7735c.
- Flutter from feature/m7-quote-review-approval-2026-11-03 at 2c8070a8fa82f66f3e790ea9693f884751b95c0d.
- M7 frozen approved PDF bytes, SHA-256 and approval revision remain source of truth.
- No changes to main/development/Hostinger and no change to the existing M7 public quote layout.

## Committed M8 foundation
- Additive schema: signature_requests, signature_events, signed_document_artifacts.
- Server-generated 256-bit opaque token; database keeps only SHA-256; expires within 1–7 days.
- One active request per approved quote, company-scoped authorization, validated customer email, quote revision and digest binding, archive/link-version invalidation.
- Owner request/create/list/show/revoke/resend APIs with explicitly controlled delivery; server audit events.
- Public JSON signing-session API, read-only customer browser quote view, and exact approved-PDF access using the token.
- Admin Digital Signatures sidebar, filtering, inspection, activity and authorized revocation.
- Flutter API repository and existing Signature Request screen integration; public Flutter gateway defaults to deployed HTTPS base; no new design system.
- Isolated M8 CI checks for PHP/Blade/SQLite/MySQL and existing Flutter quote/signature regression suite.

## Security boundary
**M8 does not yet accept signatures or issue signed-PDF completion receipts.**
The public signing submission route is intentionally **not implemented/registered**. The customer-browser page is currently read-only. Do not simulate completion or mark a quote signed on local capture. The signed_document_artifacts table is a future immutable destination, not proof of a signed file.

## Remaining Stage 1 delivery
1. Audit and select trustworthy PDF signing/compositing engine compatible with Laravel 13 and Hostinger. Maintain correct composer.lock provenance and CI deployment.
2. Implement full remote browser signer UI using the existing approved quote spacing/style, draw/type/upload capture as approved, explicit consent and signer validation.
3. Build transaction-safe, replay-safe signature acceptance and in-person signatures with distinct authorized signer IDs, actual immutable signed PDF, source/destination hashes, and evidence.
4. Signed PDF secure downloads/delivery to parties, document/job history association, and administrator signed-file access.
5. Add negative tests (identity, race, expiration, replay, digest tamper, tenant violations), live mail smoke test, device and landscape acceptance.

## Release gates
Complete M8 Stage 1 code and pass GitHub CI before entering Stage 2. Run production backups and additive migration only during a separately authorized Hostinger deployment. The existing M7 backend remains live at the user-confirmed commit 934eae5; its M7 device/email acceptance is not automatically satisfied here.
