# Sign2Digital — Independent M1–M7 Scope and Source Re-Audit

**Audited:** 2026-11-04 (Asia/Karachi). **Verdict:** M7 implementation improved; production release **BLOCKED** pending verified CI, integration and security acceptance.

## Authoritative references and precedence
1. User-provided `Sign2Digital – Project Scope.docx` (original bilingual French/English); section 5 is AI quote creation, section 6 is quote review and approval, section 7 is digital signature. Original UX inventory lists 74 screens.
2. `Sign2Digital_COMPLETE_M1-M4_HANDOFF_2026-11-03` ZIP: current two-stage module process, original screen mapping and release protections. Historical four-stage and 25-module maps are not current engineering-module numbering.
3. `Sign2Digital_M6_RECOVERED_HANDOFF_2026-11-03` ZIP: reported M6 implementation and *historical* not-deployed state. User subsequently reported M6 deployed, testing underway, with OpenAI API credits pending. Actual deployed commit and live acceptance have **not** been verified from the server.
4. Both live authenticated GitHub repositories on `feature/m7-quote-review-approval-2026-11-03`. They are based on complete M6 ancestors, not the older `main` baseline.

## Current sequential engineering modules (source-verified existence, not universal production acceptance)
| Module | Implementation trace | Remaining |
|---|---|---|
| M1 — Foundation/Admin | Laravel auth/admin foundation, middleware, tests, migrations, admin UI | Production/version read-back |
| M2 — Mobile Auth | Email OTP, account APIs, Flutter company entry gate and tests | Physical-device and SMTP acceptance |
| M3 — Companies | Company creation, switch, member roles/invites, upload/terms | Hostinger/permissions and device acceptance |
| M4 — Clients | Tenant-scoped client CRUD/import/admin with focused tests | Real contacts permissions and device acceptance |
| M5 — Jobs | Job CRUD/links/admin, tests, downstream relationships | User deferred physical-device acceptance |
| M6 — AI Quote Creation | Text/voice/handwritten-photo AI preparation, quote draft, PDF/share/admin implemented in source | API credits/live AI, SMTP and physical acceptance |
| M7 — Quote Review & Approval | Review/edit, approval, revision history, tenant checks, preview and send reused from M6; additional hardening in M7 | Tests and release blockers below |

Original scope has **22 functional sections and 74 named screens**; historical `25 modules` is an older development breakdown. M8/signing remains separate and must not be claimed complete because signing UI exists.

## Original bilingual scope §6 traceability
| Requirement | Code evidence / state |
|---|---|
| Review AI-generated information | Existing Flutter Create/Review Quote flow; source implemented |
| Edit text, work, price, quantities, dimensions | Existing Review Quote, draft API; source implemented |
| Add/remove items | Existing Review Quote action cards; source implemented |
| Review VAT and deposit | Flutter review UI, integer basis-points backend; fractional-rate loss fixed here |
| Verify totals | Server-calculated minor-unit amounts, Flutter preview; integration parity pending |
| Attach company Terms & Conditions | Existing editable text terms, and M3 company terms file storage; automatic file attachment/reuse not proven; test/fill if required |
| Explicit quote approval before sending | Server-approved status required for both email/link/PDF endpoints; strengthened transactional revision guards |
| Final customer-visible quote exactly as approved | **NOT guaranteed for arbitrary client-uploaded PDF**; see release blocker below |

## Re-audit corrections committed, no redesign
**Laravel**
- M7 admin approval: transaction + row lock + reviewed `expected_revision`, verify nonempty items and resolved prices before approved status, append audit/revision only upon success.
- Mobile approval: transaction + row lock + optional `expected_revision` for legacy compatibility; updated Flutter sends value.
- Mobile archive and admin archive/restore: row locks preserve the actual status across approval/archive overlap and rotate signed-link version on archive.
- Link-only email now rejects `mail.default='null'` (in addition to `log` and `array`) so silently discarded mail cannot be reported as Sent.
- M7 backend CI configuration fixed: **both SQLite and MySQL filters now include `M7QuoteApprovalTest`**; earlier configuration only linted it.
- M7 admin regression now updates draft through the real admin endpoint instead of directly mutating revision. Archive/restore and null-mailer regression cases added.

