<?php
/**
 * Sign2Digital M9 read-only release preflight.
 *
 * Usage from installed Laravel root:
 *   php scripts/m9_release_preflight.php
 *   php scripts/m9_release_preflight.php --require-migrated
 *
 * Never migrates, sends email, copies files, logs document content, or writes.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "M9_BLOCK: Laravel installation or environment is missing.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$blocks=0;
$assert=static function(string $name, bool $ok) use (&$blocks): void {
    echo ($ok ? 'PASS ' : 'BLOCK ').$name.PHP_EOL;
    if (!$ok) $blocks++;
};
$assert('Production mode',app()->environment('production'));
$assert('Debug disabled',config('app.debug')===false);
$assert('HTTPS application URL',parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
$assert('PHP 8.3+',PHP_VERSION_ID>=80300);
$assert('MySQL selected',config('database.default')==='mysql');
$assert('M8 signed document service still installed',
    class_exists(\App\Services\SignatureCompletionService::class) &&
    class_exists(\App\Services\SignatureWorkflowService::class));
$disk=\Illuminate\Support\Facades\Storage::disk('local');
$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$assert('Private files outside public root',
    is_string($private) && is_dir($private) && is_writable($private) &&
    is_string($public) &&
    !str_starts_with($private.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR) &&
    !str_starts_with($public.DIRECTORY_SEPARATOR,$private.DIRECTORY_SEPARATOR));

$db=false; $m7=false; $m8=false; $m9=false;
try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1');
    $db=true;
} catch (\Throwable $ignored) {}
$assert('MySQL database reachable',$db);
if ($db) {
    try {
        $schema=\Illuminate\Support\Facades\Schema::connection('mysql');
        $m7=$schema->hasTable('service_quotes') &&
            $schema->hasColumn('service_quotes','approved_pdf_sha256');
        $m8=$schema->hasTable('signed_document_artifacts') &&
            $schema->hasTable('signature_events') &&
            $schema->hasTable('signature_requests');
        $tables=['managed_documents','managed_document_versions',
                 'document_attachments','document_events'];
        $m9=true;
        foreach($tables as $table) if (!$schema->hasTable($table)) $m9=false;
        if ($m9) {
            $m9=$schema->hasColumn('managed_documents','current_version') &&
                $schema->hasColumn('managed_document_versions','sha256') &&
                $schema->hasColumn('document_attachments','private_path') &&
                $schema->hasColumn('document_events','event_type');
        }
        $migrations=\Illuminate\Support\Facades\DB::table('migrations');
        $m8=$m8 && (clone $migrations)->where('migration',
            '2026_11_04_020000_create_signature_workflow_tables')->exists();
        $m9=$m9 && (clone $migrations)->where('migration',
            '2026_11_04_030000_create_document_management_tables')->exists();
    } catch (\Throwable $ignored) {
        $m7=false;$m8=false;$m9=false;
    }
}
$assert('M7 frozen quote approval schema exists',$m7);
$assert('M8 signing and signed-PDF migrations already installed',$m8);
echo $m9 ? "M9_SCHEMA_INSTALLED\n" : "M9_SCHEMA_PENDING\n";
if (in_array('--require-migrated',$argv,true)) {
    $assert('M9 schema and migration installed',$m9);
}

$routes=\Illuminate\Support\Facades\Route::getRoutes();
foreach([
    ['GET','api/v1/documents','documents.read'],
    ['GET','api/v1/documents/{document}','documents.read'],
    ['GET','api/v1/documents/{document}/history','documents.read'],
    ['GET','api/v1/documents/{document}/versions','documents.read'],
    ['GET','api/v1/documents/{document}/attachments','documents.read'],
    ['GET','api/v1/documents/{document}/pdf','documents.read'],
    ['GET','api/v1/documents/{document}/attachments/{attachment}/download','documents.read'],
    ['POST','api/v1/documents','documents.write'],
    ['POST','api/v1/documents/{document}/versions','documents.write'],
    ['POST','api/v1/documents/{document}/attachments','documents.write'],
] as [$method,$uri,$permission]) {
    $hits=0;
    foreach ($routes as $route) {
        if ($route->uri() !== $uri || !in_array($method,$route->methods(),true)) continue;
        $middleware=$route->gatherMiddleware();
        if (in_array('auth:sanctum',$middleware,true) &&
            in_array('active.user',$middleware,true) &&
            in_array('company.access',$middleware,true) &&
            in_array('company.permission:'.$permission,$middleware,true)) $hits++;
    }
    $assert('Tenant route '.$method.' '.$uri,$hits===1);
}
foreach([
    'admin.documents.index'=>'GET',
    'admin.documents.show'=>'GET',
    'admin.documents.pdf'=>'GET',
    'admin.documents.store'=>'POST',
    'admin.documents.versions.store'=>'POST',
    'admin.documents.attachments.store'=>'POST',
] as $name=>$method) {
    $route=$routes->getByName($name);
    $middleware=$route?->gatherMiddleware() ?? [];
    $assert('Platform admin route '.$name,
        $route!==null && in_array($method,$route->methods(),true) &&
        in_array('auth',$middleware,true) &&
        in_array('platform.admin',$middleware,true));
}
if($m9 && $db) {
    $stats=['documents'=>0,'versions'=>0,'attachments'=>0,'events'=>0];
    try {
        $stats['documents']=\Illuminate\Support\Facades\DB::table('managed_documents')->count();
        $stats['versions']=\Illuminate\Support\Facades\DB::table('managed_document_versions')->count();
        $stats['attachments']=\Illuminate\Support\Facades\DB::table('document_attachments')->count();
        $stats['events']=\Illuminate\Support\Facades\DB::table('document_events')->count();
        $consistent=\Illuminate\Support\Facades\DB::table('managed_document_versions as v')
            ->join('managed_documents as d','d.id','=','v.managed_document_id')
            ->whereColumn('v.company_id','!=','d.company_id')->doesntExist();
        $assert('Document version tenant association',$consistent);
        $valid=true; $checked=0;
        foreach(['managed_document_versions','document_attachments'] as $table) {
            foreach(\Illuminate\Support\Facades\DB::table($table)
                ->orderByDesc('id')->limit(5)->get(['private_path','sha256']) as $row) {
                $checked++;
                $path=(string)$row->private_path;
                if (!preg_match('#^m9-(?:documents|attachments)/[0-9]+/[a-f0-9-]+\.(pdf|png|jpg)$#D',$path) ||
                    !$disk->exists($path)) { $valid=false;break 2; }
                $bytes=$disk->get($path);
                if (!is_string($bytes) || !hash_equals($row->sha256,hash('sha256',$bytes))) {
                    $valid=false;break 2;
                }
            }
        }
        $assert('M9 private-file SHA-256 integrity sample (up to ten)',$valid);
        echo 'M9_FILES_SAMPLED '.$checked.PHP_EOL;
    } catch (\Throwable $ignored) {
        $assert('M9 database and private storage consistency',false);
    }
    foreach ($stats as $name=>$count) echo 'M9_'.strtoupper($name).' '.(int)$count.PHP_EOL;
}
echo $blocks===0 ? "M9_PREFLIGHT_PASS\n" : "M9_PREFLIGHT_BLOCKED\n";
exit($blocks===0 ? 0 : 1);
