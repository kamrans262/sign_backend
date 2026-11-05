<?php
/**
 * Sign2Digital M16 Stage 2 — READ-ONLY release hold preflight.
 *
 * CI: php scripts/m16_release_preflight.php
 * After deployment has been EXPLICITLY APPROVED, with backups and the additive
 * M12..M16 migrations already applied, run separately:
 * php scripts/m16_release_preflight.php --production --require-migrated
 *
 * The script never runs migrations, changes payment status, writes files,
 * transmits data or deploys code. A PASS is not permission to release.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if(!is_file($root.'/vendor/autoload.php')){
    fwrite(STDERR,"M16_PREFLIGHT_BLOCKED Laravel dependencies unavailable\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$failures=0;
$check=static function(string $name,bool $ok)use(&$failures):void{
    echo ($ok?'PASS ':'BLOCK ').$name.PHP_EOL;
    if(!$ok)$failures++;
};
$production=in_array('--production',$argv,true);
$schemaRequired=$production||in_array('--require-migrated',$argv,true);
$check('PHP 8.3+',PHP_VERSION_ID>=80300);
$check('M16 implementation classes',class_exists(\App\Services\DepositService::class)
    &&class_exists(\App\Http\Controllers\Api\DepositController::class)
    &&class_exists(\App\Http\Controllers\Api\DepositAiController::class)
    &&class_exists(\App\Http\Controllers\Admin\DepositController::class));
if($production||app()->environment('production')){
    $check('production environment',app()->environment('production'));
    $check('debug disabled',config('app.debug')===false);
    $check('HTTPS configured',parse_url((string)config('app.url'),
        PHP_URL_SCHEME)==='https');
}
if($production)$check('production MySQL driver',
    config('database.default')==='mysql');

$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$check('receipt storage private and writable',
    is_string($private)&&is_writable($private)&&is_string($public)
    &&!str_starts_with($private.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR));
$check('local disk is private',config('filesystems.disks.local.root')
    ===storage_path('app/private'));

$routes=Route::getRoutes();
$mobile=[
    ['GET','deposits','company.permission:documents.read'],
    ['GET','deposits/{deposit}','company.permission:documents.read'],
    ['GET','deposits/{deposit}/receipts/{receipt}','company.permission:documents.read'],
    ['GET','jobs/{job}/deposit-summary','company.permission:documents.read'],
    ['POST','deposits','company.permission:documents.write'],
    ['POST','deposits/from-quote/{quote}','company.permission:documents.write'],
    ['PATCH','deposits/{deposit}','company.permission:documents.write'],
    ['POST','deposits/{deposit}/receive','company.permission:documents.write'],
    ['POST','deposits/{deposit}/refund','company.permission:documents.write'],
    ['DELETE','deposits/{deposit}','company.permission:documents.write'],
    ['POST','deposits/{deposit}/receipts','company.permission:documents.write'],
    ['DELETE','deposits/{deposit}/receipts/{receipt}','company.permission:documents.write'],
    ['POST','deposits/ai/voice','company.permission:documents.write'],
];
foreach($mobile as [$method,$suffix,$permission]){
    $matches=0;
    foreach($routes as $route){
        if($route->uri()!=='api/v1/'.$suffix||
            !in_array($method,$route->methods(),true))continue;
        $mw=$route->gatherMiddleware();
        if(in_array('auth:sanctum',$mw,true)&&
            in_array('active.user',$mw,true)&&
            in_array('company.access',$mw,true)&&
            in_array('company.permission:jobs.read',$mw,true)&&
            in_array($permission,$mw,true))$matches++;
    }
    $check('authenticated tenant '.$method.' '.$suffix,$matches===1);
}
foreach([
    ['POST','deposits','throttle:20,1'],
    ['POST','deposits/from-quote/{quote}','throttle:10,1'],
    ['POST','deposits/{deposit}/receive','throttle:10,1'],
    ['POST','deposits/{deposit}/refund','throttle:10,1'],
    ['GET','deposits/{deposit}/receipts/{receipt}','throttle:30,1'],
    ['POST','deposits/{deposit}/receipts','throttle:20,1'],
    ['POST','deposits/ai/voice','throttle:6,1'],
] as [$method,$suffix,$throttle]){
    $matches=0;
    foreach($routes as $route)if($route->uri()==='api/v1/'.$suffix
        &&in_array($method,$route->methods(),true)
        &&in_array($throttle,$route->gatherMiddleware(),true))$matches++;
    $check('throttled '.$method.' '.$suffix,$matches===1);
}
foreach(['index','export','create','store','fromQuote','edit','update',
    'receive','refund','destroy','upload','receipt','removeReceipt'] as $name){
    $route=$routes->getByName('admin.deposits.'.$name);
    $mw=$route?->gatherMiddleware()??[];
    $check('guarded Admin Deposits '.$name,$route!==null
        &&in_array('auth',$mw,true)&&in_array('platform.admin',$mw,true));
}
$connected=false;$migrated=false;$schemaReady=false;
try{
    DB::select('SELECT 1');$connected=true;
    $names=[
        '2026_11_04_060000_create_site_reports_and_minutes_tables',
        '2026_11_04_070000_create_work_acceptance_tables',
        '2026_11_05_010000_create_expenses_tracking_tables',
        '2026_11_05_020000_create_labour_tracking_tables',
        '2026_11_05_030000_create_deposit_tracking_tables',
        '2026_11_05_040000_add_deposit_idempotency_columns',
    ];
    $ids=[];
    if(Schema::hasTable('migrations'))foreach($names as $name)
        $ids[]=DB::table('migrations')->where('migration',$name)->value('id');
    $validIds=array_filter($ids,static fn($x)=>is_numeric($x)&&(int)$x>0);
    $migrated=count($ids)===6&&count($validIds)===6&&
        count(array_unique($ids))===6&&
        $ids===array_values((static function(array $x):array{
            sort($x,SORT_NUMERIC);return $x;
        })($ids));
    $schemaReady=$migrated;
    foreach(['job_deposits','job_deposit_receipts','job_deposit_events'] as $table)
        $schemaReady=$schemaReady&&Schema::hasTable($table);
    if($schemaReady)foreach([
        ['job_deposits','request_key'],['job_deposits','request_fingerprint'],
        ['job_deposits','company_id'],['job_deposits','service_quote_id'],
        ['job_deposits','amount_minor'],['job_deposits','received_at'],
        ['job_deposits','refunded_at'],['job_deposits','lock_version'],
        ['job_deposit_receipts','sha256'],['job_deposit_receipts','private_path'],
        ['job_deposit_events','event_type'],['job_deposit_events','metadata'],
    ] as [$table,$column])$schemaReady=$schemaReady&&Schema::hasColumn($table,$column);
}catch(\Throwable $e){$connected=false;$schemaReady=false;}
if($schemaRequired){
    $check('database reachable',$connected);
    $check('ordered M12–M16 migrations',$migrated);
    $check('M16 schema and retry columns installed',$schemaReady);
}
echo $schemaReady?"M16_SCHEMA_INSTALLED\n":"M16_SCHEMA_NOT_INSTALLED\n";
if($connected&&$schemaReady){
    try{
        $badJobs=DB::table('job_deposits as d')
            ->leftJoin('service_jobs as j','j.id','=','d.service_job_id')
            ->where(fn($q)=>$q->whereNull('j.id')
                ->orWhereColumn('d.company_id','!=','j.company_id'))
            ->exists();
        $check('deposit job tenancy intact',!$badJobs);
        $badQuotes=DB::table('job_deposits as d')
            ->join('service_quotes as q','q.id','=','d.service_quote_id')
            ->where(fn($x)=>$x->whereColumn('d.company_id','!=','q.company_id')
                ->orWhereColumn('d.service_job_id','!=','q.service_job_id'))
            ->exists();
        $check('deposit quote/job tenancy intact',!$badQuotes);
        foreach(['job_deposit_receipts','job_deposit_events'] as $table){
            $bad=DB::table($table.' as x')
                ->leftJoin('job_deposits as d','d.id','=','x.job_deposit_id')
                ->where(fn($q)=>$q->whereNull('d.id')
                    ->orWhereColumn('x.company_id','!=','d.company_id'))->exists();
            $check($table.' tenant and parent integrity',!$bad);
        }
        $badState=DB::table('job_deposits')->where(function($q){
            $q->whereNotIn('status',['pending','received','refunded','void'])
                ->orWhere('currency','!=','CHF')->orWhere('amount_minor','<',1)
                ->orWhere('lock_version','<',1)
                ->orWhere(fn($x)=>$x->where('status','received')->whereNull('received_at'))
                ->orWhere(fn($x)=>$x->where('status','refunded')
                    ->where(fn($y)=>$y->whereNull('refunded_at')->orWhereNull('received_at')))
                ->orWhere(fn($x)=>$x->where('status','void')->whereNull('voided_at'))
                ->orWhere(fn($x)=>$x->whereNotNull('request_key')
                    ->whereNull('request_fingerprint'));
        })->exists();
        $check('CHF and deposit lifecycle invariants',!$badState);
        $duplicateKeys=DB::table('job_deposits')->whereNotNull('request_key')
            ->select('company_id','request_key')->groupBy('company_id','request_key')
            ->havingRaw('COUNT(*) > 1')->exists();
        $check('idempotent keys unique within each company',!$duplicateKeys);
        $overbooked=DB::table('job_deposits as d')
            ->join('service_quotes as q','q.id','=','d.service_quote_id')
            ->whereIn('d.status',['pending','received'])
            ->groupBy('d.company_id','d.service_quote_id','q.deposit_minor')
            ->havingRaw('SUM(d.amount_minor) > q.deposit_minor')->exists();
        $check('approved quote deposit capacity',!$overbooked);
        $valid=true;$sample=0;
        foreach(DB::table('job_deposit_receipts')->whereNull('removed_at')
            ->orderByDesc('id')->limit(30)->get() as $proof){
            $sample++;
            $prefix='deposits/'.$proof->company_id.'/'.$proof->job_deposit_id.'/';
            if(!str_starts_with((string)$proof->private_path,$prefix)
                ||str_contains((string)$proof->private_path,'..')
                ||!Storage::disk('local')->exists($proof->private_path)){
                $valid=false;continue;
            }
            $bytes=Storage::disk('local')->get($proof->private_path);
            if(!is_string($bytes)||strlen($bytes)!==(int)$proof->byte_size
                ||!hash_equals((string)$proof->sha256,hash('sha256',$bytes)))$valid=false;
        }
        $check('sampled private receipt originals and SHA-256',$valid);
        echo 'M16_RECEIPTS_SAMPLED '.$sample.PHP_EOL;
    }catch(\Throwable $e){
        $check('read-only M16 financial and receipt integrity checks',false);
    }
}
echo "M16_PREFLIGHT_READ_ONLY_NO_PAYMENT_NO_DEPLOYMENT\n";
echo "M16_DEVICE_LIVE_AI_PRODUCTION_NOT_VERIFIED\n";
if($failures){
    echo 'M16_PREFLIGHT_BLOCKED '.$failures.PHP_EOL;exit(2);
}
echo "M16_PREFLIGHT_PASS\n";
