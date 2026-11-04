<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkAcceptanceService
{
    public function find(int $companyId,int $id): object
    {
        return DB::table('work_acceptances')->where('company_id',$companyId)
            ->where('id',$id)->firstOrFail();
    }

    public function payload(object $row): array
    {
        return [
            'id'=>(int)$row->id,'company_id'=>(int)$row->company_id,
            'job_id'=>(int)$row->service_job_id,'client_id'=>(int)$row->client_id,
            'number'=>$row->number,'completed_work'=>$row->completed_work,
            'completion_date'=>$row->completion_date,'cleaning_status'=>$row->cleaning_status,
            'outstanding_issues'=>$row->outstanding_issues??'',
            'remedial_work'=>$row->remedial_work??'',
            'photo_ids'=>json_decode((string)($row->photo_ids??'[]'),true)?:[],
            'status'=>$row->status,'revision'=>(int)$row->revision,
            'approved_at'=>$row->approved_at,'sent_at'=>$row->sent_at,
            'signed_at'=>$row->signed_at,'acceptance_result'=>$row->acceptance_result,
            'customer_name'=>$row->customer_name,
            'customer_reservation'=>$row->customer_reservation,
            'customer_outstanding_issues'=>$row->customer_outstanding_issues,
            'customer_rating'=>$row->customer_rating===null?null:(int)$row->customer_rating,
            'customer_comment'=>$row->customer_comment,
            'managed_document_id'=>$row->managed_document_id===null
                ? null:(int)$row->managed_document_id,
            'has_approved_pdf'=>$row->approved_pdf_sha256!==null,
            'has_signed_pdf'=>$row->signed_pdf_sha256!==null,
            'signed_pdf_sha256'=>$row->signed_pdf_sha256,
            'approved_pdf_sha256'=>$row->approved_pdf_sha256,
            'created_at'=>$row->created_at,'updated_at'=>$row->updated_at,
            'project'=>$row->project??DB::table('service_jobs')->where('id',$row->service_job_id)->value('title'),
            'customer'=>$row->customer??DB::table('clients')->where('id',$row->client_id)->value('full_name'),
        ];
    }

    public function list(?int $companyId,array $filters=[]): array
    {
        $q=DB::table('work_acceptances as w')
            ->join('service_jobs as j','j.id','=','w.service_job_id')
            ->join('clients as c','c.id','=','w.client_id')
            ->select('w.*','j.title as project','c.full_name as customer');
        if($companyId!==null)$q->where('w.company_id',$companyId);
        if(!empty($filters['company_id'])&&$companyId===null)
            $q->where('w.company_id',(int)$filters['company_id']);
        if(!empty($filters['job_id']))$q->where('w.service_job_id',(int)$filters['job_id']);
        if(!empty($filters['status']))$q->where('w.status',$filters['status']);
        if(!empty($filters['q'])) {
            $v='%'.addcslashes((string)$filters['q'],'%_\\').'%';
            $q->where(fn($q)=>$q->where('w.number','like',$v)
                ->orWhere('j.title','like',$v)->orWhere('c.full_name','like',$v));
        }
        $p=$q->orderByDesc('w.id')->paginate((int)($filters['per_page']??30));
        return ['records'=>$p->getCollection()->map(fn($r)=>$this->payload($r))->all(),
            'total'=>$p->total(),'page'=>$p->currentPage(),
            'last_page'=>$p->lastPage()];
    }

    public function events(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('work_acceptance_events')->where('company_id',$companyId)
            ->where('work_acceptance_id',$id)->orderBy('id')->get()
            ->map(fn($r)=>[
                'id'=>$r->id,'type'=>$r->event_type,'actor_id'=>$r->actor_user_id,
                'created_at'=>$r->created_at,
                'metadata'=>json_decode((string)($r->metadata??'null'),true),
            ])->all();
    }

    public function revisions(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('work_acceptance_revisions')->where('company_id',$companyId)
            ->where('work_acceptance_id',$id)->orderByDesc('revision')->get()
            ->map(fn($r)=>[
                'revision'=>$r->revision,'snapshot'=>json_decode($r->snapshot,true),
                'created_at'=>$r->created_at,'actor_id'=>$r->actor_user_id,
            ])->all();
    }

    public function event(object $row,string $kind,?int $userId=null,
        ?int $requestId=null,array $data=[]): void
    {
        DB::table('work_acceptance_events')->insert([
            'company_id'=>$row->company_id,'work_acceptance_id'=>$row->id,
            'work_acceptance_request_id'=>$requestId,
            'actor_user_id'=>$userId,'event_type'=>$kind,
            'metadata'=>$data?json_encode($data,JSON_THROW_ON_ERROR):null,
            'created_at'=>now(),
        ]);
    }

    private function snapshot(object $row,?int $userId): void
    {
        DB::table('work_acceptance_revisions')->insert([
            'company_id'=>$row->company_id,'work_acceptance_id'=>$row->id,
            'actor_user_id'=>$userId,'revision'=>$row->revision,
            'snapshot'=>json_encode($this->payload($row),JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }

    public function save(Request $request,Company $company,?int $id=null): array
    {
        $previous=$id===null?null:$this->find($company->id,$id);
        abort_if($previous&&$previous->status!=='draft',409,
            'The approved acceptance report cannot be modified.');
        $data=$request->validate([
            'job_id'=>[$previous?'sometimes':'required','integer','min:1'],
            'revision'=>[$previous?'required':'nullable','integer','min:1'],
            'completed_work'=>[$previous?'sometimes':'required','string','max:16000'],
            'completion_date'=>[$previous?'sometimes':'required','date_format:Y-m-d'],
            'cleaning_status'=>[$previous?'sometimes':'required',
                Rule::in(['cleaned','pending','not_applicable'])],
            'outstanding_issues'=>['nullable','string','max:12000'],
            'remedial_work'=>['nullable','string','max:12000'],
            'photo_ids'=>['sometimes','array','max:8'],
            'photo_ids.*'=>['integer','min:1','distinct'],
        ]);
        $jobId=(int)($data['job_id']??$previous?->service_job_id);
        $job=ServiceJob::query()->where('company_id',$company->id)
            ->where('id',$jobId)->firstOrFail();
        abort_if($job->category==='archived',409,'Archived jobs cannot be accepted.');
        abort_if($previous && ((int)$previous->service_job_id!==$jobId),409,
            'The linked job cannot change.');
        $completed=trim((string)($data['completed_work']??$previous?->completed_work??''));
        if($completed==='')throw ValidationException::withMessages([
            'completed_work'=>'Describe the work that has actually been completed.',
        ]);
        $ids=$data['photo_ids']??json_decode((string)($previous?->photo_ids??'[]'),true)?:[];
        if(count($ids)>0) {
            $matched=DB::table('job_photos')->where('company_id',$company->id)
                ->where('service_job_id',$job->id)->whereNull('archived_at')
                ->whereIn('id',$ids)->count();
            if($matched!==count($ids))throw ValidationException::withMessages([
                'photo_ids'=>'Selected photos must belong to the same job.',
            ]);
        }
        $actor=$request->user()?->id;
        $record=DB::transaction(function () use ($company,$job,$previous,$data,$ids,$actor):object {
            if($previous===null) {
                $id=DB::table('work_acceptances')->insertGetId([
                    'company_id'=>$company->id,'service_job_id'=>$job->id,
                    'client_id'=>$job->client_id,'created_by'=>$actor,
                    'number'=>'TEMP-'.Str::uuid(),'completed_work'=>trim($data['completed_work']),
                    'completion_date'=>$data['completion_date'],
                    'cleaning_status'=>$data['cleaning_status'],
                    'outstanding_issues'=>$data['outstanding_issues']??null,
                    'remedial_work'=>$data['remedial_work']??null,
                    'photo_ids'=>json_encode($ids,JSON_THROW_ON_ERROR),
                    'status'=>'draft','revision'=>1,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('work_acceptances')->where('id',$id)->update([
                    'number'=>'WA-'.str_pad((string)$id,8,'0',STR_PAD_LEFT),
                ]);
                $row=$this->find($company->id,$id);
                $this->snapshot($row,$actor);
                $this->event($row,'created',$actor);
                return $row;
            }
            $row=DB::table('work_acceptances')->where('company_id',$company->id)
                ->where('id',$previous->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='draft' &&
                (int)$row->revision===(int)$data['revision'],409,
                'The acceptance draft changed. Reload before editing.');
            $updates=['revision'=>(int)$row->revision+1,'updated_at'=>now()];
            foreach(['completed_work','completion_date','cleaning_status',
                'outstanding_issues','remedial_work'] as $field) {
                if(array_key_exists($field,$data))$updates[$field]=$data[$field];
            }
            if(array_key_exists('photo_ids',$data)) {
                $updates['photo_ids']=json_encode($ids,JSON_THROW_ON_ERROR);
            }
            DB::table('work_acceptances')->where('id',$row->id)->update($updates);
            $new=$this->find($company->id,$row->id);
            $this->snapshot($new,$actor);
            $this->event($new,'edited',$actor,null,['revision'=>$new->revision]);
            return $new;
        });
        AuditLogger::record($previous?'mobile.acceptance.updated':'mobile.acceptance.created',
            $request->user(),null,$company,['acceptance_id'=>$record->id],$request);
        return $this->payload($record);
    }

    private static function pdfLabel(string $value): string
    {
        return (string)iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',
            mb_substr($value,0,16000));
    }

    public function basePdf(object $row): string
    {
        $pdf=new \FPDF();
        $pdf->SetMargins(19,19,19);
        $pdf->SetAutoPageBreak(true,19);
        $pdf->AddPage();
        $pdf->SetTextColor(22,98,130);
        $pdf->SetFont('Arial','B',17);
        $pdf->MultiCell(0,9,'Sign2Digital | Work Acceptance');
        $pdf->SetTextColor(18,37,54);
        $pdf->SetFont('Arial','',10);
        foreach([
            'Reference'=>$row->number,'Job'=>DB::table('service_jobs')
                ->where('id',$row->service_job_id)->value('title')??'',
            'Client'=>DB::table('clients')->where('id',$row->client_id)
                ->value('full_name')??'',
            'Completion date'=>$row->completion_date,
            'Cleaning / restoration'=>str_replace('_',' ',$row->cleaning_status),
        ] as $label=>$value) {
            $pdf->MultiCell(0,7,self::pdfLabel($label.': '.$value));
        }
        foreach([
            'Completed work'=>$row->completed_work,
            'Outstanding issues'=>$row->outstanding_issues??'',
            'Required remedial work'=>$row->remedial_work??'',
        ] as $label=>$text) {
            if(trim($text)==='')continue;
            $pdf->Ln(3);$pdf->SetFont('Arial','B',11);
            $pdf->MultiCell(0,7,self::pdfLabel($label));
            $pdf->SetFont('Arial','',10);
            $pdf->MultiCell(0,6,self::pdfLabel($text));
        }
        $ids=json_decode((string)($row->photo_ids??'[]'),true)?:[];
        foreach($ids as $photoId) {
            $photo=DB::table('job_photos')->where('company_id',$row->company_id)
                ->where('service_job_id',$row->service_job_id)
                ->where('id',$photoId)->firstOrFail();
            $disk=Storage::disk('local');
            abort_unless($disk->exists($photo->private_path),409,'Selected photo is missing.');
            $bytes=$disk->get($photo->private_path);
            abort_unless(is_string($bytes)&&
                hash_equals($photo->sha256,hash('sha256',$bytes)),409,
                'Selected photo integrity failure.');
            $dimensions=@getimagesizefromstring($bytes);
            abort_unless(is_array($dimensions) && in_array($photo->mime_type,
                ['image/png','image/jpeg'],true) &&
                ($dimensions['mime']??null)===$photo->mime_type,409,
                'Invalid selected photo.');
            $ratio=$dimensions[0]/max(1,$dimensions[1]);
            $w=min(108.0,90.0*$ratio);$h=$w/$ratio;
            if($h>95){$h=95;$w=95*$ratio;}
            if($pdf->GetY()+$h+15>270)$pdf->AddPage();
            $pdf->SetFont('Arial','',9);
            $pdf->MultiCell(0,6,'Job Photo #'.$photoId);
            $y=$pdf->GetY();
            $pdf->Image($disk->path($photo->private_path),19,$y,$w,$h,
                $photo->mime_type==='image/png'?'PNG':'JPG');
            $pdf->SetY($y+$h+5);
        }
        $pdf->Ln(4);
        $pdf->SetFont('Arial','I',9);
        $pdf->MultiCell(0,6,'For customer review. This document is not accepted or signed until customer confirmation.');
        return $pdf->Output('S');
    }

    public function approvedBytes(object $row): string
    {
        abort_unless($row->approved_pdf_path && $row->approved_pdf_sha256,409,
            'Approve acceptance report first.');
        $disk=Storage::disk('local');
        abort_unless($disk->exists($row->approved_pdf_path),404);
        $bytes=$disk->get($row->approved_pdf_path);
        abort_unless(is_string($bytes) &&
            hash_equals($row->approved_pdf_sha256,hash('sha256',$bytes)),409,
            'Approved acceptance integrity mismatch.');
        return $bytes;
    }

    public function signedBytes(object $row): string
    {
        abort_unless($row->signed_pdf_path && $row->signed_pdf_sha256,409,
            'Customer signature is not yet recorded.');
        $disk=Storage::disk('local');
        abort_unless($disk->exists($row->signed_pdf_path),404);
        $bytes=$disk->get($row->signed_pdf_path);
        abort_unless(is_string($bytes) &&
            hash_equals($row->signed_pdf_sha256,hash('sha256',$bytes)),409,
            'Signed acceptance integrity mismatch.');
        return $bytes;
    }

    public function approve(Request $request,Company $company,int $id): array
    {
        $request->validate(['revision'=>['required','integer','min:1'],
            'confirm'=>['required','accepted']]);
        $path=null;
        try {
            $row=DB::transaction(function()use($request,$company,$id,&$path):object {
                $locked=DB::table('work_acceptances')->where('company_id',$company->id)
                    ->where('id',$id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status==='draft' &&
                    (int)$locked->revision===(int)$request->input('revision'),409,
                    'Report already approved or revised.');
                $bytes=$this->basePdf($locked);
                abort_unless(strlen($bytes)<=5*1024*1024,422,
                    'Reduce selected photos to keep the frozen source PDF under 5 MB.');
                $sha=hash('sha256',$bytes);
                $path='work-acceptance/approved/'.$company->id.'/'.$id.'/'.$sha.'.pdf';
                $disk=Storage::disk('local');
                abort_unless(!$disk->exists($path) && $disk->put($path,$bytes) &&
                    hash_equals($sha,hash('sha256',$disk->get($path))),503,
                    'Unable to preserve approved PDF.');
                DB::table('work_acceptances')->where('id',$id)->update([
                    'approved_pdf_path'=>$path,'approved_pdf_sha256'=>$sha,
                    'approved_at'=>now(),'approved_by'=>$request->user()?->id,
                    'status'=>'approved','updated_at'=>now(),
                ]);
                $updated=$this->find($company->id,$id);
                $this->event($updated,'approved',$request->user()?->id);
                return $updated;
            });
        }catch(\Throwable $error){
            if($path)Storage::disk('local')->delete($path);
            throw $error;
        }
        AuditLogger::record('mobile.acceptance.approved',$request->user(),
            null,$company,['acceptance_id'=>$id],$request);
        return $this->payload($row);
    }

    public function requests(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('work_acceptance_requests')->where('company_id',$companyId)
            ->where('work_acceptance_id',$id)->orderByDesc('id')->get([
                'id','request_uuid','status','expires_at','sent_at','viewed_at',
                'signed_at','revoked_at','signer_name','signer_email',
            ])->map(fn($r)=>(array)$r)->all();
    }

    public function send(Request $request,Company $company,int $id): array
    {
        $request->validate(['confirm'=>['required','accepted']]);
        $row=$this->find($company->id,$id);
        abort_unless(in_array($row->status,['approved','sent','viewed'],true),409);
        $this->approvedBytes($row);
        $client=DB::table('clients')->where('company_id',$company->id)
            ->where('id',$row->client_id)->firstOrFail();
        $name=trim($client->full_name);
        $email=trim((string)$client->email);
        abort_unless($name!==''&&filter_var($email,FILTER_VALIDATE_EMAIL),422,
            'The linked client needs a name and valid email address.');
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $signingId=DB::transaction(function()use($request,$row,$token,$email,$name):int {
            $locked=DB::table('work_acceptances')
                ->where('id',$row->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status,['approved','sent','viewed'],true)
                && hash_equals($row->approved_pdf_sha256,$locked->approved_pdf_sha256),409);
            $active=DB::table('work_acceptance_requests')
                ->where('work_acceptance_id',$row->id)
                ->whereIn('status',['ready','sent','viewed'])
                ->where('expires_at','>',now())->exists();
            abort_if($active,409,'Revoke the active customer request before resending.');
            $uuid=(string)Str::uuid();
            $consent=hash('sha256',$locked->approved_pdf_sha256."\n".$uuid);
            $id=DB::table('work_acceptance_requests')->insertGetId([
                'company_id'=>$row->company_id,'work_acceptance_id'=>$row->id,
                'created_by'=>$request->user()?->id,
                'request_uuid'=>$uuid,'document_uuid'=>(string)Str::uuid(),
                'token_sha256'=>hash('sha256',$token),
                'consent_reference'=>$consent,
                'approved_revision'=>$locked->revision,
                'approved_pdf_sha256'=>$locked->approved_pdf_sha256,
                'signer_name'=>$name,'signer_email'=>$email,
                'status'=>'ready','expires_at'=>now()->addDays(7),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($locked,'signature_requested',$request->user()?->id,$id);
            return $id;
        });
        $url=route('acceptance.public.open',['token'=>$token]);
        try{
            Mail::raw('Please review the completed work and sign only if you agree: '.$url,
                function($message)use($email,$row){
                    $message->to($email)->subject('Sign2Digital Work Acceptance '.$row->number);
                });
        }catch(\Throwable $error){
            DB::table('work_acceptance_requests')->where('id',$signingId)
                ->where('status','ready')->update(['status'=>'revoked',
                    'revoked_at'=>now(),'updated_at'=>now()]);
            throw $error;
        }
        DB::transaction(function()use($signingId,$row,$request):void {
            DB::table('work_acceptance_requests')->where('id',$signingId)
                ->where('status','ready')->update([
                    'status'=>'sent','sent_at'=>now(),'updated_at'=>now(),
                ]);
            DB::table('work_acceptances')->where('id',$row->id)->update([
                'status'=>'sent','sent_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($row,'sent',$request->user()?->id,$signingId);
        });
        AuditLogger::record('mobile.acceptance.sent',$request->user(),null,
            $company,['acceptance_id'=>$id,'request_id'=>$signingId],$request);
        return ['report'=>$this->payload($this->find($company->id,$id)),
            'request_id'=>$signingId,'recipient'=>$email];
    }

    public function revoke(Request $request,Company $company,int $id,int $signing): array
    {
        $item=DB::transaction(function()use($company,$id,$signing,$request):object {
            $row=$this->find($company->id,$id);
            $target=DB::table('work_acceptance_requests')->where('company_id',$company->id)
                ->where('work_acceptance_id',$id)->where('id',$signing)
                ->lockForUpdate()->firstOrFail();
            abort_unless(in_array($target->status,['ready','sent','viewed'],true),409);
            DB::table('work_acceptance_requests')->where('id',$signing)->update([
                'status'=>'revoked','revoked_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('work_acceptances')->where('id',$id)->update([
                'status'=>'approved','updated_at'=>now(),
            ]);
            $this->event($row,'request_revoked',$request->user()?->id,$signing);
            return $this->find($company->id,$id);
        });
        return $this->payload($item);
    }
}
