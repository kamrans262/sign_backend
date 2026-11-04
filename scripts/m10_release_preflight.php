<?php
/**
 * Sign2Digital M10 read-only pre/post release check.
 * Usage:
 *   php scripts/m10_release_preflight.php
 *   php scripts/m10_release_preflight.php --require-migrated
 *
 * No backups, migrations, email, API calls, write operations or user secrets.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"M10_BLOCK: Laravel installation or environment is missing.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$blocks=0;
$assert=static function (string $message,bool $passed) use (&$blocks): void {
    echo ($passed ? 'PASS ' : 'BLOCK ').$message.PHP_EOL;
    if (!$passed) $blocks++;
};
$assert('Production mode',app()->environment('production'));
$assert('Debug disabled',config('app.debug')===false);
$assert('HTTPS APP_URL',parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
$assert('PHP 8.3+',PHP_VERSION_ID >= 80300);
$assert('MySQL selected',config('database.default')==='mysql');
$assert('M7/M8/M9 services are installed',
    class_exists(\App\Services\SignatureCompletionService::class) &&
    class_exists(\App\Services\DocumentCatalogService::class) &&
    class_exists(\App\Services\SignatureWorkflowService::class));
$assert('M10 source, PDF and model services installed',
    class_exists(\App\Services\AddendumService::class) &&
    class_exists(\App\Models\Addendum::class) &&
    class_exists(\App\Models\AddendumSigningRequest::class));
$disk=\Illuminate\Support\Facades\Storage::disk('local');
$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$assert('Writable private storage outside web public root',
    is_string($private) && is_dir($private) && is_writable($private) &&
    is_string($public) &&
    !str_starts_with($private.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR) &&
    !str_starts_with($public.DIRECTORY_SEPARATOR,$private.DIRECTORY_SEPARATOR));

$database=false;$m9=false;$m10=false;
try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1');
    $database=true;
    $schema=\Illuminate\Support\Facades\Schema::connection('mysql');
    $m9=$schema->hasTable('managed_documents') &&
        $schema->hasTable('signed_document_artifacts') &&
        $schema->hasTable('signature_requests') &&
        $schema->hasColumn('service_quotes','approved_pdf_sha256') &&
        \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration','2026_11_04_030000_create_document_management_tables')->exists();
    $tables=['addenda','addendum_revisions','addendum_events','addendum_signing_requests'];
    $m10=true;
    foreach($tables as $table) if (!$schema->hasTable($table)) $m10=false;
    if ($m10) {
        foreach([
            ['addenda','source_signed_artifact_id'],
            ['addenda','source_pdf_sha256'],
            ['addenda','signed_pdf_sha256'],
            ['addenda','approved_revision'],
            ['addendum_signing_requests','token_sha256'],
            ['addendum_signing_requests','consent_reference'],
            ['addendum_revisions','snapshot'],
            ['addendum_events','event_type'],
        ] as [$table,$column]) {
            if (!$schema->hasColumn($table,$column)) $m10=false;
        }
        $m10=$m10 && \Illuminate\Support\Facades\DB::table('migrations')
            ->where('migration','2026_11_04_040000_create_addenda_management_tables')->exists();
    }
} catch (\Throwable $error) {
    $database=false;$m9=false;$m10=false;
}
$assert('MySQL reachable',$database);
$assert('M9 baseline schema and migration present',$m9);
echo $m10 ? "M10_SCHEMA_INSTALLED\n" : "M10_SCHEMA_PENDING\n";
if (in_array('--require-migrated',$argv,true)) {
    $assert('M10 schema and migration installed',$m10);
}

$routes=\Illuminate\Support\Facades\Route::getRoutes();
foreach([
    ['GET','api/v1/addenda','documents.read'],
    ['GET','api/v1/addenda/{addendum}','documents.read'],
    ['GET','api/v1/addenda/{addendum}/history','documents.read'],
    ['GET','api/v1/addenda/{addendum}/pdf','documents.read'],
    ['GET','api/v1/addenda/{addendum}/signature-requests','documents.read'],
    ['POST','api/v1/addenda','documents.write'],
    ['PATCH','api/v1/addenda/{addendum}','documents.write'],
    ['POST','api/v1/addenda/{addendum}/approve','documents.write'],
    ['POST','api/v1/addenda/{addendum}/signature-requests','documents.write'],
    ['POST','api/v1/addenda/{addendum}/signature-requests/{signature}/revoke','documents.write'],
    ['POST','api/v1/addenda/prepare/voice','documents.write'],
] as [$method,$uri,$perm]) {
    $hits=0;
    foreach ($routes as $route) {
        if ($route->uri()!==$uri || !in_array($method,$route->methods(),true)) continue;
        $middleware=$route->gatherMiddleware();
        if (in_array('auth:sanctum',$middleware,true) &&
            in_array('active.user',$middleware,true) &&
            in_array('company.access',$middleware,true) &&
            in_array('company.permission:'.$perm,$middleware,true)) $hits++;
    }
    $assert('Tenant route '.$method.' '.$uri,$hits===1);
}

foreach([
    'addenda.public.browser'=>'GET',
    'addenda.public.pdf'=>'GET',
    'addenda.public.submit'=>'POST',
    'addenda.public.receipt'=>'GET',
    'addenda.public.signedPdf'=>'GET',
    'addenda.public.api'=>'GET',
    'addenda.public.pdf.api'=>'GET',
    'addenda.public.submit.api'=>'POST',
    'addenda.public.receipt.api'=>'GET',
    'addenda.public.signedPdf.api'=>'GET',
] as $name=>$method) {
    $route=$routes->getByName($name);
    $mw=$route?->gatherMiddleware() ?? [];
    $assert('Public token-only limited route '.$name,
        $route!==null && in_array($method,$route->methods(),true) &&
        !in_array('auth:sanctum',$mw,true) &&
        (bool)array_filter($mw,fn($v)=>str_starts_with($v,'throttle:')));
}

foreach([
    'admin.addenda.index'=>'GET','admin.addenda.show'=>'GET',
    'admin.addenda.create'=>'GET','admin.addenda.store'=>'POST',
    'admin.addenda.edit'=>'GET','admin.addenda.update'=>'PUT',
    'admin.addenda.approve'=>'POST','admin.addenda.pdf'=>'GET',
    'admin.addenda.signature'=>'POST','admin.addenda.revoke'=>'POST',
] as $name=>$method) {
    $route=$routes->getByName($name);
    $mw=$route?->gatherMiddleware() ?? [];
    $assert('Platform admin route '.$name,
        $route!==null && in_array($method,$route->methods(),true) &&
        in_array('auth',$mw,true) && in_array('platform.admin',$mw,true));
}

$maxSample=10;$checked=0;$integrity=true;$source=true;$tenant=true;$state=true;
$counts=['addenda'=>0,'addendum_revisions'=>0,
    'addendum_signing_requests'=>0,'addendum_events'=>0];
if ($m10 && $database) {
    try {
        $db=\Illuminate\Support\Facades\DB::connection();
        foreach ($counts as $name=>$_) $counts[$name]=$db->table($name)->count();
        $tenant=$db->table('addenda as a')
            ->join('signed_document_artifacts as s',
                's.id','=','a.source_signed_artifact_id')
            ->whereColumn('a.company_id','!=','s.company_id')->doesntExist();
        foreach (['addendum_revisions','addendum_signing_requests','addendum_events'] as $t) {
            $tenant=$tenant && $db->table($t.' as x')
                ->join('addenda as a','a.id','=','x.addendum_id')
                ->whereColumn('x.company_id','!=','a.company_id')->doesntExist();
        }
        foreach ($db->table('addenda')->orderByDesc('id')->limit($maxSample)->get()
            as $row) {
            $checked++;
            $artifact=\App\Models\SignedDocumentArtifact::query()
                ->where('company_id',$row->company_id)
                ->find($row->source_signed_artifact_id);
            if (!$artifact ||
                !hash_equals((string)$row->source_pdf_sha256,
                    (string)$artifact->signed_pdf_sha256) ||
                (int)$row->source_revision !== (int)$artifact->source_revision) {
                $source=false;
            } else {
                try {
                    app(\App\Services\SignatureCompletionService::class)->bytes($artifact);
                } catch (\Throwable $error) {$source=false;}
            }
            $statuses=['draft','approved','sent','signed'];
            if (!in_array($row->status,$statuses,true) || (int)$row->revision<1) $state=false;
            if ($row->status==='draft' && ($row->approved_pdf_sha256 !== null ||
                $row->signed_pdf_sha256 !== null)) $state=false;
            if (in_array($row->status,['approved','sent','signed'],true) &&
                (!$row->approved_pdf_sha256 ||
                 (int)$row->approved_revision !== (int)$row->revision)) $state=false;
            if ($row->status==='signed' && !$row->signed_pdf_sha256) $state=false;
            foreach ([
                ['approved_pdf_path','approved_pdf_sha256','approved'],
                ['signed_pdf_path','signed_pdf_sha256','signed'],
            ] as [$field,$digestField,$category]) {
                $path=$row->{$field};$sha=$row->{$digestField};
                if ($path === null && $sha === null) continue;
                if (!$path || !$sha ||
                    !preg_match('#^addenda/'.$category.'/'.(int)$row->company_id.
                        '/'.(int)$row->id.'/[1-9][0-9]*/[a-f0-9]{64}\.pdf$#D',(string)$path) ||
                    !$disk->exists($path)) {$integrity=false;continue;}
                $bytes=$disk->get($path);
                if (!is_string($bytes) || !str_starts_with($bytes,'%PDF-') ||
                    !str_contains(substr($bytes,-4096),'%%EOF') ||
                    !hash_equals((string)$sha,hash('sha256',$bytes))) {
                    $integrity=false;
                }
            }
        }
    } catch (\Throwable $error) {
        $integrity=false;$source=false;$tenant=false;$state=false;
    }
    $assert('Addenda and supporting row company consistency',$tenant);
    $assert('Addenda source signed-PDF hashes sampled (up to ten)',$source);
    $assert('Addenda approved/signed private PDF hashes sampled (up to twenty)',$integrity);
    $assert('Addendum lifecycle invariants sampled',$state);
    echo 'M10_SAMPLES_CHECKED '.$checked.PHP_EOL;
    foreach($counts as $name=>$value) echo 'M10_'.strtoupper($name).' '.(int)$value.PHP_EOL;
}
echo "M10_EMAIL_TRANSPORT_".(in_array((string)config('mail.default'),['log','array','null'],true)
    ? "NOT_CONFIGURED" : "CONFIGURED_BUT_NOT_DELIVERY_VERIFIED").PHP_EOL;
echo "INFO: This preflight is read-only; it never deploys, backs up, migrates or sends email.\n";
echo $blocks===0 ? "M10_PREFLIGHT_PASS\n" : "M10_PREFLIGHT_BLOCKED\n";
exit($blocks===0?0:1);
