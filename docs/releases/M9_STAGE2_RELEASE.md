# Sign2Digital — M9 Stage 2 release runbook

Status: **candidate release; do not mark deployed until server and device checks succeed**.

## Preserved invariants
- Preserve the original Flutter screens and approved layout. M9 adds API binding without a redesign.
- Do not merge or edit main or development.
- Do not remove or overwrite M7 approved quote PDFs or M8 signed PDFs.
- Signed artifacts remain immutable. Uploaded PDFs receive separate versions and attachments.
- API access requires Sanctum, active user and company plus documents.read/documents.write. Admin requires a platform administrator.
- All snapshots stay outside public_html. Never commit .env, API keys or backup archives.

## Server prerequisites
1. Production must be a healthy *M8 release*. Both M7 and M8 migrations must show Ran. Do not skip directly from an unverified M7 installation.
2. Run: php scripts/m8_release_preflight.php --require-migrated
3. Run: bash scripts/m8_release_backup.sh --backup-only
4. Save its M8_BACKUP_DIRECTORY, verify SHA256SUMS and separately test an isolated restore before switching branches.
5. The current backend Git revision must be an ancestor of the M9 feature release, with a clean worktree.

## Controlled release steps
From the active Laravel backend root:
1. Check: test -z "$(git status --porcelain --untracked-files=all)"
2. Check: php scripts/m8_release_preflight.php --require-migrated
3. Backup: bash scripts/m8_release_backup.sh --backup-only
4. Fetch: git fetch origin feature/m8-digital-signatures-2026-11-04 feature/m9-document-management-2026-11-04
5. Guard: git merge-base --is-ancestor HEAD origin/feature/m9-document-management-2026-11-04
6. Switch: git switch -c deploy/m9-document-management-2026-11-04 --track origin/feature/m9-document-management-2026-11-04
7. Preflight: php scripts/m9_release_preflight.php
8. Deploy: bash scripts/m9_release_deploy.sh --apply --backup-dir "/absolute/secure/backup/path"
9. Verify: php artisan migrate:status, /api/health, admin Documents list and the device acceptance checks below.

Never run reset --hard, migrate:fresh, migrate:rollback or force-push on production.
The M9 script uses only the additive M9 migration. It requires a valid backup, M8 installed and correct release branch.

## Rollback
- For a code issue switch back to the recorded pre-M9 production commit, then clear Laravel caches. Preserve the additive M9 database tables, especially if users uploaded documents.
- If migration fails, stop and retain backup, logs and files. Do not retry with destructive migration commands.
- Database restore is a last resort and needs an independently tested snapshot and explicit assessment of records created after the backup.
- Do not rebuild a missing or altered approved/signed PDF and call it the original.

## Acceptance checklist
- [ ] API /api/health returns 200 and the admin still logs in.
- [ ] Only platform administrators can open Document Management.
- [ ] Admin list company/type/category/status/search filters operate correctly.
- [ ] Real company document uploads, previews and SHA-256 verified downloads work.
- [ ] Uploading version 2 leaves version 1 and its bytes intact.
- [ ] Attachment uploads do not alter the original source PDF digest; Flutter and admin downloads preserve image/PDF contents and verify SHA-256.
- [ ] A member with documents.read cannot create files or versions.
- [ ] Unsigned quotes have no verified signed PDF and cannot be signed by document upload.
- [ ] Existing M7 approved/M8 signed PDFs remain unchanged and downloadable.
- [ ] Related signed/original quote links refer to exact recorded IDs.
- [ ] History events come from server records rather than invented activity.
- [ ] Other companies cannot list, preview or download a tenant's documents.
- [ ] iOS, Android, landscape and tablet keep existing Sign2Digital UI styles.
- [ ] Switching company/account does not show records from an earlier company.
- [ ] Review backend SQLite/MySQL, Flutter analyzer and focused regression results.
- [ ] Compare any broad legacy UI test failures against the untouched M8 baseline.
- [ ] Check on actual mobile devices after the backend migration, not just CI.

## Scripts and evidence
- Read-only preflight: scripts/m9_release_preflight.php
- M8-backed snapshot wrapper: scripts/m9_release_backup.sh
- Guarded migration: scripts/m9_release_deploy.sh
- Laravel CI: .github/workflows/m9-document-management.yml
- Flutter CI: sign_app/.github/workflows/m9-document-management.yml
- Original M8 UI baseline: sign_app/.github/workflows/m9-legacy-ui-baseline.yml

These scripts do not publish credentials, signature links, document contents or private file paths in CI output.
