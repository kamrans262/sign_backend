#!/usr/bin/env bash
# M11 immutable full production snapshot. No checkout/migration/database edits.
set -Eeuo pipefail
umask 077
if [[ $# -ne 1 || "$1" != "--backup-only" ]]; then
  echo "M11_BACKUP_BLOCKED: Require --backup-only." >&2; exit 2
fi
test -f artisan && test -f .env && test -f scripts/m8_release_backup.sh
test -z "$(git status --porcelain --untracked-files=all)" || {
  echo "M11_BACKUP_BLOCKED: Preserve uncommitted production changes." >&2;exit 2;
}
php scripts/m10_release_preflight.php --require-migrated
result="$(bash scripts/m8_release_backup.sh --backup-only)"
printf '%s\n' "$result"
directory="$(printf '%s\n' "$result" | grep '^M8_BACKUP_DIRECTORY ' | tail -n 1 | cut -d ' ' -f 2-)"
case "$directory" in "$HOME"/sign2digital-release-backups/m8_*) ;;
  *) echo "M11_BACKUP_BLOCKED: Unexpected backup location." >&2;exit 2 ;;
esac
(
  cd "$directory"
  sha256sum -c SHA256SUMS.txt >/dev/null
  gzip -t mysql.sql.gz
  tar -tzf private-pdfs.tar.gz >/dev/null
  tar -tzf env-config.tar.gz >/dev/null
)
printf 'release=M11\nbase=M10\nbackend_sha=%s\ncreated_utc=%s\n' \
  "$(git rev-parse HEAD)" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  > "$directory/m11-release-manifest.txt"
chmod 600 "$directory/m11-release-manifest.txt"
(
  cd "$directory"
  sha256sum m11-release-manifest.txt >> SHA256SUMS.txt
  sha256sum -c SHA256SUMS.txt >/dev/null
)
echo "M11_BACKUP_PASS"
echo "M11_BACKUP_DIRECTORY $directory"
echo "M11_RESTORE_TEST_REQUIRED: A checksum does not prove database restoration."
