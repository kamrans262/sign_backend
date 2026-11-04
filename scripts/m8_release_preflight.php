<?php

/**
 * Sign2Digital M8 production preflight — strictly READ ONLY.
 *
 * Never switches Git branches, migrates, sends email, edits records,
 * regenerates PDFs, reveals account details or modifies private storage.
 *
 * Usage:
 *   php scripts/m8_release_preflight.php
 *   php scripts/m8_release_preflight.php --require-migrated
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"M8_BLOCK: Laravel installation or production configuration is missing.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$failures=0;
$check=static function(string $label,bool $ok) use (&$failures):void {
    echo ($ok ? 'PASS ' : 'BLOCK ').$label.PHP_EOL;
    if(!$ok) $failures++;
};

$check('Production mode',app()->environment('production'));
$check('Debug mode disabled',config('app.debug')===false);
$check('HTTPS public application origin',
    parse_url((string) config('app.url'),PHP_URL_SCHEME)==='https');
$check('Supported PHP runtime',PHP_VERSION_ID>=80300);
$check('MySQL connection configured',config('database.default')==='mysql');
$check('PDF library classes installed',
    class_exists(\setasign\Fpdi\Fpdi::class) &&
    class_exists(\setasign\Fpdi\PdfParser\StreamReader::class));
$check('Private PDF storage root exists and is writable',
    is_dir(storage_path('app/private')) &&
    is_writable(storage_path('app/private')));
$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$check('Private PDF storage cannot live below public web root',
    is_string($private) && is_string($public) &&
    !str_starts_with($private.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR) &&
    !str_starts_with($public.DIRECTORY_SEPARATOR,$private.DIRECTORY_SEPARATOR));
$check('Private signature files not exposed under public directory',
    !is_dir(public_path('signatures')));

$connected=false;
try {
    \Illuminate\Support\Facades\DB::connection()->select('SELECT 1 AS ok');
    $connected=true;
} catch (\Throwable $ignored) {
    // Do not disclose database host, username, password or exception internals.
}
$check('Read-only MySQL database connectivity',$connected);

$m7=false;$m8=false;$migration=false;
if($connected){
    try {
        $s=\Illuminate\Support\Facades\Schema::connection('mysql');
        $m7=$s->hasTable('service_quotes') &&
            $s->hasColumn('service_quotes','approved_pdf_sha256') &&
            $s->hasColumn('service_quotes','approved_pdf_path') &&
            $s->hasColumn('service_quotes','approved_pdf_revision');
        $m8=$s->hasTable('signature_requests') &&
            $s->hasTable('signature_events') &&
            $s->hasTable('signed_document_artifacts') &&
            $s->hasColumn('signature_requests','business_signer_uuid') &&
            $s->hasColumn('signed_document_artifacts','signed_pdf_sha256');
        if($s->hasTable('migrations')){
            $migration=\Illuminate\Support\Facades\DB::table('migrations')
                ->where('migration','2026_11_04_020000_create_signature_workflow_tables')
                ->exists();
        }
    }catch(\Throwable $ignored){}
}
$check('Original M7 approval integrity schema is present',$m7);
echo $m8 && $migration ? "M8_SCHEMA_INSTALLED\n" : "M8_SCHEMA_PENDING\n";
if(in_array('--require-migrated',$argv,true)){
    $check('M8 additive migration installed and complete',$m8 && $migration);
}

$routes=\Illuminate\Support\Facades\Route::getRoutes();
foreach([
    ['GET','api/v1/signed-documents','auth:sanctum','company.permission:documents.read'],
    ['GET','api/v1/signed-documents/{document}/pdf','auth:sanctum','company.permission:documents.read'],
    ['POST','api/v1/quotes/{quote}/in-person-signing','auth:sanctum','company.permission:quotes.write'],
    ['POST','api/v1/signature-requests/{signature}/complete-in-person','auth:sanctum','company.permission:quotes.write'],
    ['POST','api/v1/quotes/{quote}/signature-requests','auth:sanctum','company.permission:quotes.write'],
    ['POST','api/v1/signature-requests/{signature}/rotate-link','auth:sanctum','company.permission:quotes.write'],
] as [$method,$uri,$auth,$permission]){
    $matched=0;
    foreach($routes as $route){
        if($route->uri()===$uri && in_array($method,$route->methods(),true)){
            $middleware=$route->gatherMiddleware();
            if(in_array($auth,$middleware,true) &&
               in_array('company.access',$middleware,true) &&
               in_array($permission,$middleware,true)) $matched++;
        }
    }
    $check('Tenant signing route '.$method.' /'.$uri,$matched===1);
}

foreach([
    'signing.public.browser'=>'GET',
    'signing.public.submit.browser'=>'POST',
    'signing.public.receipt.browser'=>'GET',
    'signing.public.signedPdf.browser'=>'GET',
] as $name=>$method){
    $route=$routes->getByName($name);
    $check('Throttled public signing route '.$name,
        $route!==null && in_array($method,$route->methods(),true) &&
        in_array('web',$route->gatherMiddleware(),true) &&
        count(array_filter($route->gatherMiddleware(),
            static fn($mid)=>str_starts_with((string)$mid,'throttle:')))>0);
}

$driver=(string)config('mail.default');
echo in_array($driver,['log','array','null',''],true)
    ? "M8_EMAIL_TRANSPORT_NOT_CONFIGURED\n"
    : "M8_EMAIL_TRANSPORT_CONFIGURED_BUT_NOT_DELIVERY_VERIFIED\n";

$sampleOk=true;$artifactCount=0;$legacyCount=null;
if($connected && $m8){
    try {
        $artifactCount=\Illuminate\Support\Facades\DB::table('signed_document_artifacts')
            ->count();
        $records=\App\Models\SignedDocumentArtifact::query()
            ->orderByDesc('id')->limit(5)->get();
        foreach($records as $record){
            $disk=\Illuminate\Support\Facades\Storage::disk('local');
            $path=(string)$record->signed_pdf_path;
            if(!preg_match('#^signatures/signed/[0-9]+/[0-9]+/[0-9]+/[a-f0-9]{64}\.pdf$#D',$path)
               || !$disk->exists($path)){
                $sampleOk=false;break;
            }
            $bytes=$disk->get($path);
            if(!is_string($bytes)||!hash_equals((string)$record->signed_pdf_sha256,
                hash('sha256',$bytes))){
                $sampleOk=false;break;
            }
        }
    }catch(\Throwable $ignored){$sampleOk=false;}
    $check('Existing signed-PDF integrity sample (up to five)', $sampleOk);
}
if($connected && $m7){
    try {
        $legacyCount=\Illuminate\Support\Facades\DB::table('service_quotes')
            ->whereIn('status',['approved','sent'])
            ->whereNull('approved_pdf_sha256')->count();
    }catch(\Throwable $ignored){}
}
echo 'M8_SIGNED_ARTIFACT_COUNT '.(int)$artifactCount.PHP_EOL;
echo 'M7_LEGACY_APPROVALS_WITHOUT_HASH '.
    ($legacyCount===null?'UNKNOWN':(string)$legacyCount).PHP_EOL;
echo "INFO: This script never backs up, deploys, migrates, or sends email.\n";
echo $failures===0?"M8_PREFLIGHT_PASS\n":"M8_PREFLIGHT_BLOCKED\n";
exit($failures===0?0:1);
