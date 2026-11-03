<?php

/**
 * Sign2Digital M3 + M4 combined deployment preflight (READ ONLY).
 *
 * Does NOT deploy, migrate, write data, modify .env, or send email.
 * Run before a future approved release, then rerun with
 * --require-migrated after its backed-up migration is finished.
 * Never print configuration values, tokens, or passwords.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "BLOCK: Installed Laravel .env and vendor packages are required. No changes made.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$errors = 0;
$check = static function (string $label, bool $ok) use (&$errors): void {
    echo ($ok ? 'PASS' : 'BLOCK').' '.$label.PHP_EOL;
    if (!$ok) ++$errors;
};
$check('PHP >= 8.4.1 (current composer.lock)', PHP_VERSION_ID >= 80401);
$check('Production environment', app()->environment('production'));
$check('APP_DEBUG disabled', config('app.debug') === false);
$check('HTTPS APP_URL', parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https');
$check('MySQL default database', config('database.default') === 'mysql');
$mailer = (string) config('mail.default');
$check('Real email mailer configured', $mailer !== '' && !in_array($mailer, ['log', 'array'], true));
$check('Private application storage writable',
    is_dir(storage_path('app')) && is_writable(storage_path('app')));

$dbReady = false;
try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1 AS ok');
    $dbReady = true;
} catch (\Throwable $e) {
    // Intentionally omit exception details: connection errors can disclose secrets.
}
$check('Read-only database connectivity', $dbReady);

$routes = \Illuminate\Support\Facades\Route::getRoutes();
$required = [
    ['GET', 'api/v1/companies', null],
    ['POST', 'api/v1/companies', null],
    ['POST', 'api/v1/companies/{company}/switch', null],
    ['GET', 'api/v1/companies/{company}/members', null],
    ['GET', 'admin/companies/{company}/members/{user}/edit', 'platform.admin'],
    ['GET', 'admin/clients', 'platform.admin'],
    ['GET', 'api/v1/clients', 'company.permission:clients.read'],
    ['POST', 'api/v1/clients', 'company.permission:clients.write'],
    ['GET', 'api/v1/clients/{client}', 'company.permission:clients.read'],
    ['PATCH', 'api/v1/clients/{client}', 'company.permission:clients.write'],
    ['DELETE', 'api/v1/clients/{client}', 'company.permission:clients.write'],
    ['POST', 'api/v1/clients/import', 'company.permission:clients.write'],
];
foreach ($required as [$method, $uri, $middleware]) {
    $matches = [];
    foreach ($routes as $route) {
        if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
            $matches[] = $route;
        }
    }
    $check('Single route: '.$method.' /'.$uri, count($matches) === 1);
    if (count($matches) === 1 && $middleware !== null) {
        $guard = $matches[0]->gatherMiddleware();
        $check('Required middleware on '.$method.' /'.$uri,
            in_array($middleware, $guard, true) &&
            ($middleware === 'platform.admin' ||
                (in_array('company.access', $guard, true) &&
                    in_array('auth:sanctum', $guard, true))));
    }
}

$m3 = false;
$m4 = false;
if ($dbReady) {
    try {
        $schema = \Illuminate\Support\Facades\Schema::connection('mysql');
        $m3 = $schema->hasTable('company_invitations')
            && $schema->hasColumn('users', 'active_company_id')
            && $schema->hasColumn('companies', 'logo_original_name')
            && $schema->hasColumn('companies', 'terms_original_name');
        $m4 = $schema->hasTable('clients')
            && $schema->hasColumn('clients', 'company_id')
            && $schema->hasColumn('clients', 'deleted_at')
            && $schema->hasColumn('clients', 'source');
    } catch (\Throwable $e) {
        $check('Read-only M3/M4 database schema inspection', false);
    }
}
echo ($m3 ? 'M3_SCHEMA_PRESENT' : 'M3_SCHEMA_PENDING').PHP_EOL;
echo ($m4 ? 'M4_SCHEMA_PRESENT' : 'M4_SCHEMA_PENDING').PHP_EOL;
if (in_array('--require-migrated', $argv, true)) {
    $check('M3 migration complete', $m3);
    $check('M4 migration complete', $m4);
} else {
    echo "INFO: Schema may be pending before the approved combined deployment. No migration executed.\n";
}

echo $errors === 0
    ? "PREFLIGHT_PASS (read-only; no deployment).\n"
    : "PREFLIGHT_BLOCKED (read-only; no deployment).\n";
exit($errors === 0 ? 0 : 1);
