<?php
/**
 * Sign2Digital M15 Time & Labour release preflight — READ ONLY.
 *
 * CI (no schema required): php scripts/m15_release_preflight.php
 * After an EXPLICITLY APPROVED production release, backup and ordered
 * additive M12 -> M13 -> M14 -> M15 migrations:
 * php scripts/m15_release_preflight.php --require-migrated --production
 *
 * No database/storage writes, mail, migrations, secrets or deployment.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "M15_PREFLIGHT_BLOCKED Laravel dependencies unavailable\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$failures = 0;
$check = static function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'BLOCK ').$name.PHP_EOL;
    if (!$ok) $failures++;
};
$production = in_array('--production', $argv, true);
$requireSchema = $production || in_array('--require-migrated', $argv, true);
if ($production || app()->environment('production')) {
    $check('production runtime', app()->environment('production'));
    $check('debug disabled', config('app.debug') === false);
    $check('HTTPS application URL',
        parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https');
}
if ($production) $check('MySQL configured', config('database.default') === 'mysql');
$check('PHP 8.3+', PHP_VERSION_ID >= 80300);
$check('M15 service/controller implementations present',
    class_exists(\App\Services\LabourService::class) &&
    class_exists(\App\Services\LabourTimerService::class) &&
    class_exists(\App\Http\Controllers\Api\LabourController::class) &&
    class_exists(\App\Http\Controllers\Api\LabourTimerController::class) &&
    class_exists(\App\Http\Controllers\Api\LabourAiController::class) &&
    class_exists(\App\Http\Controllers\Admin\LabourController::class));

$private = realpath(storage_path('app/private'));
$public = realpath(public_path());
$check('private evidence storage outside served web root',
    is_string($private) && is_writable($private) && is_string($public) &&
    !str_starts_with($private.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR));
$check('local disk uses private evidence storage',
    config('filesystems.disks.local.root') === storage_path('app/private'));

$routes = Route::getRoutes();
$mobile = [
    ['GET','labour/entries','company.permission:jobs.read'],
    ['GET','labour/entries/{entry}','company.permission:jobs.read'],
    ['GET','labour/entries/{entry}/evidence/{evidence}','company.permission:jobs.read'],
    ['GET','labour/timer/current','company.permission:jobs.read'],
    ['GET','jobs/{job}/labour-workers','company.permission:jobs.read'],
    ['GET','jobs/{job}/labour-summary','company.permission:jobs.read'],
    ['GET','jobs/{job}/cost-summary','company.permission:jobs.read'],
    ['POST','labour/entries','company.permission:jobs.write'],
    ['PATCH','labour/entries/{entry}','company.permission:jobs.write'],
    ['POST','labour/entries/{entry}/approve','company.permission:jobs.write'],
    ['POST','labour/entries/{entry}/void','company.permission:jobs.write'],
    ['POST','labour/entries/{entry}/evidence','company.permission:jobs.write'],
    ['DELETE','labour/entries/{entry}/evidence/{evidence}','company.permission:jobs.write'],
    ['POST','labour/timer/{action}','company.permission:jobs.write'],
    ['POST','labour/ai/voice','company.permission:jobs.write'],
];
foreach ($mobile as [$method,$suffix,$permission]) {
    $count = 0;
    foreach ($routes as $route) {
        if ($route->uri() !== 'api/v1/'.$suffix ||
            !in_array($method, $route->methods(), true)) continue;
        $mw = $route->gatherMiddleware();
        if (in_array('auth:sanctum', $mw, true) &&
            in_array('active.user', $mw, true) &&
            in_array('company.access', $mw, true) &&
            in_array($permission, $mw, true)) $count++;
    }
    $check('protected tenant API '.$method.' '.$suffix, $count === 1);
}
foreach ([
    ['POST','labour/ai/voice','throttle:6,1'],
    ['POST','labour/entries','throttle:30,1'],
    ['POST','labour/timer/{action}','throttle:30,1'],
    ['GET','labour/entries/{entry}/evidence/{evidence}','throttle:30,1'],
] as [$method,$suffix,$throttle]) {
    $count=0;
    foreach ($routes as $route) {
        if ($route->uri() === 'api/v1/'.$suffix &&
            in_array($method, $route->methods(), true) &&
            in_array($throttle, $route->gatherMiddleware(), true)) $count++;
    }
    $check('throttled API '.$method.' '.$suffix, $count === 1);
}
foreach ([
    'index','export','create','store','edit','update','approve','void',
    'upload','evidence.file','evidence.remove','timer.stop','timer.recover',
] as $name) {
    $route=$routes->getByName('admin.labour.'.$name);
    $mw=$route?->gatherMiddleware() ?? [];
    $check('guarded Admin Time & Labour '.$name,
        $route !== null && in_array('auth', $mw, true) &&
        in_array('platform.admin', $mw, true));
}

$connected=false;
$schemaReady=false;
$prerequisites=false;
try {
    DB::select('SELECT 1');
    $connected=true;
    $migrationNames=[
        '2026_11_04_060000_create_site_reports_and_minutes_tables',
        '2026_11_04_070000_create_work_acceptance_tables',
        '2026_11_05_010000_create_expenses_tracking_tables',
        '2026_11_05_020000_create_labour_tracking_tables',
    ];
    $migrationIds=[];
    if (Schema::hasTable('migrations')) {
        foreach ($migrationNames as $name) {
            $id=DB::table('migrations')->where('migration',$name)->value('id');
            if ($id !== null) $migrationIds[]=(int)$id;
        }
    }
    $prerequisites=count($migrationIds)===4 &&
        $migrationIds===array_values(array_unique($migrationIds)) &&
        $migrationIds===array_values(array_filter($migrationIds,
            static fn(int $value): bool => $value > 0)) &&
        $migrationIds===array_values((static function(array $ids): array {
            sort($ids);
            return $ids;
        })($migrationIds));
    $tables=['job_expenses','job_labour_entries','job_labour_timers',
        'job_labour_timer_requests','job_labour_events','job_labour_evidence'];
    $schemaReady=$prerequisites;
    foreach ($tables as $table)
        $schemaReady=$schemaReady && Schema::hasTable($table);
    if ($schemaReady) {
        foreach ([
            ['job_labour_entries','company_id'],
            ['job_labour_entries','duration_minutes'],
            ['job_labour_entries','hourly_rate_minor'],
            ['job_labour_entries','cost_minor'],
            ['job_labour_entries','lock_version'],
            ['job_labour_timers','running_since'],
            ['job_labour_timers','accumulated_seconds'],
            ['job_labour_timer_requests','request_key'],
            ['job_labour_events','actor_user_id'],
            ['job_labour_evidence','sha256'],
            ['job_labour_evidence','removed_at'],
        ] as [$table,$column])
            $schemaReady=$schemaReady && Schema::hasColumn($table,$column);
    }
} catch (\Throwable $exception) {
    $connected=false;
    $schemaReady=false;
}
if ($requireSchema) {
    $check('database reachable', $connected);
    $check('M12 -> M13 -> M14 -> M15 migration order', $prerequisites);
    $check('M15 additive schema installed', $schemaReady);
}
echo $schemaReady ? "M15_SCHEMA_INSTALLED\n" : "M15_SCHEMA_NOT_INSTALLED\n";

if ($connected && $schemaReady) {
    try {
        $companyJob=DB::table('job_labour_entries as e')
            ->leftJoin('service_jobs as j','j.id','=','e.service_job_id')
            ->where(function($q) {
                $q->whereNull('j.id')
                  ->orWhereColumn('e.company_id','!=','j.company_id');
            })->doesntExist();
        $check('time entries linked to same-company jobs', $companyJob);
        $timerJob=DB::table('job_labour_timers as t')
            ->leftJoin('service_jobs as j','j.id','=','t.service_job_id')
            ->where(function($q) {
                $q->whereNull('j.id')
                  ->orWhereColumn('t.company_id','!=','j.company_id');
            })->doesntExist();
        $check('timers linked to same-company jobs', $timerJob);
        foreach ([
            ['job_labour_evidence','job_labour_entry_id'],
            ['job_labour_events','job_labour_entry_id'],
        ] as [$table,$id]) {
            $valid=DB::table($table.' as x')
                ->leftJoin('job_labour_entries as e','e.id','=','x.'.$id)
                ->whereNotNull('x.'.$id)
                ->where(function($q) {
                    $q->whereNull('e.id')
                      ->orWhereColumn('x.company_id','!=','e.company_id');
                })->doesntExist();
            $check('company-scoped '.$table, $valid);
        }
        $badEntry=DB::table('job_labour_entries')->where(function($q) {
            $q->where('duration_minutes','<',1)
              ->orWhere('duration_minutes','>',1440)
              ->orWhere('lock_version','<',1)
              ->orWhere('currency','!=','CHF')
              ->orWhereNotIn('status',['pending','approved','void'])
              ->orWhere(function($x) {
                  $x->where('status','approved')->whereNull('approved_at');
              })->orWhere(function($x) {
                  $x->where('status','void')->whereNull('voided_at');
              })->orWhereColumn('work_ended_at','<=','work_started_at');
        })->exists();
        $check('time, money and approval state invariants', !$badEntry);
        $badTimer=DB::table('job_labour_timers')->where(function($q) {
            $q->whereNotIn('status',['running','paused','stopped'])
              ->orWhere('lock_version','<',1)
              ->orWhere(function($x) {
                  $x->where('status','running')->whereNull('running_since');
              })->orWhere(function($x) {
                  $x->whereIn('status',['paused','stopped'])
                    ->whereNotNull('running_since');
              });
        })->exists();
        $check('timer lifecycle invariants', !$badTimer);
        $sample=0;$valid=true;
        foreach (DB::table('job_labour_evidence')->orderByDesc('id')
            ->limit(30)->get() as $proof) {
            $sample++;
            $prefix='labour/'.$proof->company_id.'/'.
                $proof->job_labour_entry_id.'/';
            if (!str_starts_with($proof->private_path,$prefix) ||
                str_contains($proof->private_path,'..') ||
                !Storage::disk('local')->exists($proof->private_path)) {
                $valid=false;
                continue;
            }
            $bytes=Storage::disk('local')->get($proof->private_path);
            if (!is_string($bytes) ||
                strlen($bytes)!==(int)$proof->byte_size ||
                !hash_equals((string)$proof->sha256,hash('sha256',$bytes))) {
                $valid=false;
            }
        }
        $check('sampled original proof SHA-256 integrity', $valid);
        echo 'M15_PROOFS_SAMPLED '.$sample.PHP_EOL;
    } catch (\Throwable $exception) {
        $check('M15 read-only relational and evidence checks', false);
    }
}
echo "M15_PREFLIGHT_READ_ONLY_NO_MIGRATIONS_NO_DEPLOYMENT\n";
echo "M15_LIVE_AI_PHYSICAL_DEVICES_AND_PRODUCTION_NOT_VERIFIED\n";
if ($failures) {
    echo 'M15_PREFLIGHT_BLOCKED '.$failures.PHP_EOL;
    exit(2);
}
echo "M15_PREFLIGHT_PASS\n";
