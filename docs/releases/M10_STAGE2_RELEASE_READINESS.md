# Sign2Digital — M10 Stage 2 Security, Regression and Hostinger Release

**Release candidate:** `feature/m10-addenda-management-2026-11-04` in backend and Flutter.  
**Base:** verified deployed M9 backend `2ad17628a8bf4fcdcdcde9a449ae3b938bc192d3`.  
**Production status:** **NOT DEPLOYED**; M9 remains the production backend until all gates are met.

## Scope, protections, traceability

M10 is **Addenda Management**. It is a separate document referencing an actual signed M8 artifact, never a modification or regenerated facsimile of the original. Implements project scope Screen 31 Save, Review, Send for Signature; preserves the original UI. See `M10_PREPARATION_REQUIREMENTS_AND_TWO_STAGE_PLAN.md` for M10-R01 through M10-R14 and audited source paths.

No staging server is required; security regression tests use disposable CI databases. A separate empty MySQL schema is required only for the production backup restoration proof.

## Stage 2 acceptance matrix

| Requirement | Verification / gate |
| --- | --- |
| Genuine signed source, tenant ownership, original SHA-256 | `M10AddendaManagementTest`: source must exist, belong to same company, have signed M8 request, verified original bytes |
| Draft revisions and server money | stale revision rejected, precise integer VAT and amount tested, only drafts editable |
| Signed original immutable | compare original bytes after creation, approval, signing |
| Approval freezes independent addendum | PDF SHA-256 and private storage, no fake signed original |
| Signature security | hashed 43-character tokens, approved revision/consent checks, one-use, expiry/revoke, tamper and cross-company checks |
| Email transport failures | newly created request revoked on error; retry possible; SMTP acceptance never claimed as final inbox delivery |
| Documents and Admin | addendum category and linked original, versions, history, previews, dedicated admin sidebar, permissions |
| AI voice | server-configured `OPENAI_API_KEY` only; no mobile key exposure; suggestions explicitly reviewed; network delivery manually verified after deployment |
| Responsive Flutter | existing inputs/buttons, phone 320 and 430, landscape 720×390, tablet 1024×768, 20px horizontal margins; content fills width |
| Security and CI | syntax, Blade views, routes/middleware, SQLite suite, isolated MySQL 8, production-mode preflight |
| Backups and restore | fresh SQL + private PDFs + environment snapshot, SHA256SUMS; isolated empty MySQL restore must succeed before production migration |
| Post-deploy acceptance | `M10_PREFLIGHT_PASS`, exact HEAD readback, real device and customer consent, signed-PDF integrity; email delivery separately verified |

**CI:**
- Backend workflow `.github/workflows/m10-addenda.yml`: M7–M10 regression, MySQL 8, read-only M9+M10 production-mode preflight, scripts syntax.
- Flutter workflow `.github/workflows/m10-addenda.yml`: M10 repository, prior M8/M9 document flows, Phase 5 widget flow, responsive addendum coverage.

## Production preconditions

1. Hostinger backend SSH: `ssh -p 65002 u777477188@147.93.42.143`. **Do not paste password or API secrets into chat.**
2. Laravel root: `~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend`.
3. Deployed M9 is clean at pinned commit `2ad17628a8bf4fcdcdcde9a449ae3b938bc192d3`. M9 preflight says `M9_PREFLIGHT_PASS`.
4. There are no live M10 tables yet. M10 migration is **additive only**: `database/migrations/2026_11_04_040000_create_addenda_management_tables.php`.
5. Backend PHP version >= 8.3, production mode, `APP_DEBUG=false`, HTTPS URL, MySQL 8, private storage writable outside public root.
6. Preserve all legacy original quotes/PDFs/hashes, including the one historic M7 approved quote without a usable PDF hash. Do not treat unsigned or legacy approvals as signed source.
7. A fresh, attributable SQL + private storage + environment snapshot is required, **and restored in isolation**. A checksum alone is not proof of recoverability.

## Hostinger commands — one line each, in exact order

**A. Read-only check and fresh M9 snapshot on currently deployed M9 branch:**

```bash
cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && test "$(git rev-parse HEAD)" = "2ad17628a8bf4fcdcdcde9a449ae3b938bc192d3" && test -z "$(git status --porcelain --untracked-files=all)" && php scripts/m9_release_preflight.php --require-migrated && bash scripts/m9_release_backup.sh --backup-only
```

Record the exact `M9_BACKUP_DIRECTORY` reported (under `/home/u777477188/sign2digital-release-backups/m8_<UTC>`). **Do not use a placeholder as the actual directory.**

**B. Switch to pinned M10 release code (no migration yet):**

```bash
cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && test -z "$(git status --porcelain --untracked-files=all)" && git fetch origin && git merge-base --is-ancestor HEAD origin/feature/m10-addenda-management-2026-11-04 && git switch -c deploy/m10-addenda-management-2026-11-04 --track origin/feature/m10-addenda-management-2026-11-04 && php scripts/m10_release_preflight.php
```

