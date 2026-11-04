#!/usr/bin/env bash
# M11 additive-only production release. Never switches branches, deletes files,
# restores live DBs or rolls back historical documents.
set -Eeuo pipefail
umask 077
if [[ $# -ne 3 || "$1" != "--apply" || "$2" != "--backup-dir" ]]; then
  echo "M11_DEPLOY_BLOCKED: Require --apply --backup-dir <verified snapshot>." >&2;exit 2
fi
backup="$3"
case "$backup" in "$HOME"/sign2digital-release-backups/m8_*) ;;
  *) echo "M11_DEPLOY_BLOCKED: Untrusted snapshot." >&2; exit 2 ;;
esac
for file in artisan .env vendor/autoload.php scripts/m10_release_preflight.php \
  scripts/m11_release_preflight.php database/migrations/2026_11_04_050000_create_job_media_tables.php; do
  test -f "$file" || { echo "M11_DEPLOY_BLOCKED: Missing $file" >&2;exit 2; }
done
for file in SHA256SUMS.txt m11-release-manifest.txt m11-restore-rehearsal.txt \
  production-head.txt mysql.sql.gz private-pdfs.tar.gz env-config.tar.gz; do
  test -f "$backup/$file" && test ! -L "$backup/$file" || {
    echo "M11_DEPLOY_BLOCKED: Missing or symlinked backup file $file" >&2;exit 2;
  }
done
test -z "$(git status --porcelain --untracked-files=all)" || {
  echo "M11_DEPLOY_BLOCKED: Local changes present." >&2;exit 2;
}
head="$(git rev-parse HEAD)"
git fetch -q origin feature/m11-job-photos-notes-2026-11-04
expected="$(git rev-parse refs/remotes/origin/feature/m11-job-photos-notes-2026-11-04)"
test "$head" = "$expected" || {
  echo "M11_DEPLOY_BLOCKED: Deployed HEAD not verified M11 feature tip." >&2;exit 2;
}
original="$(cat "$backup/production-head.txt")"
git merge-base --is-ancestor "$original" "$head" || {
  echo "M11_DEPLOY_BLOCKED: Backup HEAD unrelated to this release." >&2;exit 2;
}
(
  cd "$backup"
  sha256sum -c SHA256SUMS.txt >/dev/null
  gzip -t mysql.sql.gz
  tar -tzf private-pdfs.tar.gz >/dev/null
  tar -tzf env-config.tar.gz >/dev/null
)
grep -qx 'release=M11' "$backup/m11-release-manifest.txt"
grep -qx 'base=M10' "$backup/m11-release-manifest.txt"
grep -qx "backend_sha=$original" "$backup/m11-release-manifest.txt"
grep -qx 'release=M11' "$backup/m11-restore-rehearsal.txt"
grep -qx 'restored=M10' "$backup/m11-restore-rehearsal.txt"
hash="$(sha256sum "$backup/SHA256SUMS.txt" | cut -d ' ' -f 1)"
grep -qx "snapshot_manifest_sha256=$hash" "$backup/m11-restore-rehearsal.txt" || {
  echo "M11_DEPLOY_BLOCKED: Backup has not been restore-tested." >&2;exit 2;
}
php scripts/m10_release_preflight.php --require-migrated
php scripts/m11_release_preflight.php
echo "M11_VERIFIED_BACKUP_AND_RESTORE"
echo "M11_MIGRATION_BEGIN"
php artisan migrate --force --path=database/migrations/2026_11_04_050000_create_job_media_tables.php
php artisan optimize:clear
php scripts/m10_release_preflight.php --require-migrated
php scripts/m11_release_preflight.php --require-migrated
echo "M11_DEPLOY_PASS $head"
echo "NO AUTOMATIC ROLLBACK. Preserve all tables, files, signing history, and original PDFs."
