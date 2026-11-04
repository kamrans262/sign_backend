#!/usr/bin/env bash
# M8 backup only: never deploys, migrates, switches branches or deletes files.
# Invoke from Laravel root: bash scripts/m8_release_backup.sh --backup-only
set -Eeuo pipefail
umask 077

if [[ "$1" != "--backup-only" || ! -f .env || ! -f artisan || ! -f vendor/autoload.php ]]; then
  echo "BLOCK: Run from installed sign_backend with --backup-only." >&2
  exit 2
fi
for command in php mysqldump tar gzip sha256sum git mktemp; do
  command -v "$command" >/dev/null || { echo "BLOCK: Missing $command" >&2; exit 2; }
done

destination="$HOME/sign2digital-release-backups"
mkdir -p "$destination"
chmod 700 "$destination"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="$destination/m8_$timestamp"
mkdir -m 700 "$out"
cnf="$(mktemp)"
chmod 600 "$cnf"
trap 'rm -f "$cnf"' EXIT
trap 'echo "BLOCK: Incomplete backup. Do not deploy." >&2' ERR

# Credentials go to a 0600 temporary client option file, not to stdout, the
# process argument list, Git, shell history, or the public web directory.
php -r '
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config("database.default")!=="mysql")exit(2);
$c=Illuminate\Support\Facades\DB::connection()->getConfig();
if(empty($c["username"]))exit(2);
$quote=static function($v){
    $s=(string)($v??"");
    if(preg_match("/[\r\n]/",$s))exit(2);
    return "\"".addcslashes($s,"\\\\\"")."\"";
};
$values=["user"=>$c["username"],"password"=>$c["password"]??"",
    "host"=>$c["host"]??"localhost","port"=>$c["port"]??3306];
$text="[client]\n";
foreach($values as $key=>$value)$text.=$key."=".$quote($value)."\n";
if(file_put_contents($argv[1],$text,LOCK_EX)!==strlen($text))exit(2);
@chmod($argv[1],0600);
' "$cnf"

db="$(php -r '
require getcwd()."/vendor/autoload.php";
$app=require getcwd()."/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=(string)Illuminate\Support\Facades\DB::connection()->getDatabaseName();
if($db===""||preg_match("/[\r\n]/",$db))exit(2);
echo $db;
')"
test -n "$db"

mysqldump --defaults-extra-file="$cnf" --single-transaction \
  --quick --no-tablespaces --triggers --skip-lock-tables \
  --default-character-set=utf8mb4 "$db" | gzip -c > "$out/mysql.sql.gz"
gzip -t "$out/mysql.sql.gz"
test -s "$out/mysql.sql.gz"

test -d storage/app/private
tar -czf "$out/private-pdfs.tar.gz" storage/app/private
tar -tzf "$out/private-pdfs.tar.gz" >/dev/null
if test -f bootstrap/cache/config.php; then
  tar -czf "$out/env-config.tar.gz" .env bootstrap/cache/config.php
else
  tar -czf "$out/env-config.tar.gz" .env
fi
tar -tzf "$out/env-config.tar.gz" >/dev/null

git rev-parse HEAD > "$out/production-head.txt"
git branch --show-current > "$out/production-branch.txt"
(
  cd "$out"
  sha256sum mysql.sql.gz private-pdfs.tar.gz env-config.tar.gz \
    production-head.txt production-branch.txt > SHA256SUMS.txt
  sha256sum -c SHA256SUMS.txt >/dev/null
)
chmod 600 "$out"/*
echo "M8_BACKUP_CREATED"
echo "M8_BACKUP_CHECKSUMS_PASS"
echo "M8_BACKUP_DIRECTORY $out"
echo "RESTORE_TEST_REQUIRED: A checksum is not proof that a separate MySQL restore succeeds."
