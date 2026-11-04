<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class JobMediaController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'job_id' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', Rule::in(['photos','notes','conversions'])],
            'archived' => ['nullable', Rule::in(['0','1'])],
        ]);
        $kind = $filters['kind'] ?? 'photos';
        $table = ['photos'=>'job_photos','notes'=>'job_notes','conversions'=>'job_note_documents'][$kind];
        $query = DB::table($table.' as item')
            ->join('companies as c','c.id','=','item.company_id')
            ->join('service_jobs as j','j.id','=','item.service_job_id')
            ->select('item.*','c.name as company_name','j.title as job_title','j.reference_number')
            ->orderByDesc('item.id');
        if (!empty($filters['company_id'])) $query->where('item.company_id', $filters['company_id']);
        if (!empty($filters['job_id'])) $query->where('item.service_job_id', $filters['job_id']);
        if ($kind !== 'conversions') {
            if (($filters['archived'] ?? '0') === '1') $query->whereNotNull('item.archived_at');
            else $query->whereNull('item.archived_at');
        }
        return view('admin.job-media.index', [
            'records' => $query->paginate(20)->withQueryString(),
            'companies' => Company::query()->orderBy('name')->get(['id','name']),
            'jobs' => ServiceJob::query()->orderByDesc('id')->limit(300)->get(['id','company_id','title','reference_number']),
            'kind' => $kind, 'filters' => $filters,
        ]);
    }

    private function scoped(string $kind, int $id): object
    {
        $table = $kind === 'photos' ? 'job_photos' : 'job_notes';
        return DB::table($table)->where('id', $id)->firstOrFail();
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        abort_unless(in_array($kind, ['photos','notes'], true), 404);
        $item = $this->scoped($kind, $id);
        abort_if($item->archived_at !== null, 409, 'Restore the record before editing.');
        $values = $kind === 'photos'
            ? $request->validate(['caption'=>['nullable','string','max:187'], 'category'=>['required',Rule::in(['before','during','after'])]])
            : $request->validate(['title'=>['required','string','max:255'], 'body'=>['nullable','string','max:20000'], 'visibility'=>['required',Rule::in(['private','customer_facing'])]]);
        $table = $kind === 'photos' ? 'job_photos' : 'job_notes';
        if ($kind === 'photos') $values['caption'] = trim($values['caption'] ?? '');
        if ($kind === 'notes') {
            $values['body'] = trim($values['body'] ?? '');
            if ($item->note_type === 'text' && $values['body'] === '') return back()->withErrors(['body'=>'Text is required.']);
        }
        DB::table($table)->where('id',$id)->update(array_merge($values,['updated_at'=>now()]));
        AuditLogger::record('admin.job_'.$kind.'.updated',$request->user(),null,Company::findOrFail($item->company_id),
            ['record_id'=>$id,'job_id'=>$item->service_job_id],$request);
        return back()->with('success','Record updated.');
    }

    public function archive(Request $request, string $kind, int $id): RedirectResponse
    {
        abort_unless(in_array($kind, ['photos','notes'], true), 404);
        $item = $this->scoped($kind, $id);
        $table = $kind === 'photos' ? 'job_photos' : 'job_notes';
        DB::table($table)->where('id',$id)->update([
            'archived_at'=>$item->archived_at === null ? now() : null,'updated_at'=>now(),
        ]);
        AuditLogger::record('admin.job_'.$kind.'.archive_toggled',$request->user(),null,
            Company::findOrFail($item->company_id),['record_id'=>$id,'job_id'=>$item->service_job_id],$request);
        return back()->with('success','Record archive state updated.');
    }

    public function download(Request $request, string $kind, int $id)
    {
        abort_unless(in_array($kind, ['photos','notes'], true), 404);
        $item = $this->scoped($kind,$id);
        $path = $kind === 'photos' ? $item->private_path : $item->audio_private_path;
        $digest = $kind === 'photos' ? $item->sha256 : $item->audio_sha256;
        $mime = $kind === 'photos' ? $item->mime_type : $item->audio_mime_type;
        abort_unless($path && $digest && $mime && Storage::disk('local')->exists($path),404);
        $bytes = Storage::disk('local')->get($path);
        abort_unless(is_string($bytes) && hash_equals($digest,hash('sha256',$bytes)),409,'Media integrity failed.');
        AuditLogger::record('admin.job_'.$kind.'.downloaded',$request->user(),null,
            Company::findOrFail($item->company_id),['record_id'=>$id],$request);
        return response($bytes,200,[
            'Content-Type'=>$mime,'Content-Disposition'=>'attachment; filename="Sign2Digital_Media_'.$id.'"',
            'Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function createNote(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_id'=>['required','integer','min:1'],
            'title'=>['required','string','max:255'],
            'body'=>['required','string','max:20000'],
            'visibility'=>['required',Rule::in(['private','customer_facing'])],
        ]);
        $job = ServiceJob::query()->findOrFail($data['job_id']);
        abort_if($job->category === 'archived',409);
        $id = DB::table('job_notes')->insertGetId([
            'company_id'=>$job->company_id,'service_job_id'=>$job->id,
            'created_by'=>$request->user()->id,'title'=>trim($data['title']),
            'body'=>trim($data['body']),'note_type'=>'text','visibility'=>$data['visibility'],
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        AuditLogger::record('admin.job_note.created',$request->user(),null,Company::findOrFail($job->company_id),
            ['note_id'=>$id,'job_id'=>$job->id],$request);
        return redirect()->route('admin.job-media.index',['kind'=>'notes','job_id'=>$job->id])
            ->with('success','Note created.');
    }

    public function uploadPhoto(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_id'=>['required','integer','min:1'],
            'file'=>['required','file','mimes:jpg,jpeg,png','max:20480'],
            'category'=>['required',Rule::in(['before','during','after'])],
            'caption'=>['nullable','string','max:187'],
        ]);
        $job = ServiceJob::query()->findOrFail($data['job_id']);
        abort_if($job->category === 'archived',409);
        $file = $request->file('file');
        $bytes = file_get_contents($file->getRealPath());
        $meta = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        abort_unless(is_array($meta) && in_array($meta['mime'] ?? null,['image/jpeg','image/png'],true),422,'Invalid image.');
        $mime = $meta['mime'];
        if (DB::table('job_photos')->where('company_id',$job->company_id)
            ->where('service_job_id',$job->id)->whereNull('archived_at')
            ->where('sha256',hash('sha256',$bytes))->exists()) {
            return back()->withErrors(['file'=>'This photo already exists for this job.']);
        }
        $path = 'job-media/'.$job->company_id.'/'.$job->id.'/photos/'.Str::uuid().($mime==='image/png'?'.png':'.jpg');
        abort_unless(Storage::disk('local')->put($path,$bytes),503);
        try {
            $id = DB::table('job_photos')->insertGetId([
                'company_id'=>$job->company_id,'service_job_id'=>$job->id,
                'created_by'=>$request->user()->id,'category'=>$data['category'],
                'caption'=>trim($data['caption'] ?? ''),
                'original_name'=>mb_substr($file->getClientOriginalName(),0,255),
                'private_path'=>$path,'mime_type'=>$mime,'byte_size'=>strlen($bytes),
                'sha256'=>hash('sha256',$bytes),'created_at'=>now(),'updated_at'=>now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        AuditLogger::record('admin.job_photo.created',$request->user(),null,Company::findOrFail($job->company_id),
            ['photo_id'=>$id,'job_id'=>$job->id],$request);
        return redirect()->route('admin.job-media.index',['kind'=>'photos','job_id'=>$job->id])
            ->with('success','Photo uploaded.');
    }
}
