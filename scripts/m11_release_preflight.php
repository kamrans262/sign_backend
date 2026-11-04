<?php
/**
 * M11 read-only preflight. Never writes, migrates, deletes or sends requests.
 * php scripts/m11_release_preflight.php [--require-migrated]
 */
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_file($root.'/.env') || !is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "M11_BLOCK: Laravel installation or .env missing.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$blocks=0;
$assert=static function (string $what, bool $ok) use (&$blocks): void {
    echo ($ok ? 'PASS ' : 'BLOCK ').$what.PHP_EOL;
    if (!$ok) $blocks++;
};
$bytes=static function (string $raw): int {
    $raw=trim($raw);
    if (!preg_match('/^(\d+)([KMG]?)$/i',$raw,$match)) return 0;
    $size=(int)$match[1];
    if ($size===0 && strtoupper($match[2])==='') return PHP_INT_MAX;
    return $size*(1024**match(strtoupper($match[2])) {
        'G'=>3, 'M'=>2, 'K'=>1, default=>0,
    });
};
$assert('Production environment',app()->environment('production'));
$assert('Debug disabled',config('app.debug')===false);
$assert('HTTPS canonical URL',parse_url((string)config('app.url'),PHP_URL_SCHEME)==='https');
$assert('PHP >= 8.3',PHP_VERSION_ID>=80300);
$assert('MySQL selected',config('database.default')==='mysql');
$assert('Uploads allow 25 MiB voice files',$bytes((string)ini_get('upload_max_filesize')) >= 25*1024*1024);
$assert('POST body limit supports 25 MiB plus form overhead',$bytes((string)ini_get('post_max_size')) >= 26*1024*1024);
$assert('Private file storage configured',config('filesystems.disks.local.root')===storage_path('app/private'));
$private=realpath(storage_path('app/private'));
$public=realpath(public_path());
$assert('Private storage writable and separate from public web root',
    is_string($private) && is_dir($private) && is_writable($private) &&
    is_string($public) &&
    !str_starts_with($private.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR) &&
    !str_starts_with($public.DIRECTORY_SEPARATOR,$private.DIRECTORY_SEPARATOR));
$assert('All M11 services installed',
    class_exists(\App\Http\Controllers\Api\JobMediaController::class) &&
    class_exists(\App\Http\Controllers\Api\JobNoteDocumentController::class) &&
    class_exists(\App\Http\Controllers\Admin\JobMediaController::class) &&
    class_exists(\App\Services\DocumentCatalogService::class));

$schema=false;
$m10=false;
$m11=false;
try {
    DB::connection()->select('SELECT 1');
    $schema=true;
    $m10=Schema::hasTable('addenda') &&
        Schema::hasTable('addendum_revisions') &&
        Schema::hasTable('addendum_signing_requests') &&
        DB::table('migrations')->where('migration',
            '2026_11_04_040000_create_addenda_management_tables')->exists();
    $m11=true;
    foreach(['job_photos','job_notes','job_note_documents'] as $name) {
        if (!Schema::hasTable($name)) $m11=false;
    }
    if ($m11) {
        foreach([
            ['job_photos','sha256'],['job_photos','archived_at'],
            ['job_notes','audio_sha256'],['job_notes','visibility'],
            ['job_notes','archived_at'],['job_note_documents','managed_document_id'],
            ['job_note_documents','approved_at'],
        ] as [$table,$column]) if (!Schema::hasColumn($table,$column)) $m11=false;
        $m11=$m11 && DB::table('migrations')->where('migration',
            '2026_11_04_050000_create_job_media_tables')->exists();
    }
} catch (\Throwable $e) {
    $schema=false;$m10=false;$m11=false;
}
$assert('MySQL reachable',$schema);
$assert('M10 prerequisite migrated',$m10);
echo $m11 ? "M11_SCHEMA_INSTALLED\n" : "M11_SCHEMA_PENDING\n";
if (in_array('--require-migrated',$argv,true)) {
    $assert('M11 migration and schema installed',$m11);
}

