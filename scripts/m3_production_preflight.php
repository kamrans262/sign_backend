<?php

/**
 * Sign2Digital M3 release preflight.
 *
 * Run ONLY after taking independent database and private-file backups.
 * This script reads configuration, routes, filesystem permissions and DB
 * metadata; it never runs migrations, writes data, sends mail or changes
 * source control. Do not print .env values or secrets in release logs.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "BLOCKED: Laravel .env or installed Composer dependencies are missing.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$checks = [];
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks[] = $ok;
    printf("%s %s\n", $ok ? 'PASS' : 'BLOCK', $label);
};

$check('PHP >= 8.4.1 (current composer.lock Symfony requirements)', PHP_VERSION_ID >= 80401);
$check('Production environment', app()->environment('production'));
$check('Application debug disabled', config('app.debug') === false);
$check('HTTPS application URL', parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https');
$check('MySQL selected as active database', config('database.default') === 'mysql');

try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1 AS ready');
    $check('Configured database is reachable (read only)', true);
} catch (\Throwable $e) {
    $check('Configured database is reachable (read only)', false);
}

$mailer = (string) config('mail.default');
$check('Mail transport configured (not log/array)', $mailer !== '' &&
    !in_array($mailer, ['log', 'array'], true));

$storageRoot = storage_path('app');
$check('Laravel private storage parent is writable', is_dir($storageRoot) &&
    is_writable($storageRoot));

$registered = [];
foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
    $registered[] = $route->uri();
}
foreach ([
    'api/v1/companies',
    'api/v1/companies/{company}/switch',
    'api/v1/companies/{company}/members',
    'api/v1/companies/{company}/invitations',
    'api/v1/companies/invitations/accept',
    'admin/companies/{company}/members/{user}/edit',
] as $requiredRoute) {
    $check('Registered route: '.$requiredRoute, in_array($requiredRoute, $registered, true));
}

try {
    $schema = \Illuminate\Support\Facades\Schema::connection('mysql');
    $m3Migrated = $schema->hasTable('company_invitations')
        && $schema->hasColumn('users', 'active_company_id')
        && $schema->hasColumn('companies', 'logo_original_name')
        && $schema->hasColumn('companies', 'terms_original_name');
    echo ($m3Migrated ? 'M3_SCHEMA_PRESENT' : 'M3_SCHEMA_PENDING').": Review php artisan migrate:status before release.\n";
    // Pending migrations may be intentional before the approved migration
    // step; this is reported, not hidden or applied automatically.
} catch (\Throwable $e) {
    $check('Read-only M3 database schema inspection', false);
}

$ready = !in_array(false, $checks, true);
echo $ready
    ? "PREFLIGHT_PASS: configuration is eligible for manual release checks. No deployment occurred.\n"
    : "PREFLIGHT_BLOCKED: correct the failed checks before deployment. No deployment occurred.\n";
exit($ready ? 0 : 1);
