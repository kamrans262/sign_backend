<?php

/**
 * Sign2Digital M7 release preflight. READ ONLY.
 *
 * No branch switches, migrations, database writes, files, emails or secrets.
 * Run on a reviewed Hostinger checkout AFTER a verified database backup.
 * With --require-migrated, the M7 add-only schema must already be present.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"BLOCK: Installed Laravel runtime is incomplete.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$errors=0;
$check=static function (string $label,bool $passed) use (&$errors):void {
    echo ($passed?'PASS ':'BLOCK ').$label.PHP_EOL;
    if (!$passed) ++$errors;
};

$check('Production environment',app()->environment('production'));
$check('Debug mode disabled',config('app.debug')===false);
$check('HTTPS app URL',parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
$check('MySQL is configured',config('database.default')==='mysql');
$check('Private approved PDF root writable',
    is_dir(storage_path('app/private')) && is_writable(storage_path('app/private')));
$check('No public approved PDF storage directory',
    !is_dir(public_path('quotes/approved')));

$ready=false;
try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1 AS ok');
    $ready=true;
} catch (\Throwable $e) {
    // Never print PDO exceptions; they may include sensitive connection details.
}
$check('Read-only database connection',$ready);

$schemaOk=false;
if ($ready) {
    try {
        $schema=\Illuminate\Support\Facades\Schema::connection('mysql');
        $schemaOk=$schema->hasTable('service_quotes') &&
            $schema->hasColumn('service_quotes','approved_pdf_path') &&
            $schema->hasColumn('service_quotes','approved_pdf_sha256') &&
            $schema->hasColumn('service_quotes','approved_pdf_revision');
    } catch (\Throwable $e) {
        $schemaOk=false;
    }
}
echo $schemaOk?"M7_SCHEMA_PRESENT\n":"M7_SCHEMA_PENDING\n";
if (in_array('--require-migrated',$argv,true)) {
    $check('M7 additive migration installed',$schemaOk);
} else {
    echo "INFO: Pending M7 migration is expected before the separately approved rollout.\n";
}

$routes=\Illuminate\Support\Facades\Route::getRoutes();
foreach ([
    ['GET','api/v1/quotes/{quote}/approved-pdf','company.permission:quotes.read'],
    ['POST','api/v1/quotes/{quote}/approve','company.permission:quotes.write'],
    ['POST','api/v1/quotes/{quote}/share-email-pdf','company.permission:quotes.write'],
    ['GET','admin/quotes/{quote}/approved-pdf','platform.admin'],
    ['POST','admin/quotes/{quote}/approve','platform.admin'],
] as [$method,$uri,$permission]) {
    $matches=[];
    foreach ($routes as $route) {
        if ($route->uri()===$uri && in_array($method,$route->methods(),true)) {
            $matches[]=$route;
        }
    }
    $guard=count($matches)===1?$matches[0]->gatherMiddleware():[];
    $ok=count($matches)===1 && in_array($permission,$guard,true);
    if ($permission!=='platform.admin') {
        $ok=$ok && in_array('company.access',$guard,true) &&
            in_array('auth:sanctum',$guard,true);
    } else {
        $ok=$ok && in_array('auth',$guard,true);
    }
    $check('Route authorization '.$method.' /'.$uri,$ok);
}
$public=$routes->getByName('quotes.public');
$check('Public quotes require signed expiring URLs',
    $public!==null && in_array('signed',$public->gatherMiddleware(),true));

$driver=(string)config('mail.default');
echo in_array($driver,['log','array','null'],true)
    ? "EMAIL_TRANSPORT_NOT_CONFIGURED\n":"EMAIL_TRANSPORT_CONFIGURED\n";
echo "INFO: This preflight is read-only. It does not authorize deployment.\n";
echo $errors===0?"M7_PREFLIGHT_PASS\n":"M7_PREFLIGHT_BLOCKED\n";
exit($errors===0?0:1);
