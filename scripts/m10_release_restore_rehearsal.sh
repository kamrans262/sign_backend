#!/usr/bin/env bash
# M10: restore an M9 release backup only into an existing EMPTY, separate
# MySQL database with "restore" or "rehearsal" in its name.
# Never creates, drops, empties or changes a production schema.
# Set M10_RESTORE_HOST, M10_RESTORE_DB, M10_RESTORE_USER,
# M10_RESTORE_PASSWORD (optional M10_RESTORE_PORT) via a secure shell session.
set -Eeuo pipefail
umask 077
if [[ $# -ne 2 || "$1" != "--backup-dir" ]]; then
  echo "M10_RESTORE_BLOCKED: Need --backup-dir <M9 snapshot>." >&2
  exit 2
fi
backup="$2"
case "$backup" in
  "$HOME"/sign2digital-release-backups/m8_*) ;;
  *) echo "M10_RESTORE_BLOCKED: Untrusted snapshot directory." >&2; exit 2 ;;
esac
for cmd in mysql php sha256sum gzip mktemp grep; do
  command -v "$cmd" >/dev/null || { echo "M10_RESTORE_BLOCKED: Missing $cmd." >&2; exit 2; }
done
if [[ ! -f artisan || ! -f .env || ! -f vendor/autoload.php ||
      ! -f "$backup/SHA256SUMS.txt" || ! -f "$backup/mysql.sql.gz" ||
      ! -f "$backup/m9-release-manifest.txt" ]]; then
  echo "M10_RESTORE_BLOCKED: Missing Laravel installation or backup." >&2; exit 2
fi
cnf="$(mktemp)"
chmod 600 "$cnf"
trap 'rm -f "$cnf"' EXIT
# Generate secure client options via PHP to avoid printing credentials.
php -r '
$keys=["user"=>"M10_RESTORE_USER","password"=>"M10_RESTORE_PASSWORD",
  "host"=>"M10_RESTORE_HOST","port"=>"M10_RESTORE_PORT"];
$text="[client]\n";
foreach($keys as $name=>$env){
    $v=getenv($env);
    if($v===false&&$name==="port")$v="3306";
    if($v===false||$v===""||preg_match("/[\r\n]/",(string)$v))exit(2);
    $text.=$name."=\"".addcslashes((string)$v,"\\\\\"")."\"\n";
}
$db=getenv("M10_RESTORE_DB");
if(!$db||!preg_match("/^[a-zA-Z0-9_]+$/D",$db)||
  !str_contains($db,"restore")&&!str_contains($db,"rehearsal"))exit(3);
if(file_put_contents($argv[1],$text,LOCK_EX)!==strlen($text))exit(2);
@chmod($argv[1],0600);
' "$cnf" || { echo "M10_RESTORE_BLOCKED: Need separate nonproduction DB credentials and named target." >&2;exit 2; }
target="$M10_RESTORE_DB"
production="$(php -r '
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::connection()->getDatabaseName();
')"
if [[ -z "$production" || "$target" == "$production" ]]; then
  echo "M10_RESTORE_BLOCKED: Target cannot be the live database." >&2;exit 2
fi
(
  cd "$backup"
  sha256sum -c SHA256SUMS.txt >/dev/null
  gzip -t mysql.sql.gz
)
grep -qx 'release=M9' "$backup/m9-release-manifest.txt" || {
  echo "M10_RESTORE_BLOCKED: Snapshot is not an M9 release backup." >&2;exit 2;
}
mysql --defaults-extra-file="$cnf" --database="$target" --batch \
  --skip-column-names --execute='SELECT 1' >/dev/null
before="$(mysql --defaults-extra-file="$cnf" --database="$target" --batch \
  --skip-column-names --execute='SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')"
if [[ "$before" != "0" ]]; then
  echo "M10_RESTORE_BLOCKED: The nonproduction target already contains tables." >&2;exit 2
fi
echo "M10_RESTORE_REHEARSAL_BEGIN: Empty isolated target verified."
gzip -dc "$backup/mysql.sql.gz" | mysql --defaults-extra-file="$cnf" \
  --database="$target" --binary-mode
after="$(mysql --defaults-extra-file="$cnf" --database="$target" --batch \
  --skip-column-names --execute='SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')"
migration="$(mysql --defaults-extra-file="$cnf" --database="$target" --batch \
  --skip-column-names --execute="SELECT COUNT(*) FROM migrations WHERE migration='2026_11_04_030000_create_document_management_tables'")"
if [[ ! "$after" =~ ^[1-9][0-9]*$ || "$migration" != "1" ]]; then
  echo "M10_RESTORE_BLOCKED: MySQL restore or M9 baseline failed." >&2; exit 1
fi
# Record the SHA of the VERIFIED snapshot. Deployment must check this
# marker against the exact same backup and never treat a CRC alone as restore.
manifest_hash="$(sha256sum "$backup/SHA256SUMS.txt" | cut -d ' ' -f 1)"
printf 'release=M10\nrestored=M9\nsnapshot_manifest_sha256=%s\nrestored_tables=%s\nverified_utc=%s\n' \
  "$manifest_hash" "$after" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  > "$backup/m10-restore-rehearsal.txt"
chmod 600 "$backup/m10-restore-rehearsal.txt"
echo "M10_RESTORE_REHEARSAL_PASS"
echo "M10_RESTORED_M9_TABLES $after"
echo "INFO: The isolated database is retained for inspection. Production was not changed."
