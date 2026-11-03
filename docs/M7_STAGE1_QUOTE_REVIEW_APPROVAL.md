# Sign2Digital — M7 Stage 1: Quote Review & Approval

Date: 2026-11-03
Scope source: Original bilingual Sign2Digital Project Scope, French §6 “Vérification & validation du devis” / English §6 “Quote Review & Approval”. Historical module numbering differs: engineering M7 maps to scope §6. Original 74-screen index is unchanged.

## Branch isolation
- Flutter: kamrans262/sign_app, `feature/m7-quote-review-approval-2026-11-03`, branched from the complete M6 feature branch.
- Backend: kamrans262/sign_backend, `feature/m7-quote-review-approval-2026-11-03`, branched from the complete M6 feature branch.
- main/development are not updated. No Hostinger deployment/migration.
- User reports M6 deployed with live testing ongoing; historical M6 handoff says not deployed when written. Confirm actual production commit independently before a release.

## Audited, already present in M6 — REUSE, DO NOT DUPLICATE
- Flutter Review Quote (#20), Quote Preview (#21), Send Quote (#22), job-linked quote list, preserved original style and inputs.
- Flutter `RemoteQuoteRepository`: save, retrieve, server approval, secure-link sharing, email with optional real PDF attachment.
- Laravel tenant-scoped quote CRUD, price/quantity/VAT/deposit validation, integer minor-unit calculations, revision snapshots and audit, approve, email/PDF sharing, signed expiring customer links, link invalidation after archive.
- Laravel admin Quote Management screens for filter, edit drafts, approve, archive/restore, review prices/terms/payment details.
- M6 quote tables `service_quotes`, `service_quote_items`, `service_quote_revisions` and relevant quote permission checks.
- Existing tests: `M6ServiceQuoteManagementTest`, `M6Stage2SecurityAndReleaseTest` and Flutter quote UI/repository/privacy tests. Reuse M6 CI; do not create a duplicate quote backend.

## M7 Stage 1 code changes
1. API approval now re-fetches/locks the tenant-owned quote in a DB transaction and validates current draft, item completeness and totals before changing status. Stops approval/edit races.
2. Approval accepts optional `expected_revision` for backward compatibility. Stale clients receive HTTP 409, not an approval of pricing they have not reviewed.
3. Flutter remote approval submits the revision returned by saved quote response.
4. Added backend regression test for stale revision, correct revision, and repeat approval. Tests are authored but have NOT been run in this environment.

## Requirements traceability and decisions
| Scope / behavior | Implementation | Stage 1 assessment |
| --- | --- | --- |
| AI/manual input then human editing | Existing M6 Review Quote and draft APIs | Reuse |
| No fabricated price/quantity/tax | Existing unresolved-field and amount checks | Reuse, live AI test still pending |
| Editable quote number, work rows, VAT/deposit/terms | Existing Flutter and server persistence | Reuse |
| Server approval requires explicit confirmation | Existing M6 approve; M7 revision guard added | Strengthened |
| Draft revisions and admin visibility | Existing revision tables, audit and admin Quotes | Reuse |
| Preview/PDF after approval | Existing Flutter Preview and PDF | Verify on-device |
| Customer delivery only after approval | Existing link/email/PDF endpoints | Verify live SMTP and secure link |
| Physical signatures/signed document preservation | Next digital-signature module | Not part of M7 |
| No UI redesign | Flutter changes are repository-only; zero widget/layout changes | Preserved |
| Landscape screens full width | Existing known concern | Audit/test in M7 Stage 2; do not restyle elsewhere |

## API contract (additive)
`POST /api/v1/quotes/{quote}/approve`
Request: `{"confirmed": true, "expected_revision": 2}`
- `expected_revision` optional for older M6 clients, REQUIRED from updated Flutter client whenever returned.
- 200: current verified draft approved, new revision returned.
- 409: stale revision or non-draft status; no change.
- 404: cross-company/unknown quote.
- 422: invalid confirmation or incomplete fields.
- Existing tenant, authentication and permission middleware retained.

## Remaining M7 Stage 2 acceptance
- Run Laravel targeted tests and full CI on MySQL 8 + SQLite; Flutter analyze and quote tests; inspect failed/skipped counts.
- Check admin approval concurrency (similar lock/revision policy) before treating approval race hardening as complete.
- Confirm live deployed API version before deploying changes.
- Verify price/rounding/snapshot/display/PDF parity; preview/reopen/edit; immutable approvals and permissions.
- Test real SMTP PDF/secure link, archive/restore invalidation, role revocation, customer links and physical iPhone/Android.
- Verify landscape width for all touched screens while preserving design tokens and original 74-screen navigation.
- M6 AI quota shortage is independent of M7; text/audio/image AI acceptance waits for credits.
- Do not deploy or migrate without release review, backups, and explicit authorization.

## M7 acceptance gate
No claim of CI success, complete quote security or deployment until independent checks run. No OpenAI key is needed for M7 Stage 1.
