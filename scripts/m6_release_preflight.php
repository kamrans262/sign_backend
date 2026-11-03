<?php

/**
 * Sign2Digital M3–M6 future combined deployment preflight, READ ONLY.
 *
 * No migration, database write, deployment, file modification, email send,
 * environment dump, credentials or private data disclosure occurs here.
 * Default mode permits pending schemas BEFORE the reviewed release.
 * Use --require-migrated AFTER independently approved, backed-up migrations.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "BLOCK: Laravel installation incomplete; no changes made.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$errors = 0;
$check = static function (string $name, bool $ok) use (&$errors): void {
    echo ($ok ? 'PASS ' : 'BLOCK ').$name.PHP_EOL;
    if (!$ok) ++$errors;
};

$check('Runtime PHP >= 8.4.1 for locked dependencies', PHP_VERSION_ID >= 80401);
$check('Production environment', app()->environment('production'));
$check('Debug mode disabled', config('app.debug') === false);
$check('App URL uses HTTPS', parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https');
$check('Database default uses MySQL', config('database.default') === 'mysql');
$check('Private app storage available',
    is_dir(storage_path('app')) && is_writable(storage_path('app')));

$dbReady = false;
try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1 AS ok');
    $dbReady = true;
} catch (\Throwable $e) {
    // Never print a raw PDO/DB exception; it may include credentials.
}
$check('Read-only MySQL connectivity', $dbReady);

$routes = \Illuminate\Support\Facades\Route::getRoutes();
$required = [
    ['GET', 'api/v1/jobs', 'company.permission:jobs.read'],
    ['POST', 'api/v1/jobs', 'company.permission:jobs.write'],
    ['GET', 'api/v1/jobs/{job}', 'company.permission:jobs.read'],
    ['PATCH', 'api/v1/jobs/{job}', 'company.permission:jobs.write'],
    ['DELETE', 'api/v1/jobs/{job}', 'company.permission:jobs.write'],
    ['POST', 'api/v1/jobs/{job}/restore', 'company.permission:jobs.write'],
    ['GET', 'admin/jobs', 'platform.admin'],
    ['GET', 'admin/jobs/create', 'platform.admin'],
    ['GET', 'admin/jobs/{job}/edit', 'platform.admin'],
    ['POST', 'admin/jobs', 'platform.admin'],
    ['PUT', 'admin/jobs/{job}', 'platform.admin'],
    ['DELETE', 'admin/jobs/{job}', 'platform.admin'],
    ['POST', 'admin/jobs/{job}/restore', 'platform.admin'],
    ['GET', 'api/v1/quotes', 'company.permission:quotes.read'],
    ['GET', 'api/v1/quotes/{quote}', 'company.permission:quotes.read'],
    ['GET', 'api/v1/quotes/{quote}/revisions', 'company.permission:quotes.read'],
    ['POST', 'api/v1/quotes', 'company.permission:quotes.write'],
    ['PATCH', 'api/v1/quotes/{quote}', 'company.permission:quotes.write'],
    ['DELETE', 'api/v1/quotes/{quote}', 'company.permission:quotes.write'],
    ['POST', 'api/v1/quotes/{quote}/approve', 'company.permission:quotes.write'],
    ['POST', 'api/v1/quotes/{quote}/share', 'company.permission:quotes.write'],
    ['POST', 'api/v1/quotes/prepare/{method}', 'company.permission:quotes.write'],
    ['GET', 'admin/quotes', 'platform.admin'],
    ['GET', 'admin/quotes/create', 'platform.admin'],
    ['GET', 'admin/quotes/{quote}/edit', 'platform.admin'],
    ['POST', 'admin/quotes', 'platform.admin'],
    ['PUT', 'admin/quotes/{quote}', 'platform.admin'],
    ['POST', 'admin/quotes/{quote}/approve', 'platform.admin'],
    ['DELETE', 'admin/quotes/{quote}', 'platform.admin'],
    ['POST', 'admin/quotes/{quote}/restore', 'platform.admin'],
];
foreach ($required as [$method, $uri, $permission]) {
    $found = [];
    foreach ($routes as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            $found[] = $route;
        }
    }
    $check('Unique '.$method.' /'.$uri, count($found) === 1);
    if (count($found) !== 1) continue;
    $guard = $found[0]->gatherMiddleware();
    $ok = in_array($permission, $guard, true);
    if ($permission !== 'platform.admin') {
        $ok = $ok && in_array('company.access', $guard, true)
            && in_array('auth:sanctum', $guard, true);
    }
    $check('Authorization '.$method.' /'.$uri, $ok);
}

$m3 = $m4 = $m5 = $m6 = false;
if ($dbReady) {
    try {
        $schema = \Illuminate\Support\Facades\Schema::connection('mysql');
        $m3 = $schema->hasTable('company_invitations')
            && $schema->hasColumn('users', 'active_company_id');
        $m4 = $schema->hasTable('clients')
            && $schema->hasColumn('clients', 'company_id')
            && $schema->hasColumn('clients', 'deleted_at');
        $m5 = $schema->hasTable('service_jobs')
            && $schema->hasTable('service_job_user')
            && $schema->hasColumn('service_jobs', 'company_id')
            && $schema->hasColumn('service_jobs', 'client_id')
            && $schema->hasColumn('service_jobs', 'reference_number')
            && $schema->hasColumn('service_jobs', 'category')
            && $schema->hasColumn('service_jobs', 'archived_at');
        $m6 = $schema->hasTable('service_quotes')
            && $schema->hasTable('service_quote_items')
            && $schema->hasTable('service_quote_revisions')
            && $schema->hasColumn('service_quotes', 'company_id')
            && $schema->hasColumn('service_quotes', 'reference_number')
            && $schema->hasColumn('service_quotes', 'custom_quote_number')
            && $schema->hasColumn('service_quotes', 'link_version')
            && $schema->hasColumn('service_quotes', 'revision')
            && $schema->hasColumn('service_quotes', 'total_minor');
    } catch (\Throwable $e) {
        $check('Read-only schema inspection', false);
    }
}
foreach (['M3' => $m3, 'M4' => $m4, 'M5' => $m5, 'M6' => $m6] as $module => $present) {
    echo $module.($present ? '_SCHEMA_PRESENT' : '_SCHEMA_PENDING').PHP_EOL;
    if (in_array('--require-migrated', $argv, true)) {
        $check($module.' migration complete', $present);
    }
}
if (!in_array('--require-migrated', $argv, true)) {
    echo "INFO: Pending schema is expected before the future approved rollout. No migration was executed.\n";
}
// Public access is only valid through signed URLs. Never print the URL,
// access token, APP_KEY or OPENAI_API_KEY during this preflight.
$public = $routes->getByName('quotes.public');
$check('Signed public quote route is protected',
    $public !== null && in_array('signed', $public->gatherMiddleware(), true)
    && $public->uri() === 'quote-view/{quote}');

$aiReady = trim((string) config('services.openai.key')) !== '';
$mailDriver = (string) config('mail.default');
$mailReady = !in_array($mailDriver, ['log','array','null'], true);
echo ($aiReady ? 'AI_KEY_CONFIGURED' : 'AI_KEY_NOT_CONFIGURED').PHP_EOL;
echo ($mailReady ? 'EMAIL_TRANSPORT_CONFIGURED' : 'EMAIL_TRANSPORT_NOT_CONFIGURED').PHP_EOL;
if (in_array('--require-live-services', $argv, true)) {
    $check('Live AI key configured only in Laravel', $aiReady);
    $check('Transactional email transport configured', $mailReady);
} else {
    echo "INFO: Live AI and SMTP are optional until the separately approved end-to-end test.\n";
}
echo "INFO: Preflight is read-only. Backup, migration and release approval are separate mandatory steps.\n";

echo $errors === 0
    ? "M6_PREFLIGHT_PASS (read-only; NOT deployed).\n"
    : "M6_PREFLIGHT_BLOCKED (read-only; NOT deployed).\n";
exit($errors === 0 ? 0 : 1);
