# Sign2Digital — M6 Stage 2 Final Verification and Release Handoff

**Date:** 2026-11-03. **Status:** Stage 2 implementation and automated regression verification; future release is **NOT authorized / NOT deployed**.

## Versioned Git references

- Flutter: `kamrans262/sign_app`, `feature/m6-ai-quote-creation-2026-11-03`, based on M5 `bd9fd1ff804b256e51823b8deebb9ca62eb25a26`.
- Laravel: `kamrans262/sign_backend`, `feature/m6-ai-quote-creation-2026-11-03`, based on M5 `1ec0800f5a9c67e1c8177051e8f9ada8e36b1bad`.
- **Do not rewrite or replace the deployed M5 backend without explicit release authorization, validated backups and rollback.** All earlier M1–M5 source commits are retained in M6 branches.

## User-approved behavior and scope

Preserve every original Create Quote, Quote AI Processing, Review Quote, Quote Preview, PDF, Send Quote, job-linked quote, client-linked quote, and signing screen. Maintain all original fields, buttons, dropdown options, style tokens and landscape behavior; no new duplicate widgets. Do not represent demo fixtures as real quotes. User-typed prices, VAT, deposit and terms remain human-entered; AI never invents amounts, quantities, dimensions, materials, taxes, addresses or dates.

## M6 Stage 2 changes

1. Corrected the legacy gallery widget-test fixture: `XFile.fromData` provides bytes without native dart:io blocking inside fake-async Flutter tests. Updated focused CI with expanded reporter and parallel tests.
2. Authorized customer links are time-limited and signed. Archiving rotates `service_quotes.link_version` to invalidate all older URLs permanently, even after an administrator restores an approved quote.
3. Admin Quote Management now shows the original payment/IBAN inputs and read-only approved quote details, including saved items, totals, VAT, deposit, customer, dates and payment instructions; the customer signed-view page shows verified payment fields.
4. Mobile and admin inputs reject oversized money and unexpected payment-detail fields. Quotes use integer minor currency units; the API never accepts client-provided totals/status/tenant identity.
5. The editable **Quote Number** field is saved as company-unique `custom_quote_number`, with immutable server-generated `reference_number` reserved for audit. The app and print view use the chosen display number when supplied.
6. Mobile and admin update transactions lock the quote and reject stale `expected_revision` values with HTTP 409 instead of overwriting newer work. Flutter preserves the server revision in quote drafts.
7. Quote draft session store, recipient-message store and Home quote tracking clear on verified company changes or logout. The shared device cannot carry private quote session data from the previous company into the next one.
8. Added backend M6 Stage 2 feature tests for signed-link expiry, tampering, invalidation/restoration, unavailable email transport, validation and money boundaries, admin approval/read-only views, malicious uploads and missing AI key, custom numbers, tenant isolation and stale-device edits.
9. Added Flutter M6 Stage 2 tests for company switches, logout, quote number mapping, server approval boundaries and stale tenant responses.
10. Added `scripts/m6_release_preflight.php`, strictly READ-ONLY; validates production prerequisites, route guards, signed public route and schema presence. Live AI/SMTP checks are opt-in.

## API and schema traceability

- Additive database migration `2026_11_03_500000_create_service_quotes_tables.php` creates `service_quotes`, `service_quote_items` and `service_quote_revisions`, including `link_version`, `custom_quote_number` and `revision`.
- Quote read: `GET /api/v1/quotes`, `/api/v1/quotes/{quote}`, `/api/v1/quotes/{quote}/revisions` require active membership and `quotes.read`.
- Quote write: `POST /api/v1/quotes`, `PATCH /api/v1/quotes/{quote}`, `POST /api/v1/quotes/{quote}/approve`, `POST /api/v1/quotes/{quote}/share`, `DELETE /api/v1/quotes/{quote}` require `quotes.write`.
- AI extraction: `POST /api/v1/quotes/prepare/text|audio|image` is authenticated, tenant-scoped and rate-limited. The OpenAI API key exists only in Laravel's private `.env`; no key is compiled into the app.
- Public customer view: `GET /quote-view/{quote}` requires signed and unexpired link, an approved/sent (not archived) quote and matching link version.
- Company-linked quote history appears in Jobs and Clients only if the member separately has `quotes.read`.
- Admin `/admin/quotes` uses existing Laravel administrator guards and original admin theme; a separate signing module owns actual document signing, not M6.

