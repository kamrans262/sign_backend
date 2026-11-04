#!/usr/bin/env bash
# Sign2Digital M9 additive migration. No Git branch switches, no DB restore.
# Run only AFTER creating and verifying the M8 production snapshot, and AFTER
# checking out the pinned M9 backend feature release.
# Example: bash scripts/m9_release_deploy.sh --apply --backup-dir "/secure/backup/path"
set -Eeuo pipefail
umask 077

if [[ $# -ne 3 || "$1" != "--apply" || "$2" != "--backup-dir" ]]; then
  echo "M9_DEPLOY_BLOCKED: Require --apply --backup-dir <verified-backup>." >&2
  exit 2
fi
backup="$3"
case "$backup" in
  "$HOME"/sign2digital-release-backups/m8_*) ;;
  *) echo "M9_DEPLOY_BLOCKED: Backup directory is not a trusted release snapshot." >&2; exit 2 ;;
esac
if [[ ! -f artisan || ! -f .env || ! -f vendor/autoload.php ||
      ! -f scripts/m9_release_preflight.php || ! -f "$backup/SHA256SUMS.txt" ]]; then
  echo "M9_DEPLOY_BLOCKED: Backend or snapshot is incomplete." >&2
  exit 2
fi
for command in git php sha256sum; do
  command -v "$command" >/dev/null || { echo "M9_DEPLOY_BLOCKED: Missing $command." >&2; exit 2; }
done
branch="$(git branch --show-current)"
if [[ "$branch" != "deploy/m9-document-management-2026-11-04" ]]; then
  echo "M9_DEPLOY_BLOCKED: Use the dedicated M9 deployment branch." >&2
  exit 2
fi
if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
  echo "M9_DEPLOY_BLOCKED: Uncommitted or untracked release files." >&2
  exit 2
fi
(
  cd "$backup"
  sha256sum -c SHA256SUMS.txt >/dev/null
)
echo "M9_BACKUP_VERIFIED"
php scripts/m9_release_preflight.php
echo "M9_MIGRATION_BEGIN"
php artisan migrate --force --path=database/migrations/2026_11_04_030000_create_document_management_tables.php
php artisan optimize:clear
php scripts/m9_release_preflight.php --require-migrated
echo "M9_MIGRATION_COMPLETE"
echo "M9_BACKEND_HEAD $(git rev-parse HEAD)"
echo "M9_DEPLOY_PASS"
echo "NO AUTOMATIC DATABASE ROLLBACK: Preserve additive tables and all private PDFs."
