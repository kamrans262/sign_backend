#!/usr/bin/env bash
# Sign2Digital M10 additive-only release; assumes verified M9 production backup.
# Never performs Git checkout, backup deletion, database rollback or force reset.
# Run: bash scripts/m10_release_deploy.sh --apply --backup-dir "<M9 snapshot path>"
set -Eeuo pipefail
umask 077

if [[ $# -ne 3 || "$1" != "--apply" || "$2" != "--backup-dir" ]]; then
  echo "M10_DEPLOY_BLOCKED: Require --apply --backup-dir <verified-M9-snapshot>." >&2
  exit 2
fi
backup="$3"
case "$backup" in
  "$HOME"/sign2digital-release-backups/m8_*) ;;
  *) echo "M10_DEPLOY_BLOCKED: Backup is outside trusted M9 snapshot directory." >&2; exit 2 ;;
esac
for program in git php bash sha256sum grep tar gzip; do
  command -v "$program" >/dev/null || { echo "M10_DEPLOY_BLOCKED: Missing $program." >&2; exit 2; }
done
if [[ ! -f artisan || ! -f .env || ! -f vendor/autoload.php ||
      ! -f scripts/m9_release_preflight.php ||
      ! -f scripts/m10_release_preflight.php ||
      ! -f "$backup/SHA256SUMS.txt" ||
      ! -f "$backup/m9-release-manifest.txt" ||
      ! -f "$backup/production-head.txt" ||
      ! -f "$backup/mysql.sql.gz" ||
      ! -f "$backup/private-pdfs.tar.gz" ||
      ! -f "$backup/env-config.tar.gz" ]]; then
  echo "M10_DEPLOY_BLOCKED: The backend or source backup is incomplete." >&2
  exit 2
fi
if [[ -L "$backup" || -L "$backup/SHA256SUMS.txt" || -L "$backup/mysql.sql.gz" ||
      -L "$backup/private-pdfs.tar.gz" || -L "$backup/env-config.tar.gz" ]]; then
  echo "M10_DEPLOY_BLOCKED: Symlinked backup components are not trusted." >&2
  exit 2
fi
test "$(git branch --show-current)" = "deploy/m10-addenda-management-2026-11-04" || {
  echo "M10_DEPLOY_BLOCKED: Use the dedicated M10 deployment branch." >&2
  exit 2
}
test -z "$(git status --porcelain --untracked-files=all)" || {
  echo "M10_DEPLOY_BLOCKED: The backend worktree has local changes." >&2
  exit 2
}
head_sha="$(git rev-parse HEAD)"
feature_sha="$(git rev-parse refs/remotes/origin/feature/m10-addenda-management-2026-11-04)"
test "$head_sha" = "$feature_sha" || {
  echo "M10_DEPLOY_BLOCKED: Deployed HEAD is not the verified M10 feature tip." >&2
  exit 2
}
base_sha="2ad17628a8bf4fcdcdcde9a449ae3b938bc192d3"
test "$(cat "$backup/production-head.txt")" = "$base_sha" || {
  echo "M10_DEPLOY_BLOCKED: Backup is not of the known M9 deployment." >&2
  exit 2
}
grep -qx 'release=M9' "$backup/m9-release-manifest.txt" || {
  echo "M10_DEPLOY_BLOCKED: Missing M9 backup provenance." >&2; exit 2;
}
(
  cd "$backup"
  sha256sum -c SHA256SUMS.txt >/dev/null
  tar -tzf private-pdfs.tar.gz >/dev/null
  tar -tzf env-config.tar.gz >/dev/null
  gzip -t mysql.sql.gz
)
echo "M10_BACKUP_CHECKSUMS_PASS"
echo "RESTORE_TEST_REQUIRED: A checksum does not demonstrate successful MySQL restore."
php scripts/m9_release_preflight.php --require-migrated
php scripts/m10_release_preflight.php
echo "M10_MIGRATION_BEGIN"
php artisan migrate --force --path=database/migrations/2026_11_04_040000_create_addenda_management_tables.php
php artisan optimize:clear
php scripts/m9_release_preflight.php --require-migrated
php scripts/m10_release_preflight.php --require-migrated
echo "M10_MIGRATION_COMPLETE"
echo "M10_BACKEND_HEAD $head_sha"
echo "M10_DEPLOY_PASS"
echo "NO AUTOMATIC DATABASE ROLLBACK: Preserve original signed PDFs, all M7–M10 tables and private files."
