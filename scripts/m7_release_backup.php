<?php

/**
 * Sign2Digital M7 production backup helper. Execute from Laravel's repository
 * root by piping this reviewed file into the PHP CLI; it uses getcwd().
 *
 * Captures .env, full MySQL SQL dump, and storage/app/private under storage/backups.
 * Never echoes credentials or command errors that may include secrets.
 * Aborts on any failure. Does not migrate, checkout, email, or change live data.
 */
declare(strict_types=1);

$root = getcwd();
if (!is_string($root) || !is_file($root.'/.env') ||
    !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "BLOCK: Run inside installed Laravel backend root.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('production') || config('database.default') !== 'mysql') {
    fwrite(STDERR, "BLOCK: Expected the production MySQL environment.\n");
    exit(1);
}
$c = (array) config('database.connections.mysql');
$database = (string) ($c['database'] ?? '');
$username = (string) ($c['username'] ?? '');
$password = (string) ($c['password'] ?? '');
$host = (string) ($c['host'] ?? 'localhost');
$port = (int) ($c['port'] ?? 3306);
if ($database === '' || $username === '' || $host === '' || $port <= 0) {
    fwrite(STDERR, "BLOCK: MySQL configuration is incomplete.\n");
    exit(1);
}
$private = $root.'/storage/app/private';
if (!is_dir($private) || !is_readable($private)) {
    fwrite(STDERR, "BLOCK: Private storage could not be read.\n");
    exit(1);
}
$folder = $root.'/storage/backups/sign2digital-m7-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
$oldUmask = umask(0077);
$clientFile = null;
try {
    if (!mkdir($folder, 0700, true)) {
        throw new \RuntimeException('Unable to create backup directory.');
    }
    if (!copy($root.'/.env', $folder.'/env.before-m7')) {
        throw new \RuntimeException('Unable to preserve current .env.');
    }
    chmod($folder.'/env.before-m7', 0600);

    $escape = static fn (string $value): string => '"'.
        str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
    $clientFile = $folder.'/.mysqldump-client.cnf';
    $config = "[client]\n".
        'user='.$escape($username)."\n".
        'password='.$escape($password)."\n";
    $socket = trim((string) ($c['unix_socket'] ?? ''));
    if ($socket !== '') {
        $config .= 'socket='.$escape($socket)."\n";
    } else {
        $config .= 'host='.$escape($host)."\n".'port='.$port."\n";
    }
    if (file_put_contents($clientFile, $config, LOCK_EX) === false) {
        throw new \RuntimeException('Unable to prepare protected database credentials.');
    }
    chmod($clientFile, 0600);

    $run = static function (array $command): void {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            throw new \RuntimeException('Backup executable unavailable.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        // Suppress raw tool errors: some clients may echo access credentials.
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('A backup command returned a failure.');
        }
    };

    $sql = $folder.'/mysql.before-m7.sql';
    $run([
        'mysqldump', '--defaults-extra-file='.$clientFile,
        '--single-transaction', '--quick', '--no-tablespaces',
        '--hex-blob', '--result-file='.$sql, $database,
    ]);
    unlink($clientFile);
    $clientFile = null;
    clearstatcache(true, $sql);
    if (!is_file($sql) || filesize($sql) < 200) {
        throw new \RuntimeException('Database backup is empty or incomplete.');
    }
    chmod($sql, 0600);
    $tar = $folder.'/private.before-m7.tar.gz';
    $run(['tar', '-czf', $tar, '-C', $root.'/storage/app', 'private']);
    clearstatcache(true, $tar);
    if (!is_file($tar) || filesize($tar) < 30) {
        throw new \RuntimeException('Private storage backup was not created.');
    }
    chmod($tar, 0600);

    $manifest = [
        'created_utc' => gmdate(DATE_ATOM),
        'old_commit' => trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')),
        'mysql_sha256' => hash_file('sha256', $sql),
        'mysql_bytes' => filesize($sql),
        'private_sha256' => hash_file('sha256', $tar),
        'private_bytes' => filesize($tar),
        'env_sha256' => hash_file('sha256', $folder.'/env.before-m7'),
    ];
    file_put_contents($folder.'/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    chmod($folder.'/manifest.json', 0600);
    echo "M7_BACKUP_OK\n";
    echo "BACKUP_DIRECTORY=".$folder."\n";
    echo "OLD_COMMIT=".$manifest['old_commit']."\n";
    echo "MYSQL_BYTES=".$manifest['mysql_bytes']."\n";
    echo "PRIVATE_BYTES=".$manifest['private_bytes']."\n";
    echo "BACKUP_SHA256_VERIFIED\n";
    echo "INFO: Keep this directory private. This script did not deploy anything.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, "M7_BACKUP_FAILED: No deployment performed. ".
        "Review available disk space, MySQL privileges, and backup utilities.\n");
    exit(1);
} finally {
    if ($clientFile !== null && is_file($clientFile)) {
        unlink($clientFile);
    }
    umask($oldUmask);
}