**C. Restore rehearsal, *before* changing production schema:**

Arrange a separate existing, empty MySQL database with a name containing `restore` or `rehearsal`; a separate account is preferred. The script blocks restoring over the live DB. In your SSH terminal, provide the test-only connection through `M10_RESTORE_HOST`, `M10_RESTORE_DB`, `M10_RESTORE_USER`, `M10_RESTORE_PASSWORD` (optional `M10_RESTORE_PORT`). Never share passwords in chat, Git or script arguments. Run:

```bash
bash scripts/m10_release_restore_rehearsal.sh --backup-dir "/exact/printed/M9_BACKUP_DIRECTORY"
```

Expected `M10_RESTORE_REHEARSAL_PASS`; script creates `m10-restore-rehearsal.txt` inside the snapshot with the snapshot manifest digest. If isolated DB access is unavailable, **deployment remains blocked**; do not invent a restore success.

**D. Controlled additive deployment — only after C passes:**

```bash
cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && bash scripts/m10_release_deploy.sh --apply --backup-dir "/exact/printed/M9_BACKUP_DIRECTORY"
```

Expected `M10_DEPLOY_PASS`, `M10_PREFLIGHT_PASS`, and exact Git HEAD. Deployment script verifies current M10 branch tip, fresh M9 backup's pinned M9 production SHA, backup checksums, an isolated restore proof, M9 routes/migration, M10 routes and M10 PDF integrity. It runs only the one additive M10 migration and cache clear; **no automatic destructive rollback**.

**E. Post-deployment read-only verification:**

```bash
cd ~/domains/forestgreen-pigeon-673192.hostingersite.com/sign_backend && git rev-parse HEAD && git status --short && php artisan migrate:status | grep 2026_11_04_040000_create_addenda_management_tables && php scripts/m9_release_preflight.php --require-migrated && php scripts/m10_release_preflight.php --require-migrated
```

**Flutter/Mac update only after backend M10 passes**. The user's Mac has local iOS/macOS project and CocoaPods files; preserve them by stashing. Never reset/clean or force a branch.

```bash
test "$(git branch --show-current)" = "feature/m9-document-management-2026-11-04" && git stash push -u -m "Sign2Digital pre-M10 Mac platform backup 2026-11-04" && git fetch origin && git switch -c feature/m10-addenda-management-2026-11-04 --track origin/feature/m10-addenda-management-2026-11-04 && git stash apply 'stash@{0}' && flutter pub get && flutter run
```

This command is a guarded example when the current Mac branch is still M9; do not run blindly if it already switched.

## Manual post-release acceptance checklist

1. Create a **test customer + test job + quote**, approve, request and complete a real M8 signature, verify original PDF hash. The earlier live M8 check had **zero signed artifacts**, so live M10 customer signing cannot be verified without establishing a legitimate signed test source.
2. Create an M10 addendum from this exact signed record in Flutter. Verify original details link; draft save/reopen across app launches/devices, quantity, price, VAT, date, validation and existing UI.
3. Review, explicitly approve, download and verify frozen addendum PDF hash; original signed quote bytes remain unchanged. Draft editing must be blocked after approval.
4. Generate and open a real secure addendum-only customer signing link; sign, verify receipt, signed PDF, source SHA, status and event history. Reuse and expiry/revoke must fail; do not claim email delivery unless confirmed.
5. Admin `Addenda Management`: list, filters, add/edit draft, approve, securely request signature, revoke, PDF downloads, version and audit history; permissions enforced.
6. Documents Addends, Quote and Signed Document cross-links and previews; old uploaded documents, M9 and older quotes still work; no original PDF overwritten.
7. Test actual OpenAI speech extraction only if the existing Hostinger `OPENAI_API_KEY` is configured and quota works. Do not store or request client keys in the app.
8. Check Android and iOS devices and 320, 430, 720×390, 1024×768 layouts. CI widget tests are not a substitute for real-device acceptance.
9. Validate external customer HTTPS access and SMTP delivery separately. `MAIL_MAILER=smtp` or similar means configured, **not delivery verified**.
10. Keep M9 snapshot and isolated restore database after deployment until production acceptance is signed off; do not delete private files or revert M10 additive tables.

## Current known limitations and safeguards

- This GitHub-only release preparation cannot SSH or edit Hostinger production on the user's behalf. Only actual Hostinger output can confirm deployment or restore rehearsal.
- M10 Stage 2 CI uses synthetic PDFs and isolated databases, not real customer records or verified live email delivery.
- Production addendum creation intentionally requires a genuine signed source. Legacy M7 approvals without verified PDFs cannot be upgraded by regenerating PDF artwork.
- The signing system records an electronic consent evidence PDF, not a qualified certificate-based PKI/PAdES signature.
- If a check fails, stop. Preserve the current database, signed originals and backup. Diagnose the failing route/migration before any further writes.
