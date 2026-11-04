<?php
/**
 * M14 read-only release preflight. No mail, migration, upload, DB mutation or deploy.
 *
 * Use after a deliberately authorized deploy and additive migrations:
 *   php scripts/m14_release_preflight.php --require-migrated --production
 *
 * Local CI can run without flags before migrating to verify route security.
 */
declare(strict_types=1);

$root=dirname(__DIR__);
if(!is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR,"M14_PREFLIGHT_BLOCKED Laravel dependencies unavailable\n");
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
$check=static function(string $name,bool $ok)use(&$failures):void {
    echo ($ok?'PASS ':'BLOCK ').$name.PHP_EOL;
    if(!$ok)$failures++;
};
$requiresProduction=in_array('--production',$argv,true);
$requiresMigrated=in_array('--require-migrated',$argv,true);
if($requiresProduction||app()->environment('production')) {
    $check('production environment',app()->environment('production'));
    $check('debug disabled',config('app.debug')===false);
    $check('HTTPS application URL',
        parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
}
$check('PHP 8.3+',PHP_VERSION_ID>=80300);
$check('Expense API, AI and Admin controllers available',
    class_exists(\App\Services\ExpenseService::class) &&
    class_exists(\App\Http\Controllers\Api\ExpenseController::class) &&
    class_exists(\App\Http\Controllers\Api\ExpenseAiController::class) &&
    class_exists(\App\Http\Controllers\Admin\ExpenseController::class));
$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$check('local evidence storage outside publicly served files',
    is_string($private)&&is_writable($private)&&is_string($public)&&
    !str_starts_with($private.DIRECTORY_SEPARATOR,
        $public.DIRECTORY_SEPARATOR));
$check('local disk rooted at private storage',
    config('filesystems.disks.local.root')===storage_path('app/private'));

$routes=Route::getRoutes();
$mobile=[
    ['GET','expenses','company.permission:documents.read'],
    ['GET','expenses/{expense}','company.permission:documents.read'],
    ['GET','expenses/{expense}/receipts/{receipt}','company.permission:documents.read'],
    ['GET','jobs/{job}/expense-totals','company.permission:documents.read'],
    ['POST','expenses','company.permission:documents.write'],
    ['PATCH','expenses/{expense}','company.permission:documents.write'],
    ['POST','expenses/{expense}/receipts','company.permission:documents.write'],
    ['DELETE','expenses/{expense}/receipts/{receipt}','company.permission:documents.write'],
    ['POST','expenses/{expense}/request-reimbursement','company.permission:documents.write'],
    ['POST','expenses/ai/voice','company.permission:documents.write'],
    ['POST','expenses/ai/receipt','company.permission:documents.write'],
];
foreach($mobile as [$method,$suffix,$permission]) {
    $found=0;
    foreach($routes as $route) {
        if($route->uri()!=='api/v1/'.$suffix ||
            !in_array($method,$route->methods(),true))continue;
        $mw=$route->gatherMiddleware();
        $safe=in_array('auth:sanctum',$mw,true)&&
            in_array('active.user',$mw,true)&&
            in_array('company.access',$mw,true)&&
            in_array($permission,$mw,true);
        if($permission==='company.permission:documents.write')
            $safe=$safe&&in_array('company.permission:jobs.read',$mw,true);
        if($safe)$found++;
    }
    $check('tenant API '.$method.' '.$suffix,$found===1);
}
$adminNames=['index','create','store','edit','update','approve',
    'requestReimbursement','reimburse','upload','receipt','removeReceipt'];
foreach($adminNames as $method) {
    $route=$routes->getByName('admin.expenses.'.$method);
    $mw=$route?->gatherMiddleware()??[];
    $check('guarded Admin Expenses '.$method,
        $route!==null&&in_array('auth',$mw,true)&&
        in_array('platform.admin',$mw,true));
}
foreach([
    ['POST','expenses/ai/voice','throttle:6,1'],
    ['POST','expenses/ai/receipt','throttle:6,1'],
    ['GET','expenses/{expense}/receipts/{receipt}','throttle:30,1'],
] as [$method,$suffix,$throttle]) {
    $found=0;
    foreach($routes as $route) {
        if($route->uri()==='api/v1/'.$suffix&&
            in_array($method,$route->methods(),true)&&
            in_array($throttle,$route->gatherMiddleware(),true))$found++;
    }
    $check('throttled evidence/AI '.$method.' '.$suffix,$found===1);
}

$connected=false;$schemaReady=false;
try {
    DB::select('SELECT 1');$connected=true;
    $schemaReady=Schema::hasTable('job_expenses')&&
        Schema::hasTable('job_expense_receipts')&&
        Schema::hasTable('job_expense_events')&&
        DB::table('migrations')->where('migration',
            '2026_11_05_010000_create_expenses_tracking_tables')->exists();
    if($schemaReady) {
        foreach([
            ['job_expenses','company_id'],['job_expenses','amount_minor'],
            ['job_expenses','lock_version'],['job_expenses','reimbursed_at'],
            ['job_expense_receipts','sha256'],
            ['job_expense_receipts','private_path'],
            ['job_expense_receipts','removed_at'],
            ['job_expense_events','actor_user_id'],
        ] as [$table,$column]) {
            $schemaReady=$schemaReady&&Schema::hasColumn($table,$column);
        }
    }
} catch(\Throwable $exception) {$connected=false;$schemaReady=false;}
if($requiresMigrated||$requiresProduction) {
    $check('database reachable',$connected);
    $check('M14 additive schema migrated',$schemaReady);
}
echo $schemaReady?"M14_SCHEMA_INSTALLED\n":"M14_SCHEMA_NOT_INSTALLED\n";

if($schemaReady&&$connected) {
    try {
        $valid=DB::table('job_expenses as e')
            ->leftJoin('service_jobs as j','j.id','=','e.service_job_id')
            ->leftJoin('clients as c','c.id','=','j.client_id')
            ->where(function($q) {
                $q->whereNull('j.id')->orWhereNull('c.id')
                  ->orWhereColumn('e.company_id','!=','j.company_id')
                  ->orWhereColumn('e.company_id','!=','c.company_id');
            })->doesntExist();
        $check('company/job/client relationships', $valid);
        foreach(['job_expense_receipts','job_expense_events'] as $table) {
            $valid=DB::table($table.' as r')
                ->leftJoin('job_expenses as e','e.id','=','r.job_expense_id')
                ->where(function($q) {
                    $q->whereNull('e.id')
                        ->orWhereColumn('r.company_id','!=','e.company_id');
                })->doesntExist();
            $check('company-scoped '.$table,$valid);
        }
        $valid=DB::table('job_expenses')
            ->where(function($q) {
                $q->where('amount_minor','<=',0)
                  ->orWhere('currency','!=','CHF')
                  ->orWhere('lock_version','<',1)
                  ->orWhereNotIn('status',['to_review','approved','reimbursed'])
                  ->orWhere(function($x) {
                      $x->where('status','to_review')
                        ->whereNotNull('approved_at');
                  })->orWhere(function($x) {
                      $x->where('status','approved')
                        ->whereNull('approved_at');
                  })->orWhere(function($x) {
                      $x->where('status','reimbursed')
                        ->whereNull('reimbursed_at');
                  });
            })->doesntExist();
        $check('positive CHF amounts and consistent review status',$valid);
        $sample=0;$verified=true;
        foreach(DB::table('job_expense_receipts')->orderByDesc('id')
            ->limit(30)->get() as $file) {
            $sample++;
            $prefix='expenses/'.$file->company_id.'/'.$file->job_expense_id.'/';
            if(!str_starts_with($file->private_path,$prefix)||
                str_contains($file->private_path,'..')||
                !Storage::disk('local')->exists($file->private_path)) {
                $verified=false;continue;
            }
            $bytes=Storage::disk('local')->get($file->private_path);
            if(!is_string($bytes)||
                strlen($bytes)!==(int)$file->byte_size||
                !hash_equals((string)$file->sha256,hash('sha256',$bytes))) {
                $verified=false;
            }
        }
        $check('sampled private receipt SHA-256 integrity',$verified);
        echo 'M14_RECEIPTS_SAMPLED '.$sample.PHP_EOL;
    } catch(\Throwable $exception) {
        $check('M14 read-only company and evidence audit',false);
    }
}
echo "M14_PREFLIGHT_READ_ONLY_NO_MIGRATION_NO_DEPLOYMENT\n";
echo "M14_LIVE_MOBILE_AI_DEVICE_CHECKS_NOT_VERIFIED\n";
if($failures>0) {
    echo 'M14_PREFLIGHT_BLOCKED '.$failures.PHP_EOL;
    exit(2);
}
echo "M14_PREFLIGHT_PASS\n";
