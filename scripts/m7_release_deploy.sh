#!/usr/bin/env bash
# Sign2Digital M7 guarded Hostinger deployment. Run from sign_backend root.
# Pass the exact CI-verified release commit SHA as the sole argument.
# Preserves existing production .env, rows, M6 fixes and private storage.
set -Eeuo pipefail

EXPECTED_OLD="134574095650e568e26743fe500116d7bc8a4b25"
RELEASE_REF="release/m7-preserve-production-2026-11-04"
MIGRATION="database/migrations/2026_11_04_010000_add_approved_pdf_integrity_to_service_quotes.php"
SWITCHED=0
TEMP_MIGRATION=0
OLD=""

fail() { printf 'BLOCK: %s\n' "$1" >&2; exit 1; }
restore_on_error() {
  local result=$?
  trap - ERR
  if [[ "$TEMP_MIGRATION" == "1" ]]; then
    rm -f "$MIGRATION"
  fi
  if [[ "$SWITCHED" == "1" && -n "$OLD" ]]; then
    printf '%s\n' "WARNING: Post-deploy check failed; restoring previous application commit."
    git switch --detach "$OLD" || true
    php artisan optimize:clear || true
  fi
  printf 'M7_RELEASE_BLOCKED (status %s). Backup remains intact. Database migration is additive; never manually delete columns.\n' "$result" >&2
  exit "$result"
}
trap restore_on_error ERR

[[ $# -eq 1 ]] || fail "Provide exactly one verified release commit SHA."
NEW="$1"
[[ "$NEW" =~ ^[0-9a-f]{40}$ ]] || fail "Provide the exact approved 40-character release commit SHA."
[[ -f ".env" && -f "artisan" && -f "vendor/autoload.php" ]] || fail "Run inside the deployed Laravel root."
[[ -z "$(git status --porcelain)" ]] || fail "Uncommitted server changes: stop instead of overwriting."
OLD="$(git rev-parse HEAD)"
[[ "$OLD" == "$EXPECTED_OLD" ]] || fail "Production has changed since preflight; verify current commit again."
[[ -z "$(git branch --show-current)" ]] || fail "Expected detached production commit; stop to preserve branch safety."
command -v mysqldump >/dev/null || fail "mysqldump is not available."
command -v tar >/dev/null || fail "tar is not available."
git fetch origin "$RELEASE_REF"
FETCHED="$(git rev-parse FETCH_HEAD)"
[[ "$FETCHED" == "$NEW" ]] || fail "Remote release changed since approval; do not deploy an unverified commit."
git merge-base --is-ancestor "$OLD" "$FETCHED" || fail "Release does not contain the deployed M6 ancestor."
[[ ! -e "$MIGRATION" ]] || fail "Migration file already exists; inspect server state first."

printf 'M7_PRECHECK_OK: production ancestor retained; release SHA verified.\n'
printf 'Creating private SQL, .env and private-storage backups first...\n'
git show "$FETCHED:scripts/m7_release_backup.php" | php

# A failed or partial backup never reaches this point (pipefail is enabled).
# Apply only the new nullable M7 columns, while old M6 PHP is still live.
# The migration is loaded from the reviewed target commit and then removed
# before the tracked release files are switched in.
git show "$FETCHED:$MIGRATION" > "$MIGRATION"
TEMP_MIGRATION=1
php artisan migrate --force --path="$MIGRATION"
rm -f "$MIGRATION"
TEMP_MIGRATION=0

[[ -z "$(git status --porcelain)" ]] || fail "Worktree changed unexpectedly after isolated migration."
git switch --detach "$FETCHED"
SWITCHED=1
php artisan optimize:clear
php scripts/m7_release_preflight.php --require-migrated
[[ -z "$(git status --porcelain)" ]] || fail "Post-deploy tracked working tree is not clean."
trap - ERR
printf 'M7_DEPLOY_OK: %s\n' "$(git rev-parse --short HEAD)"
printf 'Previous M6 commit: %s\n' "$OLD"
printf '%s\n' "Private database/.env/storage backups are under storage/backups."
printf '%s\n' "Remember: Flutter app must be updated to the compatible M7 branch."
