# Sign2Digital — M7 Stage 2: Verification and Release Readiness

**Date:** 2026-11-03
**Release status:** NOT RELEASED. Source implementation committed; automated CI results, live and physical-device checks remain unverified.

## Exact branches
- Flutter: `kamrans262/sign_app` — `feature/m7-quote-review-approval-2026-11-03`
- Laravel: `kamrans262/sign_backend` — `feature/m7-quote-review-approval-2026-11-03`
- Both branch off the M6 feature source without modifying `main` or `development`.
- Production was not accessed, migrated or deployed in Stage 2.

## Verified from source
1. Existing M6 quote draft/read/approval/share/PDF, signed customer link and admin Quote Management are reused without duplicated controllers, schema or screen layouts.
2. Flutter now passes the saved `expected_revision` in its approval call. Quote preview and sending still require actual approved data.
3. Laravel mobile approval (Stage 1) locks and rereads the current quote in a DB transaction before validating the reviewed revision and complete draft.
4. Laravel admin approval (Stage 2) now locks, rereads, validates against the optional reviewed revision inside a transaction, verifies at least one complete work item, records revision and audit only on success.
5. Existing admin approve form now posts a hidden `expected_revision`; no visual change.
6. Added `M7QuoteApprovalTest` for stale admin review, correct approval and preventing approval of empty work-item collections. Existing mobile `M6ServiceQuoteManagementTest` now covers stale revision, correct revision and duplicate approval. Flutter repository regression checks the approved request revision and HTTP 409 response.
7. A new M7 workflow per repository is configured to run on pushes to this M7 branch. Backend workflow retains PHP/Blade, SQLite and MySQL 8 M4–M6 focused coverage plus M7 tests; Flutter workflow runs focused analyzer and existing quote UI tests.
8. Original M7 Stage 2 snapshot had no UI screen changes. The 2026-11-04 independent re-audit subsequently corrected fractional VAT/deposit formatting and decimal keyboard behavior within existing screens, without changing layout/navigation/colors/components. See `docs/M7_INDEPENDENT_REAUDIT_2026-11-04.md`.

## API compatibility
`POST /api/v1/quotes/{quote}/approve`: confirmed=true; optionally expected_revision=current displayed revision. Missing revision accepted to preserve M6 clients; updated Flutter submits it. 200 on success, 409 on stale/already-approved, 422 on incomplete; tenant/RBAC stays unchanged.

`POST /admin/quotes/{quote}/approve`: optional expected_revision hidden in existing form; 409 for stale/already-approved; incomplete quote redirects with validation errors; success redirects and creates exactly one revision.

## Landscape/layout audit
- `review_quote_screen.dart` and `quote_preview_screen.dart` currently have existing `BoxConstraints(maxWidth: 900)` wrappers.
- Quote preview actions use a `LayoutBuilder` and switch to vertically stacked full-width buttons below 350px; review actions respond below 330px.
- Send Quote has a responsive method selector and a LayoutBuilder body.
- The user's known issue: some landscape/tablet pages appear centered rather than using the full available viewport width. The 900px wrapper likely contributes on wide screens.
- **No UI change was made**, as requested. On-device landscape review remains a release acceptance item. A future explicitly approved adaptive-layout change should preserve the original visual language, fields, button actions and touch targets rather than globally removing constraints.

## Tests and acceptance (honest status)
| Test | Check |
| --- | --- |
| PHP lint + Blade compile | Configured in M7 backend workflow; results not yet verified |
| SQLite focused M4/M5/M6/M7 | Configured; results not yet verified |
| MySQL 8 focused M4/M5/M6/M7 | Configured; results not yet verified |
| Flutter focused analyze | Configured; results not yet verified |
| Flutter quote UI, API repository/privacy test suite | Configured; results not yet verified |
| Android/iOS layout and manual edit-approve-preview-send | Pending physical testing |
| Live SMTP, secure link expiry and PDF parity | Pending deployed-environment testing |
| M6 billable AI quota/transcription/vision | Separate M6 acceptance; pending credits |
| Production preflight/backup/migration | Not performed; do not deploy |

## One-line local verification (non-destructive)
- Backend: `git fetch origin && git switch feature/m7-quote-review-approval-2026-11-03 && git pull --ff-only origin feature/m7-quote-review-approval-2026-11-03 && composer install --no-interaction && php artisan test --filter='M(6ServiceQuoteManagement|6Stage2SecurityAndRelease|7QuoteApproval)Test'`
- Flutter: `git fetch origin && git switch feature/m7-quote-review-approval-2026-11-03 && git pull --ff-only origin feature/m7-quote-review-approval-2026-11-03 && flutter pub get && flutter analyze lib/features/quotes test/features/quotes && flutter test test/features/quotes`
- Before switching branch, inspect `git status --short` and preserve local changes. The commands above assume a clean worktree; do not run destructive resets/cleans.

## Release gates
- Review and fix all CI failures; distinguish mocked SMTP/AI tests from real service behavior.
- Confirm Hostinger's *actual* production backend branch and full SHA; historical M6 handoff said not deployed but user later reported it deployed.
- Obtain user deployment authorization, verified MySQL/private storage/.env backups, rollback plan and read-only preflight before any migration/deployment. Never change APP_KEY.
- Confirm updated mobile release targets same backend API contract; run client-side regression and physical landscape test.
- No new OpenAI key or migration is introduced by M7 Stage 2.

**Decision:** M7 Stage 2 code and test coverage prepared, but release readiness is conditional. Do NOT deploy until gates pass.

## 2026-11-04 independent re-audit addendum (supersedes earlier snapshot)

For the current authoritative risk and verification status, see `docs/M7_INDEPENDENT_REAUDIT_2026-11-04.md`. Changes after the original Stage 2 snapshot: M7 test filters fixed to execute real approval regressions in both DB engines, admin approval tests now use real edit endpoints, archive/restore operations use row locks, null mailer no longer causes fake Sent, fractional VAT/deposit precision preserved across Flutter review/preview/PDF. The existing 900px landscape width constraint remains unchanged. The backend still accepts non-canonical client-supplied PDF attachment bytes; this is a release blocker. CI and live Hostinger verification remain unconfirmed. **Do not deploy yet.**