$requirements=[
    ['GET','jobs/{job}/photos','jobs.read'],
    ['GET','jobs/{job}/photos/{photo}/file','jobs.read'],
    ['GET','jobs/{job}/notes','jobs.read'],
    ['GET','jobs/{job}/notes/{note}','jobs.read'],
    ['GET','jobs/{job}/notes/{note}/audio','jobs.read'],
    ['POST','jobs/{job}/photos','jobs.write'],
    ['PATCH','jobs/{job}/photos/{photo}','jobs.write'],
    ['DELETE','jobs/{job}/photos/{photo}','jobs.write'],
    ['POST','jobs/{job}/notes','jobs.write'],
    ['PATCH','jobs/{job}/notes/{note}','jobs.write'],
    ['DELETE','jobs/{job}/notes/{note}','jobs.write'],
    ['GET','jobs/{job}/notes/{note}/documents','jobs.read'],
    ['POST','jobs/{job}/notes/{note}/transcribe','jobs.write'],
    ['POST','jobs/{job}/notes/{note}/documents/prepare','jobs.write'],
    ['PATCH','jobs/{job}/notes/{note}/documents/{draft}','jobs.write'],
    ['POST','jobs/{job}/notes/{note}/documents/{draft}/approve','jobs.write'],
];
$routes=Route::getRoutes();
foreach($requirements as [$method,$suffix,$permission]) {
    $uri='api/v1/'.$suffix;
    $matches=0;
    foreach($routes as $route) {
        if ($route->uri()!==$uri || !in_array($method,$route->methods(),true)) continue;
        $middleware=$route->gatherMiddleware();
        $allowed=in_array('auth:sanctum',$middleware,true) &&
            in_array('active.user',$middleware,true) &&
            in_array('company.access',$middleware,true) &&
            in_array('company.permission:'.$permission,$middleware,true);
        if (str_contains($suffix,'documents/') && $method!=='GET') {
            $allowed=$allowed &&
                in_array('company.permission:documents.write',$middleware,true);
        }
        if (in_array($suffix,[
            'jobs/{job}/notes/{note}/transcribe',
            'jobs/{job}/notes/{note}/documents/prepare',
            'jobs/{job}/notes/{note}/documents/{draft}/approve',
        ],true)) {
            $allowed=$allowed && (bool)array_filter($middleware,
                fn ($value)=>str_starts_with((string)$value,'throttle:'));
        }
        if ($allowed) $matches++;
    }
    $assert('Authenticated tenant API '.$method.' '.$uri,$matches===1);
}
foreach([
    'admin.job-media.index'=>'GET',
    'admin.job-media.photos.create'=>'POST',
    'admin.job-media.notes.create'=>'POST',
    'admin.job-media.update'=>'PATCH',
    'admin.job-media.archive'=>'POST',
    'admin.job-media.download'=>'GET',
] as $name=>$method) {
    $route=$routes->getByName($name);
    $mw=$route?->gatherMiddleware()??[];
    $assert('Protected admin route '.$name,
        $route!==null && in_array($method,$route->methods(),true) &&
        in_array('auth',$mw,true) && in_array('platform.admin',$mw,true));
}

// A production check inspects only a bounded sample; never exposes contents.
if ($m11 && $schema) {
    try {
        $valid=true;
        foreach(['job_photos','job_notes','job_note_documents'] as $name) {
            $valid=$valid && DB::table($name.' as x')
                ->join('service_jobs as j','j.id','=','x.service_job_id')
                ->whereColumn('x.company_id','!=','j.company_id')->doesntExist();
        }
        $valid=$valid && DB::table('job_note_documents as d')
            ->join('job_notes as n','n.id','=','d.job_note_id')
            ->where(function ($q) {
                $q->whereColumn('n.company_id','!=','d.company_id')
                    ->orWhereColumn('n.service_job_id','!=','d.service_job_id');
            })->doesntExist();
        $assert('All M11 media, note and document relationships are tenant consistent',$valid);

        $documentsValid=DB::table('job_note_documents as d')
            ->leftJoin('managed_documents as m','m.id','=','d.managed_document_id')
            ->where(function ($q) {
                $q->whereNotIn('d.status',['draft','approved'])
                    ->orWhere(function ($q) {
                        $q->where('d.status','approved')
                            ->where(function ($q) {
                                $q->whereNull('d.managed_document_id')
                                    ->orWhereNull('d.approved_at')
                                    ->orWhereNull('m.id')
                                    ->orWhereColumn('m.company_id','!=','d.company_id');
                            });
                    })->orWhere(function ($q) {
                        $q->where('d.status','draft')->whereNotNull('d.managed_document_id');
                    });
            })->doesntExist();
        $assert('Draft and approved document consistency',$documentsValid);

        $filesValid=true;$sampled=0;
        foreach([
            ['job_photos','private_path','sha256','mime_type','photos'],
            ['job_notes','audio_private_path','audio_sha256','audio_mime_type','audio'],
        ] as [$table,$pathCol,$digestCol,$mimeCol,$folder]) {
            foreach(DB::table($table)->whereNotNull($pathCol)
                ->orderByDesc('id')->limit(8)->get() as $row) {
                $sampled++;
                $path=(string)$row->{$pathCol};
                $sha=(string)$row->{$digestCol};
                $prefix='job-media/'.$row->company_id.'/'.$row->service_job_id.'/'.$folder.'/';
                if (!str_starts_with($path,$prefix) ||
                    !preg_match('/^[a-f0-9]{64}$/D',$sha) ||
                    !Storage::disk('local')->exists($path)) {
                    $filesValid=false;continue;
                }
                $bytes=Storage::disk('local')->get($path);
                if (!is_string($bytes) || !hash_equals($sha,hash('sha256',$bytes))) {
                    $filesValid=false;
                }
            }
        }
        $assert('Sampled private image/audio paths and SHA256 contents verified',$filesValid);
        echo 'M11_SAMPLED_MEDIA '.$sampled.PHP_EOL;
        foreach(['job_photos','job_notes','job_note_documents'] as $table) {
            echo 'M11_COUNT_'.$table.' '.DB::table($table)->count().PHP_EOL;
        }
    } catch (\Throwable $e) {
        $assert('M11 read-only integrity checks could be completed',false);
    }
}
$aiKey=trim((string)config('services.openai.key'));
echo $aiKey==='' ? "M11_AI_NOT_CONFIGURED: live AI acceptance pending\n" :
    "M11_AI_CONFIGURED: credentials present, live request not executed\n";

if ($blocks>0) {
    echo 'M11_PREFLIGHT_BLOCKED '.$blocks.PHP_EOL;
    exit(2);
}
echo "M11_PREFLIGHT_PASS\n";
