<?php
/**
 * M12 read-only production preflight. It never migrates, deploys, saves,
 * sends mail, contacts OpenAI, or exposes private data.
 * php scripts/m12_release_preflight.php [--require-migrated]
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if(!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"M12_PREFLIGHT_BLOCKED Laravel installation missing\n");exit(2);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$blocks=0;
$assert=static function(string $name,bool $ok)use(&$blocks):void {
    echo ($ok?'PASS ':'BLOCK ').$name.PHP_EOL;
    if(!$ok)$blocks++;
};
$assert('M12 production environment',app()->environment('production'));
$assert('M12 debug disabled',config('app.debug')===false);
$assert('M12 HTTPS canonical URL',
    parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
$assert('M12 PHP 8.3+',PHP_VERSION_ID>=80300);
$assert('M12 MySQL',config('database.default')==='mysql');
$assert('M12 services installed',
    class_exists(\App\Services\SiteReportService::class) &&
    class_exists(\App\Http\Controllers\Api\SiteReportController::class) &&
    class_exists(\App\Http\Controllers\Api\SiteReportVoiceController::class) &&
    class_exists(\App\Http\Controllers\Admin\SiteReportController::class));
$assert('M12 private storage configured',
    config('filesystems.disks.local.root')===storage_path('app/private'));
$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$assert('M12 private storage writable and outside public',
    is_string($private)&&is_writable($private)&&
    is_string($public)&&!str_starts_with($private.DIRECTORY_SEPARATOR,
        $public.DIRECTORY_SEPARATOR));
$connected=false;$m11=false;$m12=false;
try {
    DB::select('SELECT 1');$connected=true;
    $m11=Schema::hasTable('job_photos')&&Schema::hasTable('job_notes')&&
        DB::table('migrations')->where('migration',
            '2026_11_04_050000_create_job_media_tables')->exists();
    $m12=true;
    foreach(['site_reports','site_report_revisions','site_report_events'] as $table) {
        $m12=$m12&&Schema::hasTable($table);
    }
    if($m12) {
        foreach([['site_reports','managed_document_id'],
            ['site_reports','photo_ids'],['site_reports','revision'],
            ['site_report_revisions','snapshot'],
            ['site_report_events','event_type']] as [$table,$column]) {
            $m12=$m12&&Schema::hasColumn($table,$column);
        }
        $m12=$m12&&DB::table('migrations')->where('migration',
            '2026_11_04_060000_create_site_reports_and_minutes_tables')->exists();
    }
} catch(\Throwable $e) { $connected=false;$m11=false;$m12=false; }
$assert('M12 MySQL reachable',$connected);
$assert('M11 migration prerequisite',$m11);
echo $m12?"M12_SCHEMA_INSTALLED\n":"M12_SCHEMA_PENDING\n";
if(in_array('--require-migrated',$argv,true))$assert('M12 schema migrated',$m12);
$routes=Route::getRoutes();
foreach([
    ['GET','reports','documents.read'],
    ['GET','reports/{report}','documents.read'],
    ['GET','reports/{report}/photos/{photo}','documents.read'],
    ['GET','reports/{report}/pdf','documents.read'],
    ['POST','reports/voice/transcribe','documents.write'],
    ['POST','reports','documents.write'],
    ['PATCH','reports/{report}','documents.write'],
    ['POST','reports/{report}/prepare-ai','documents.write'],
    ['POST','reports/{report}/approve','documents.write'],
    ['POST','reports/{report}/send','documents.write'],
    ['DELETE','reports/{report}','documents.write'],
] as [$method,$suffix,$permission]) {
    $found=0;
    foreach($routes as $route) {
        if($route->uri()!=='api/v1/'.$suffix||
            !in_array($method,$route->methods(),true))continue;
        $mw=$route->gatherMiddleware();
        $ok=in_array('auth:sanctum',$mw,true)&&
            in_array('active.user',$mw,true)&&
            in_array('company.access',$mw,true)&&
            in_array('company.permission:'.$permission,$mw,true);
        if($method!=='GET')$ok=$ok&&
            in_array('company.permission:jobs.read',$mw,true);
        if($ok)$found++;
    }
    $assert('M12 tenant route '.$method.' '.$suffix,$found===1);
}
foreach([
    'admin.reports.index','admin.reports.create',
    'admin.reports.store','admin.reports.edit','admin.reports.update',
    'admin.reports.approve','admin.reports.send','admin.reports.archive',
    'admin.reports.pdf',
] as $name) {
    $route=$routes->getByName($name);
    $mw=$route?->gatherMiddleware()??[];
    $assert('M12 protected admin route '.$name,
        $route!==null&&in_array('auth',$mw,true)&&
        in_array('platform.admin',$mw,true));
}
if($m12&&$connected) {
    try {
        $tenant=DB::table('site_reports as r')
            ->join('service_jobs as j','j.id','=','r.service_job_id')
            ->join('clients as c','c.id','=','r.client_id')
            ->where(function($q) {
                $q->whereColumn('r.company_id','!=','j.company_id')
                    ->orWhereColumn('r.company_id','!=','c.company_id')
                    ->orWhereColumn('j.client_id','!=','r.client_id');
            })->doesntExist();
        $assert('M12 reports linked to same-company jobs and clients',$tenant);
        $audit=true;
        foreach(['site_report_revisions','site_report_events'] as $name) {
            $audit=$audit&&DB::table($name.' as x')
                ->join('site_reports as r','r.id','=','x.site_report_id')
                ->whereColumn('x.company_id','!=','r.company_id')->doesntExist();
        }
        $assert('M12 report revisions and events tenant consistent',$audit);
        $filesOk=true;$sampled=0;
        foreach(DB::table('site_reports')->whereNotNull('managed_document_id')
            ->orderByDesc('id')->limit(20)->get() as $report) {
            $sampled++;
            $m=DB::table('managed_documents')->where('company_id',$report->company_id)
                ->where('id',$report->managed_document_id)->first();
            $v=$m?DB::table('managed_document_versions')
                ->where('company_id',$report->company_id)
                ->where('managed_document_id',$m->id)->where('version',1)->first():null;
            $filesOk=$filesOk&&$m!==null&&$v!==null&&
                (int)$m->current_version===1&&
                in_array($report->status,['approved','sent','archived'],true)&&
                (int)$m->service_job_id===(int)$report->service_job_id&&
                Storage::disk('local')->exists($v->private_path);
            if($v && Storage::disk('local')->exists($v->private_path)) {
                $bytes=Storage::disk('local')->get($v->private_path);
                $filesOk=$filesOk&&is_string($bytes)&&
                    hash_equals($v->sha256,hash('sha256',$bytes));
            }
        }
        $assert('M12 approved PDF originals and SHA256 sampled',$filesOk);
        $photosOk=true;
        foreach(DB::table('site_reports')->orderByDesc('id')->limit(30)->get() as $report) {
            $ids=json_decode((string)($report->photo_ids??'[]'),true)?:[];
            if(count($ids)>8) { $photosOk=false;continue; }
            foreach($ids as $id) {
                if(!DB::table('job_photos')->where('company_id',$report->company_id)
                    ->where('service_job_id',$report->service_job_id)
                    ->where('id',$id)->exists())$photosOk=false;
            }
        }
        $assert('M12 sampled report photo references tenant consistent',$photosOk);
        echo 'M12_SAMPLED_APPROVED_PDFS '.$sampled.PHP_EOL;
        foreach(['site_reports','site_report_revisions','site_report_events'] as $name) {
            echo 'M12_COUNT_'.$name.' '.DB::table($name)->count().PHP_EOL;
        }
    } catch(\Throwable $e) {
        $assert('M12 read-only integrity samples completed',false);
    }
}
$key=trim((string)config('services.openai.key'));
echo $key===''?"M12_AI_NOT_CONFIGURED\n":
    "M12_AI_CONFIGURED_NOT_LIVE_VERIFIED\n";
echo "M12_PREFLIGHT_READ_ONLY_NO_LIVE_EMAIL_OR_AI\n";
if($blocks) { echo "M12_PREFLIGHT_BLOCKED $blocks\n";exit(2); }
echo "M12_PREFLIGHT_PASS\n";
