#!/usr/bin/env bash
# Sign2Digital M9 backup wrapper. Only snapshots; no migrations or Git changes.
# Run from deployed M8 Laravel root: bash scripts/m9_release_backup.sh --backup-only
set -Eeuo pipefail
umask 077
if [[ $# -ne 1 || "$1" != "--backup-only" || ! -f .env || ! -f artisan ]]; then
  echo "M9_BACKUP_BLOCKED: Laravel root and --backup-only are required." >&2
  exit 2
fi
for name in git php bash sha256sum grep; do
  command -v "$name" >/dev/null || { echo "M9_BACKUP_BLOCKED: Missing $name." >&2; exit 2; }
done
if [[ ! -f scripts/m8_release_preflight.php || ! -f scripts/m8_release_backup.sh ]]; then
  echo "M9_BACKUP_BLOCKED: M8 release scripts are missing." >&2
  exit 2
fi
test -z "$(git status --porcelain --untracked-files=all)" || {
  echo "M9_BACKUP_BLOCKED: Backend worktree has local changes." >&2
  exit 2
}
php scripts/m8_release_preflight.php --require-migrated
report="$(bash scripts/m8_release_backup.sh --backup-only)"
printf '%s\n' "$report"
directory="$(printf '%s\n' "$report" | grep '^M8_BACKUP_DIRECTORY ' | tail -n 1 | cut -d ' ' -f 2-)"
case "$directory" in
  "$HOME"/sign2digital-release-backups/m8_*) ;;
  *) echo "M9_BACKUP_BLOCKED: Unsafe backup directory." >&2; exit 2 ;;
esac
test -d "$directory" && test -f "$directory/SHA256SUMS.txt"
(
  cd "$directory"
  sha256sum -c SHA256SUMS.txt >/dev/null
)
printf 'release=M9\nbase=M8\ncreated_utc=%s\nbackend_sha=%s\n' \
  "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(git rev-parse HEAD)" \
  > "$directory/m9-release-manifest.txt"
chmod 600 "$directory/m9-release-manifest.txt"
(
  cd "$directory"
  sha256sum m9-release-manifest.txt >> SHA256SUMS.txt
  sha256sum -c SHA256SUMS.txt >/dev/null
)
echo "M9_BACKUP_PASS"
echo "M9_BACKUP_DIRECTORY $directory"
echo "RESTORE_TEST_PENDING: Confirm database restore to an isolated MySQL instance before deploying."
