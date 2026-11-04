<?php
/**
 * M13 release-readiness preflight: READ ONLY.
 * No deploy, migration, mail, storage mutation, or customer token disclosure.
 * Usage: php scripts/m13_release_preflight.php [--require-migrated]
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if(!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"M13_PREFLIGHT_BLOCKED missing Laravel install\n");exit(2);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$blocks=0;
$check=static function(string $name,bool $passed)use(&$blocks):void {
    echo ($passed?'PASS ':'BLOCK ').$name.PHP_EOL;
    if(!$passed)$blocks++;
};
$check('production environment',app()->environment('production'));
$check('debug disabled',config('app.debug')===false);
$check('HTTPS application URL',
    parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
$check('PHP 8.3+',PHP_VERSION_ID>=80300);
$check('MySQL selected',config('database.default')==='mysql');
$check('M13 service and controllers installed',
    class_exists(\App\Services\WorkAcceptanceService::class) &&
    class_exists(\App\Services\WorkAcceptanceCustomerService::class) &&
    class_exists(\App\Http\Controllers\Api\WorkAcceptanceController::class) &&
    class_exists(\App\Http\Controllers\Admin\WorkAcceptanceController::class));

$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$check('private storage exists, writable and outside public_html',
    is_string($private)&&is_writable($private)&&is_string($public)&&
    !str_starts_with($private.DIRECTORY_SEPARATOR,
        $public.DIRECTORY_SEPARATOR));
$check('local files stored in private path',
    config('filesystems.disks.local.root')===storage_path('app/private'));

$connected=false;$m12=false;$m13=false;
try {
    DB::select('SELECT 1');$connected=true;
    $m12=Schema::hasTable('site_reports')&&
        DB::table('migrations')->where('migration',
            '2026_11_04_060000_create_site_reports_and_minutes_tables')->exists();
    $tables=['work_acceptances','work_acceptance_revisions',
        'work_acceptance_events','work_acceptance_requests',
        'work_acceptance_customer_photos','work_acceptance_artifacts'];
    $m13=true;
    foreach($tables as $table)$m13=$m13&&Schema::hasTable($table);
    $m13=$m13&&DB::table('migrations')->where('migration',
        '2026_11_04_070000_create_work_acceptance_tables')->exists();
    if($m13) {
        foreach([['work_acceptances','approved_pdf_sha256'],
            ['work_acceptances','signed_pdf_sha256'],
            ['work_acceptances','managed_document_id'],
            ['work_acceptance_requests','token_sha256'],
            ['work_acceptance_customer_photos','sha256'],
            ['work_acceptance_artifacts','signed_pdf_sha256']] as [$table,$column]) {
            $m13=$m13&&Schema::hasColumn($table,$column);
        }
    }
} catch(\Throwable $e) { $connected=false;$m12=false;$m13=false; }
$check('database connection',$connected);
$check('M12 schema prerequisite (must deploy in order)',$m12);
echo $m13?"M13_SCHEMA_INSTALLED\n":"M13_SCHEMA_PENDING\n";
if(in_array('--require-migrated',$argv,true))$check('M13 schema migrated',$m13);

$routes=Route::getRoutes();
foreach([
    ['GET','work-acceptances','company.permission:documents.read'],
    ['GET','work-acceptances/{acceptance}','company.permission:documents.read'],
    ['GET','work-acceptances/{acceptance}/customer-photos','company.permission:documents.read'],
    ['GET','work-acceptances/{acceptance}/customer-photos/{photo}','company.permission:documents.read'],
    ['GET','work-acceptances/{acceptance}/pdf/{kind}','company.permission:documents.read'],
    ['POST','work-acceptances','company.permission:documents.write'],
    ['PATCH','work-acceptances/{acceptance}','company.permission:documents.write'],
    ['POST','work-acceptances/{acceptance}/approve','company.permission:documents.write'],
    ['POST','work-acceptances/{acceptance}/send','company.permission:documents.write'],
    ['POST','work-acceptances/{acceptance}/requests/{signature}/revoke','company.permission:documents.write'],
] as [$method,$suffix,$permission]) {
    $found=0;
    foreach($routes as $route) {
        if($route->uri()!=='api/v1/'.$suffix ||
            !in_array($method,$route->methods(),true))continue;
        $mw=$route->gatherMiddleware();
        $secured=in_array('auth:sanctum',$mw,true)&&
            in_array('active.user',$mw,true)&&
            in_array('company.access',$mw,true)&&
            in_array($permission,$mw,true);
        if($permission==='company.permission:documents.write') {
            $secured=$secured&&in_array('company.permission:jobs.read',$mw,true);
        }
        if($secured)$found++;
    }
    $check('tenant API '.$method.' '.$suffix,$found===1);
}
foreach(['index','create','store','edit','update','approve','send',
    'revoke','customerPhoto','pdf'] as $name) {
    $route=$routes->getByName('admin.acceptances.'.$name);
    $mw=$route?->gatherMiddleware()??[];
    $check('guarded admin '.$name,$route!==null&&
        in_array('auth',$mw,true)&&in_array('platform.admin',$mw,true));
}
foreach([
    ['GET','accept-work/{token}','throttle:30,1'],
    ['GET','accept-work/{token}/approved-pdf','throttle:15,1'],
    ['POST','accept-work/{token}/submit','throttle:6,1'],
    ['GET','accept-work/{token}/receipt','throttle:30,1'],
    ['GET','accept-work/{token}/signed-pdf','throttle:12,1'],
] as [$method,$url,$throttle]) {
    $found=0;
    foreach($routes as $route) {
        if($route->uri()===$url&&in_array($method,$route->methods(),true)&&
            in_array($throttle,$route->gatherMiddleware(),true))$found++;
    }
    $check('throttled customer '.$method.' '.$url,$found===1);
}
if($m13&&$connected) {
    try {
        $tenants=DB::table('work_acceptances as a')
            ->join('service_jobs as j','j.id','=','a.service_job_id')
            ->join('clients as c','c.id','=','a.client_id')
            ->where(function($q) {
                $q->whereColumn('a.company_id','!=','j.company_id')
                    ->orWhereColumn('a.company_id','!=','c.company_id')
                    ->orWhereColumn('j.client_id','!=','a.client_id');
            })->doesntExist();
        $check('acceptances same-company job and client',$tenants);
        foreach([
            ['work_acceptance_revisions','work_acceptance_id'],
            ['work_acceptance_events','work_acceptance_id'],
            ['work_acceptance_requests','work_acceptance_id'],
            ['work_acceptance_customer_photos','work_acceptance_id'],
            ['work_acceptance_artifacts','work_acceptance_id'],
        ] as [$table,$foreign]) {
            $clean=DB::table($table.' as x')
                ->join('work_acceptances as a','a.id','=','x.'.$foreign)
                ->whereColumn('x.company_id','!=','a.company_id')
                ->doesntExist();
            $check('tenant integrity '.$table,$clean);
        }
        $bytesOk=true;$sampled=0;
        foreach(DB::table('work_acceptances')->orderByDesc('id')
            ->limit(30)->get() as $a) {
            foreach([['approved_pdf_path','approved_pdf_sha256'],
                ['signed_pdf_path','signed_pdf_sha256']] as [$path,$hash]) {
                $file=$a->$path;$expected=$a->$hash;
                if($file===null && $expected===null)continue;
                $sampled++;
                if(!$file||!$expected||!Storage::disk('local')->exists($file)) {
                    $bytesOk=false;continue;
                }
                $data=Storage::disk('local')->get($file);
                if(!is_string($data)||!hash_equals($expected,hash('sha256',$data))) {
                    $bytesOk=false;
                }
            }
        }
        $check('sampled approved and signed PDF SHA256 integrity',$bytesOk);
        echo 'M13_SAMPLED_PDFS '.$sampled.PHP_EOL;
        $photosOk=true;$photoCount=0;
        foreach(DB::table('work_acceptance_customer_photos')->orderByDesc('id')
            ->limit(30)->get() as $p) {
            $photoCount++;
            if(!Storage::disk('local')->exists($p->private_path)) {
                $photosOk=false;continue;
            }
            $bytes=Storage::disk('local')->get($p->private_path);
            if(!is_string($bytes)||strlen($bytes)!==(int)$p->byte_size||
                !hash_equals($p->sha256,hash('sha256',$bytes)))$photosOk=false;
        }
        $check('sampled original customer photo SHA256 integrity',$photosOk);
        echo 'M13_SAMPLED_PHOTOS '.$photoCount.PHP_EOL;
    } catch(\Throwable $e) { $check('M13 read-only data integrity checks',false); }
}
echo "M13_PREFLIGHT_READ_ONLY_NO_MAIL_SENT_NO_DEPLOYMENT\n";
echo "M13_LIVE_EMAIL_DEVICE_CUSTOMER_CHECKS_NOT_VERIFIED\n";
if($blocks) { echo 'M13_PREFLIGHT_BLOCKED '.$blocks.PHP_EOL;exit(2); }
echo "M13_PREFLIGHT_PASS\n";