## Verification status — do not overstate

- Laravel focused CI performs syntax check and Blade compilation, SQLite and MySQL 8 tests including M4/M5 inherited tests and M6 Stage 1/2 quote tests.
- Flutter focused CI runs static analysis and existing quote UI tests, AI adapter, quote repository and M6 Stage 2 privacy tests.
- **Automated green** means mock-provider tests and framework checks passed; it does NOT prove on-device camera/microphone, remote model availability, real SMTP receipt or real signed-link experience on the deployed Hostinger domain.
- **Not tested against live external systems:** OpenAI transcription, vision and text generation with a billable API key; real iPhone/Android capture; production SMTP/email receipt; live Hostinger HTTPS and link expiry; iOS/Android release builds; PDF equivalence on physical devices. Treat these as acceptance blockers, not silently completed.
- Existing M5 physical acceptance remains deferred at the user's request. Do not claim M5 on-device coverage from the M6 unit tests.

## Live test cases to execute after a user-approved deployment

- Sign in with verified user and active company; run Text / Voice / handwritten-photo quote extraction with genuine data. Confirm work descriptions are supported and prices/VAT/measurements are not fabricated.
- Save a draft, reopen from Job Quotes, edit a quote number, add multiple line items and verify currency amounts and Swiss payment fields.
- Approve a fully priced quote and open the existing Preview/Download PDF/Send screens. Confirm that a quote cannot be edited or shared without approval; stale draft receives clear conflict, not overwritten data.
- Send email via configured SMTP and open the expiring secure link on a real customer device. Verify real delivery, content, expiry, tampering, archive/restore revocation and printing.
- Switch companies and log out. Verify no old local quote, payment or recipient details remain. Revoke `quotes.read` and `quotes.write`, confirm denied API and UI effects.
- Test narrow portrait and tablets/landscape, voice microphone permissions on iOS/Android, JPG/PNG selection, offline errors, upload size and repeated send.

## Controlled future deployment — NO COMMANDS HAVE BEEN EXECUTED

1. Confirm the user explicitly approves release and provides the target environment. Preserve their dirty local macOS/iOS Flutter files; do not run destructive `git reset --hard`, `git clean -fd`, or mass merge.
2. Confirm the current Hostinger database and active backend branch/commit in read-only mode. Back up the real production MySQL DB, private storage and Laravel `.env` securely outside public web root. Verify backup restorability and rollback commit.
3. Verify `APP_ENV=production`, `APP_DEBUG=false`, HTTPS APP_URL, PHP extensions/version, MySQL connectivity and Laravel configuration. Keep APP_KEY unchanged.
4. Once final release commit is reviewed, deploy the explicit consolidated commit (including required M3–M6 ancestors). Run `php scripts/m6_release_preflight.php` read-only; pending schemas are allowed before migration.
5. After verified backups and explicit migration approval, run the additive migrations exactly once. Then run `php scripts/m6_release_preflight.php --require-migrated` read-only and review all PASS/BLOCK results.
6. Configure `OPENAI_API_KEY` on the private Laravel server **only when real AI testing is authorized**; do not paste it in chat, commit it or add it to Flutter. Configure SMTP separately for actual delivery. When ready run the read-only `--require-live-services` preflight.
7. Smoke-test end-to-end and retain rollback. Do not mark production released until server, device, AI and email acceptance are independently verified.

**Explicit exclusions:** No deployment, migration, production DB change, live AI call, app-store submission, signing-module backend replacement, Firebase work or UI redesign was performed as part of M6 Stage 2.

## References

- `docs/M6_STAGE1_AI_QUOTE_CREATION.md` — original source flow/contract.
- `.github/workflows/m6-quote-focused.yml` — Laravel and Flutter (each repository) focused verification.
- `tests/Feature/M6ServiceQuoteManagementTest.php` and `tests/Feature/M6Stage2SecurityAndReleaseTest.php` — Laravel API/security regression.
- `test/features/quotes/m6_remote_quote_repository_test.dart` and `test/features/quotes/m6_stage2_privacy_and_validation_test.dart` — Flutter quote/tenant regression.
- `scripts/m6_release_preflight.php` — read-only future deployment gate.
