<?php

namespace App\Services;

use App\Models\ServiceQuote;
use App\Models\SignedDocumentArtifact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DocumentCatalogService
{
    /** Existing quotes and signed artifacts remain the authoritative originals. */
    private function unifiedQuery()
    {
        $quotes = DB::table('service_quotes as q')
            ->leftJoin('service_jobs as j', 'j.id', '=', 'q.service_job_id')
            ->selectRaw("'quote' as kind, q.id as entity_id, q.company_id, q.client_id, q.service_job_id as job_id, 'quotes' as category, COALESCE(q.custom_quote_number,q.reference_number) as number, COALESCE(j.title,'') as project, q.client_name as customer, NULL as managed_title, q.status, q.revision as version, CASE WHEN q.approved_pdf_revision = q.revision AND q.approved_pdf_path IS NOT NULL AND q.status IN ('approved','sent','archived') THEN q.approved_pdf_sha256 ELSE NULL END as sha256, q.created_at as occurred_at, q.updated_at");
        $signed = DB::table('signed_document_artifacts as a')
            ->join('signature_requests as s', 's.id', '=', 'a.signature_request_id')
            ->join('service_quotes as q', 'q.id', '=', 'a.service_quote_id')
            ->leftJoin('service_jobs as j', 'j.id', '=', 'q.service_job_id')
            ->selectRaw("'signed' as kind, a.id as entity_id, a.company_id, q.client_id, q.service_job_id as job_id, 'quotes' as category, COALESCE(q.custom_quote_number,q.reference_number) as number, COALESCE(j.title,'') as project, s.signer_name as customer, NULL as managed_title, 'signed' as status, a.source_revision as version, a.signed_pdf_sha256 as sha256, a.created_at as occurred_at, a.created_at as updated_at");
        $managed = DB::table('managed_documents as m')
            ->join('managed_document_versions as v', function ($join): void {
                $join->on('v.managed_document_id', '=', 'm.id')
                    ->on('v.version', '=', 'm.current_version');
            })
            ->leftJoin('service_jobs as j', 'j.id', '=', 'm.service_job_id')
            ->leftJoin('clients as c', 'c.id', '=', 'm.client_id')
            ->selectRaw("'managed' as kind, m.id as entity_id, m.company_id, m.client_id, m.service_job_id as job_id, m.category, m.number, COALESCE(j.title,'') as project, COALESCE(c.full_name,'') as customer, m.title as managed_title, m.status, m.current_version as version, v.sha256, m.created_at as occurred_at, m.updated_at");
        $addenda = DB::table('addenda as d')
            ->join('service_quotes as q','q.id','=','d.service_quote_id')
            ->leftJoin('service_jobs as j','j.id','=','d.service_job_id')
            ->selectRaw("'addendum' as kind, d.id as entity_id, d.company_id, d.client_id, d.service_job_id as job_id, 'addends' as category, d.number, COALESCE(j.title,'') as project, q.client_name as customer, d.title as managed_title, d.status, d.revision as version, CASE WHEN d.status = 'signed' THEN d.signed_pdf_sha256 WHEN d.status IN ('approved','sent') THEN d.approved_pdf_sha256 ELSE NULL END as sha256, d.created_at as occurred_at, d.updated_at");
        return DB::query()->fromSub($quotes->unionAll($signed)->unionAll($managed)->unionAll($addenda), 'documents');
    }

    private function format(object $row): array
    {
        $kind = (string) $row->kind;
        $id = (int) $row->entity_id;
        return [
            'id' => $kind.'-'.$id, 'kind' => $kind, 'entity_id' => $id,
            'company_id' => (int) $row->company_id,
            'client_id' => $row->client_id === null ? null : (int) $row->client_id,
            'job_id' => $row->job_id === null ? null : (int) $row->job_id,
            'category' => (string) $row->category, 'number' => (string) $row->number,
            'title' => $kind === 'signed' ? 'Signed Quote '.$row->number :
                ($kind === 'quote' ? 'Quote '.$row->number :
                ($kind === 'addendum' ? 'Addendum '.$row->number : (string) $row->managed_title)),
            'project' => (string) $row->project, 'customer' => (string) $row->customer,
            'status' => (string) $row->status, 'version' => (int) $row->version,
            'sha256' => $row->sha256, 'has_pdf' => $row->sha256 !== null &&
                ($kind !== 'quote' || in_array($row->status, ['approved','sent','archived'], true)),
            'created_at' => $row->occurred_at, 'updated_at' => $row->updated_at,
        ];
    }

    public function listing(?int $companyId, array $filters): array
    {
        $query = $this->unifiedQuery();
        if ($companyId !== null) $query->where('company_id', $companyId);
        foreach (['category','status','kind'] as $field) {
            if (!empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        foreach (['client_id','job_id'] as $field) {
            if (!empty($filters[$field])) $query->where($field, (int) $filters[$field]);
        }
        if (!empty($filters['q'])) {
            $needle = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('number', 'like', $needle)
                    ->orWhere('customer', 'like', $needle)
                    ->orWhere('project', 'like', $needle);
            });
        }
        $paginator = $query->orderByDesc('occurred_at')->orderByDesc('entity_id')
            ->paginate((int) ($filters['per_page'] ?? 30));
        return [
            'documents' => $paginator->getCollection()->map(fn ($row) => $this->format($row))->values()->all(),
            'total' => $paginator->total(), 'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function resolve(int $companyId, string $key): array
    {
        abort_unless(preg_match('/^(quote|signed|managed|addendum)-([1-9][0-9]*)$/D', $key, $match), 404);
        $row = $this->unifiedQuery()
            ->where('company_id', $companyId)
            ->where('kind', $match[1])
            ->where('entity_id', (int) $match[2])->first();
        abort_unless($row, 404);
        return $this->format($row);
    }

    /**
     * Link original approved quotes to the signed artifacts actually created
     * from those quotes. Never infer a signature from a matching customer name.
     */
    public function related(int $companyId, string $key): array
    {
        $document=$this->resolve($companyId,$key);
        if ($document['kind']==='quote') {
            $ids=DB::table('signed_document_artifacts')
                ->where('company_id',$companyId)
                ->where('service_quote_id',$document['entity_id'])
                ->orderByDesc('id')->limit(30)->pluck('id');
            $signed=$ids->map(fn ($id) => $this->resolve($companyId,'signed-'.$id))->all();
            $addendumIds=DB::table('addenda')->where('company_id',$companyId)
                ->where('service_quote_id',$document['entity_id'])
                ->orderByDesc('id')->limit(30)->pluck('id');
            return array_merge($signed,$addendumIds->map(fn($id)=>
                $this->resolve($companyId,'addendum-'.$id))->all());
        }
        if ($document['kind']==='signed') {
            $quoteId=DB::table('signed_document_artifacts')
                ->where('company_id',$companyId)
                ->where('id',$document['entity_id'])
                ->value('service_quote_id');
            $result=$quoteId ? [$this->resolve($companyId,'quote-'.$quoteId)] : [];
            $addendumIds=DB::table('addenda')->where('company_id',$companyId)
                ->where('source_signed_artifact_id',$document['entity_id'])->pluck('id');
            return array_merge($result,$addendumIds->map(fn($id)=>
                $this->resolve($companyId,'addendum-'.$id))->all());
        }
        if ($document['kind']==='addendum') {
            $source=DB::table('addenda')->where('company_id',$companyId)
                ->where('id',$document['entity_id'])->value('source_signed_artifact_id');
            return $source ? [$this->resolve($companyId,'signed-'.$source)] : [];
        }
        return [];
    }

    public function versions(int $companyId, string $key): array
    {
        $item = $this->resolve($companyId, $key);
        if ($item['kind'] === 'managed') {
            return DB::table('managed_document_versions')
                ->where('company_id', $companyId)->where('managed_document_id', $item['entity_id'])
                ->orderByDesc('version')->get(['id','version','original_name','mime_type','byte_size','sha256','created_at'])
                ->map(fn ($v) => (array) $v)->all();
        }
        if ($item['kind'] === 'quote') {
            return DB::table('service_quote_revisions')->where('service_quote_id', $item['entity_id'])
                ->orderByDesc('revision')->get(['id','revision as version','created_at'])
                ->map(fn ($v) => (array) $v)->all();
        }
        if ($item['kind'] === 'addendum') {
            return DB::table('addendum_revisions')
                ->where('company_id',$companyId)->where('addendum_id',$item['entity_id'])
                ->orderByDesc('revision')->get(['id','revision as version','created_at'])
                ->map(fn($v)=>(array)$v)->all();
        }
        $artifact = DB::table('signed_document_artifacts')->where('company_id',$companyId)
            ->where('id',$item['entity_id'])->first(['source_revision','source_pdf_sha256','created_at']);
        return $artifact ? [[
            'version' => (int) $artifact->source_revision,
            'source_sha256' => $artifact->source_pdf_sha256,
            'signed_sha256' => $item['sha256'],
            'created_at' => $artifact->created_at,
        ]] : [];
    }

    public function attachments(int $companyId, string $key): array
    {
        $item = $this->resolve($companyId, $key);
        return DB::table('document_attachments')
            ->where('company_id',$companyId)->where('target_kind',$item['kind'])
            ->where('target_id',$item['entity_id'])->orderByDesc('id')
            ->get(['id','original_name','mime_type','byte_size','sha256','created_at'])
            ->map(fn ($a) => (array) $a)->all();
    }

    /** Only events supported by persisted timestamps and real event rows. */
    public function history(int $companyId, string $key): array
    {
        $item = $this->resolve($companyId, $key);
        $kind = $item['kind']; $id = $item['entity_id'];
        $history = DB::table('document_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')
            ->where('e.company_id',$companyId)->where('e.target_kind',$kind)
            ->where('e.target_id',$id)
            ->get(['e.id','e.event_type','e.created_at','u.name as actor_name'])
            ->map(fn ($row) => ['id'=>'document-event-'.$row->id,
                'type'=>$row->event_type,'occurred_at'=>$row->created_at,
                'actor_name'=>$row->actor_name])->all();
        if ($kind === 'addendum') {
            foreach (DB::table('addendum_events as ae')
                ->leftJoin('users as u','u.id','=','ae.actor_user_id')
                ->where('ae.company_id',$companyId)->where('ae.addendum_id',$id)
                ->orderBy('ae.id')->get(['ae.id','ae.event_type','ae.created_at','u.name as actor_name']) as $event) {
                $history[]=['id'=>'addendum-event-'.$event->id,
                    'type'=>$event->event_type,'occurred_at'=>$event->created_at,
                    'actor_name'=>$event->actor_name];
            }
        }
        if ($kind === 'quote') {
            $q = ServiceQuote::query()->where('company_id',$companyId)->findOrFail($id);
            $history[] = ['id'=>'quote-created-'.$id,'type'=>'created','occurred_at'=>$q->created_at,'actor_name'=>null];
            foreach (DB::table('service_quote_revisions')->where('service_quote_id',$id)
                         ->where('revision','>',1)->orderBy('revision')->get(['id','created_at']) as $r) {
                $history[]=['id'=>'quote-revised-'.$r->id,'type'=>'version_changed','occurred_at'=>$r->created_at,'actor_name'=>null];
            }
            foreach (['approved_at'=>'approved','sent_at'=>'sent','archived_at'=>'archived'] as $column=>$type) {
                if ($q->{$column} !== null) $history[]=['id'=>'quote-'.$type.'-'.$id,
                    'type'=>$type,'occurred_at'=>$q->{$column},'actor_name'=>null];
            }
            $requests=DB::table('signature_requests')->where('company_id',$companyId)
                ->where('service_quote_id',$id)->pluck('id');
        } elseif ($kind === 'signed') {
            $artifact=SignedDocumentArtifact::query()->where('company_id',$companyId)->findOrFail($id);
            $requests=collect([$artifact->signature_request_id]);
        } else {
            $requests=collect();
        }
        if ($requests->isNotEmpty()) {
            foreach (DB::table('signature_events as e')
                ->leftJoin('users as u','u.id','=','e.actor_user_id')
                ->where('e.company_id',$companyId)->whereIn('e.signature_request_id',$requests)
                ->orderBy('e.id')->get(['e.id','e.event_type','e.created_at','u.name as actor_name']) as $event) {
                $mapped = match ($event->event_type) {
                    'document_sent' => 'sent', 'document_viewed' => 'viewed',
                    'document_signed', 'signature_completed' => 'signed',
                    default => null,
                };
                if ($mapped !== null) $history[]=['id'=>'signature-event-'.$event->id,
                    'type'=>$mapped,'occurred_at'=>$event->created_at,'actor_name'=>$event->actor_name];
            }
        }
        usort($history, fn ($a,$b) => strcmp((string)$b['occurred_at'], (string)$a['occurred_at']));
        return $history;
    }

    /** Fail closed on missing, altered, or substituted private documents. */
    private function storedBytes(string $path, string $digest, int $maxBytes = 10485760): string
    {
        $disk=Storage::disk('local');
        if (!$disk->exists($path)) throw new HttpException(503,'Document file is unavailable.');
        $bytes=$disk->get($path);
        if (!is_string($bytes) || strlen($bytes)<12 || strlen($bytes)>$maxBytes ||
            !hash_equals($digest,hash('sha256',$bytes))) {
            throw new HttpException(503,'Document integrity verification failed.');
        }
        return $bytes;
    }

    public function bytes(int $companyId, string $key): string
    {
        $item=$this->resolve($companyId,$key);
        $id=$item['entity_id'];
        if ($item['kind'] === 'signed') {
            $artifact=SignedDocumentArtifact::query()->where('company_id',$companyId)->findOrFail($id);
            return app(SignatureCompletionService::class)->bytes($artifact);
        }
        if ($item['kind'] === 'addendum') {
            $addendum=\App\Models\Addendum::query()->where('company_id',$companyId)->findOrFail($id);
            $service=app(\App\Services\AddendumService::class);
            return $addendum->status==='signed'
                ? $service->signedBytes($addendum)
                : $service->approvedBytes($addendum);
        }
        if ($item['kind'] === 'quote') {
            $quote=ServiceQuote::query()->where('company_id',$companyId)->findOrFail($id);
            abort_unless(in_array($quote->status,['approved','sent','archived'],true),409,
                'This quote has not been approved.');
            abort_unless($quote->approved_pdf_path && $quote->approved_pdf_sha256 &&
                (int)$quote->approved_pdf_revision === (int)$quote->revision,409,
                'No verified approved PDF exists for this quote.');
            $bytes=$this->storedBytes($quote->approved_pdf_path,$quote->approved_pdf_sha256,5242880);
        } else {
            $v=DB::table('managed_document_versions as v')
                ->join('managed_documents as m','m.id','=','v.managed_document_id')
                ->where('v.company_id',$companyId)->where('m.company_id',$companyId)
                ->where('m.id',$id)->whereColumn('v.version','m.current_version')
                ->first(['v.private_path','v.sha256']);
            abort_unless($v,404);
            $bytes=$this->storedBytes($v->private_path,$v->sha256);
        }
        if (!str_starts_with($bytes,'%PDF-') ||
            !str_contains(substr($bytes,-4096),'%%EOF')) {
            throw new HttpException(503,'Document PDF validation failed.');
        }
        return $bytes;
    }

    public function attachmentBytes(int $companyId, string $key, int $attachmentId): array
    {
        $item=$this->resolve($companyId,$key);
        $row=DB::table('document_attachments')->where('company_id',$companyId)
            ->where('target_kind',$item['kind'])->where('target_id',$item['entity_id'])
            ->where('id',$attachmentId)->first();
        abort_unless($row,404);
        return [$this->storedBytes($row->private_path,$row->sha256),
            (string)$row->mime_type];
    }

    public function recordEvent(int $companyId, string $key, string $event, ?int $userId): void
    {
        $item=$this->resolve($companyId,$key);
        DB::table('document_events')->insert([
            'company_id'=>$companyId,'target_kind'=>$item['kind'],
            'target_id'=>$item['entity_id'],'actor_user_id'=>$userId,
            'event_type'=>$event,'created_at'=>now(),
        ]);
    }

    public function saveFile(int $companyId, Request $request, ?string $key = null): array
    {
        $data=$request->validate([
            'file'=>['required','file','mimes:pdf','max:10240'],
            'title'=>[$key ? 'nullable' : 'required','string','max:255'],
            'number'=>['nullable','string','max:80'],
            'category'=>[$key ? 'nullable' : 'required','in:reports,addends,receipts,invoices,other,contacts'],
            'client_id'=>['nullable','integer','min:1'],
            'job_id'=>['nullable','integer','min:1'],
        ]);
        $file=$request->file('file');
        $bytes=file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && strlen($bytes)>12 && str_starts_with($bytes,'%PDF-')
            && str_contains(substr($bytes,-4096),'%%EOF'),422,'Invalid PDF file.');
        $client=$data['client_id'] ?? null;
        $job=$data['job_id'] ?? null;
        if ($key === null) {
            if ($client) abort_unless(DB::table('clients')->where('company_id',$companyId)->where('id',$client)->exists(),422);
            if ($job) {
                $j=DB::table('service_jobs')->where('company_id',$companyId)->where('id',$job)->first(['client_id']);
                abort_unless($j && (!$client || (int)$j->client_id === (int)$client),422);
            }
        }
        $path='m9-documents/'.$companyId.'/'.Str::uuid().'.pdf';
        abort_unless(Storage::disk('local')->put($path,$bytes),503,'Unable to store document.');
        try {
            $id=DB::transaction(function () use ($companyId,$request,$data,$key,$path,$bytes,$file): int {
                if ($key === null) {
                    $id=DB::table('managed_documents')->insertGetId([
                        'company_id'=>$companyId,'client_id'=>$data['client_id']??null,
                        'service_job_id'=>$data['job_id']??null,'created_by'=>$request->user()->id,
                        'category'=>$data['category'],'title'=>$data['title'],
                        'number'=>$data['number']??'DOCUMENT','status'=>'file',
                        'current_version'=>1,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                    $version=1; $event='created';
                } else {
                    $item=$this->resolve($companyId,$key);
                    abort_unless($item['kind']==='managed',409,'Only uploaded documents can have new versions.');
                    $managed=DB::table('managed_documents')->where('company_id',$companyId)
                        ->where('id',$item['entity_id'])->lockForUpdate()->first();
                    abort_unless($managed,404);
                    $id=(int)$managed->id;
                    $version=(int)$managed->current_version+1;
                    DB::table('managed_documents')->where('id',$id)->update([
                        'current_version'=>$version,'updated_at'=>now(),
                    ]);
                    $event='version_changed';
                }
                DB::table('managed_document_versions')->insert([
                    'company_id'=>$companyId,'managed_document_id'=>$id,'version'=>$version,
                    'original_name'=>mb_substr(basename($file->getClientOriginalName()),0,255),
                    'private_path'=>$path,'mime_type'=>'application/pdf',
                    'byte_size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),
                    'uploaded_by'=>$request->user()->id,'created_at'=>now(),
                ]);
                DB::table('document_events')->insert([
                    'company_id'=>$companyId,'target_kind'=>'managed','target_id'=>$id,
                    'actor_user_id'=>$request->user()->id,'event_type'=>$event,'created_at'=>now(),
                ]);
                return $id;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return $this->resolve($companyId,'managed-'.$id);
    }

    public function saveAttachment(int $companyId, Request $request, string $key): array
    {
        $item=$this->resolve($companyId,$key);
        $request->validate(['file'=>['required','file','mimes:pdf,png,jpg,jpeg','max:10240']]);
        $file=$request->file('file');
        $mime=$file->getMimeType();
        abort_unless(in_array($mime,['application/pdf','image/png','image/jpeg'],true),422);
        $bytes=file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes) && strlen($bytes)>0,422);
        $extension=match($mime){'application/pdf'=>'pdf','image/png'=>'png',default=>'jpg'};
        if ($mime==='application/pdf') abort_unless(
            str_starts_with($bytes,'%PDF-') && str_contains(substr($bytes,-4096),'%%EOF'),422);
        $path='m9-attachments/'.$companyId.'/'.Str::uuid().'.'.$extension;
        abort_unless(Storage::disk('local')->put($path,$bytes),503,'Unable to store attachment.');
        try {
            $id=DB::transaction(function () use ($companyId,$request,$item,$file,$path,$mime,$bytes): int {
                $id=DB::table('document_attachments')->insertGetId([
                    'company_id'=>$companyId,'target_kind'=>$item['kind'],
                    'target_id'=>$item['entity_id'],
                    'original_name'=>mb_substr(basename($file->getClientOriginalName()),0,255),
                    'private_path'=>$path,'mime_type'=>$mime,'byte_size'=>strlen($bytes),
                    'sha256'=>hash('sha256',$bytes),'uploaded_by'=>$request->user()->id,
                    'created_at'=>now(),
                ]);
                DB::table('document_events')->insert([
                    'company_id'=>$companyId,'target_kind'=>$item['kind'],
                    'target_id'=>$item['entity_id'],'actor_user_id'=>$request->user()->id,
                    'event_type'=>'attachment_added','created_at'=>now(),
                ]);
                return $id;
            });
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        return ['id'=>$id,'original_name'=>basename($file->getClientOriginalName()),
            'mime_type'=>$mime,'byte_size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
    }
}