**Flutter**
- `quotePercentText` formats integer basis-point VAT/deposit without losing 8.1% or 8.25%; used in review fields, summary, preview and generated PDF.
- Percentage editing uses a decimal-enabled keyboard to permit fractional VAT on iOS/Android.
- Regression test checks VAT/deposit input round-trip, expected half-up minor-unit tax; CI analyzer includes changed PDF, preview and test files.
- Quote approval posts the saved server revision. No existing fields, screens, navigation or layout components were removed or visually redesigned.

## Remaining issues before release

### P0 — Attached PDF provenance/content mismatch
`POST /api/v1/quotes/{quote}/share-email-pdf` validates an approved quote and basic `%PDF-`/`%%EOF` structure, **but does not verify that the client-supplied bytes are the exact PDF for the approved revision.** A caller could attach different contents. The existing Flutter PDF is built from its local quote model, not a server-signed canonical approved PDF. This does not satisfy a strict immutable approved-content guarantee. Resolution should produce/store a canonical server-verified PDF for the approved revision or otherwise cryptographically bind the exact approved document bytes with robust content validation, retaining the approved Flutter layout. Do not treat the current upload check as document equivalence proof.

### P0 — CI/live acceptance not verified
New M7 workflow changes have been committed but no confirmed passing GitHub Actions run is available to this audit. No local full Flutter toolchain or Laravel vendor tree was available to execute the actual test suites. A public health URL was not accessible through the verification tool; this is **not** evidence of server downtime. Do not claim tests passed or production deployed from this audit alone. Before release check real Hostinger branch/SHA, schema, env, SMTP, DB connection, backups and rollback.

### P1 — Other remaining verification
- Existing `maxWidth: 900` wrappers in Review Quote and Quote Preview center content on wide landscape/tablet screens. This audit preserved visual layout; full-width adaptive layout and physical-device overflow checks remain.
- Company terms can be entered in quote text; automatic re-use/attachment of M3 uploaded terms file is unverified.
- Signed view, signed-PDF, addenda, customer signing are M8+; current demo views do not establish deployed signing.
- Older M6 mobile/admin approval requests that omit optional `expected_revision` remain backward-compatible but do not get the stale-review comparison. Confirm rollout policy before claiming universal concurrency protection.
- Mail submission success signifies acceptance by the configured transport, **not** customer inbox delivery. Test actual SMTP receipts and bounce handling separately.
- Email-send versus archive concurrency still requires an explicit behavior/atomicity policy, especially if archiving occurs while SMTP processing is underway.
- M6 live voice/text/image API tests and M5 device acceptance are pending independently of M7.

## Release / test gates (do not execute in production without approval)
- Backend M7 focused workflow: PHP lint/Blade, M4–M7 selected tests in SQLite/MySQL 8, including `M7QuoteApprovalTest`.
- Flutter M7 focused workflow: analyze changed quote, preview, PDF and repository source; full `test/features/quotes` tests.
- Manual flows on updated Flutter: edit a quote at 8.1% VAT, save without touching VAT, reopen, compare API amount to preview and attached PDF; stale revision returns 409; archive/restore invalidates old link.
- Physical Android/iOS, portrait/landscape/tablet, real SMTP, secured signed URL validity, PDF identity, switching tenants/logging out.
- Maintain M7 separate branches; do not merge `main`/`development`, deploy to Hostinger, run migrations, rotate APP_KEY, or overwrite local worktrees until explicit approval and verifiable release gates.

## Live GitHub source
- Flutter: https://github.com/kamrans262/sign_app/tree/feature/m7-quote-review-approval-2026-11-03
- Laravel: https://github.com/kamrans262/sign_backend/tree/feature/m7-quote-review-approval-2026-11-03
- New backend workflow: `.github/workflows/m7-quote-focused.yml`
- New Flutter workflow: `.github/workflows/m7-quote-focused.yml`

**Decision:** All scope sections and old handoffs were cross-referenced; source-level M7 improvements committed, but **M7 production release is not authorized or verified as ready**. Resolve P0 canonical PDF provenance and verified CI before moving to deployment or treating full scope §6 as accepted.
