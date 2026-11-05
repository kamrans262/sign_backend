<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DepositService
{
    public static function minor(mixed $raw): int
    {
        if (!is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$raw)) {
            throw ValidationException::withMessages(['amount'=>'Enter a positive CHF amount with up to two decimals.']);
        }
        [$whole,$dec]=array_pad(explode('.',$raw,2),2,'');
        $minor=(int)$whole*100+(int)str_pad($dec,2,'0');
        if ($minor<=0 || $minor>99999999999) {
            throw ValidationException::withMessages(['amount'=>'Unsupported deposit amount.']);
        }
        return $minor;
    }

    private function event(int $company,int $id,?int $actor,string $type,array $meta=[]): void
    {
        DB::table('job_deposit_events')->insert([
            'company_id'=>$company,'job_deposit_id'=>$id,
            'actor_user_id'=>$actor,'event_type'=>$type,
            'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR),'created_at'=>now(),
        ]);
    }

    private function authorisedQuote(Company $company,int $jobId,?int $quoteId): ?ServiceQuote
    {
        if ($quoteId===null) return null;
        $quote=ServiceQuote::query()->where('company_id',$company->id)
            ->where('service_job_id',$jobId)->findOrFail($quoteId);
        abort_unless(in_array($quote->status,['approved','sent'],true)
            && $quote->approved_at!==null
            && $quote->deposit_minor!==null
            && (int)$quote->deposit_minor>0
            && $quote->currency==='CHF',422,
            'An approved CHF quote with a deposit is required.');
        return $quote;
    }

    private function guardQuotedCapacity(ServiceQuote $quote,int $amount,?int $exclude=null): void
    {
        $query=DB::table('job_deposits')->where('company_id',$quote->company_id)
            ->where('service_quote_id',$quote->id)
            ->whereIn('status',['pending','received']);
        if ($exclude!==null) $query->where('id','!=',$exclude);
        $already=(int)$query->sum('amount_minor');
        abort_if($already+$amount>(int)$quote->deposit_minor,409,
            'Requested deposits exceed the approved quote deposit amount.');
    }

    public function find(int $company,int $id): object
    {
        return DB::table('job_deposits as d')
            ->join('service_jobs as j','j.id','=','d.service_job_id')
            ->whereColumn('d.company_id','j.company_id')
            ->where('d.company_id',$company)->where('d.id',$id)
            ->select('d.*','j.title as job_title',
                'j.reference_number as job_reference')->firstOrFail();
    }

    public function payload(object $row): array
    {
        return [
            'id'=>(int)$row->id,'company_id'=>(int)$row->company_id,
            'job_id'=>(int)$row->service_job_id,
            'quote_id'=>$row->service_quote_id===null?null:(int)$row->service_quote_id,
            'job'=>$row->job_title??'','job_reference'=>$row->job_reference??'',
            'client_name'=>$row->client_name_snapshot,
            'amount_minor'=>(int)$row->amount_minor,'currency'=>'CHF',
            'deposit_date'=>$row->deposit_date,'payment_method'=>$row->payment_method,
            'reference_number'=>$row->reference_number??'','notes'=>$row->notes??'',
            'status'=>$row->status,'lock_version'=>(int)$row->lock_version,
            'received_at'=>$row->received_at,'received_by'=>$row->received_by,
            'refunded_at'=>$row->refunded_at,'refunded_by'=>$row->refunded_by,
            'refund_reason'=>$row->refund_reason,
            'voided_at'=>$row->voided_at,'void_reason'=>$row->void_reason,
            'created_at'=>$row->created_at,'updated_at'=>$row->updated_at,
        ];
    }

    public function listing(?int $company,array $filters=[]): array
    {
        $q=DB::table('job_deposits as d')
            ->join('service_jobs as j','j.id','=','d.service_job_id')
            ->whereColumn('d.company_id','j.company_id')
            ->select('d.*','j.title as job_title','j.reference_number as job_reference');
        if ($company!==null) $q->where('d.company_id',$company);
        elseif (!empty($filters['company_id'])) $q->where('d.company_id',(int)$filters['company_id']);
        foreach (['job_id'=>'d.service_job_id','quote_id'=>'d.service_quote_id',
            'status'=>'d.status'] as $filter=>$column) {
            if (isset($filters[$filter])&&$filters[$filter]!=='') $q->where($column,$filters[$filter]);
        }
        if (empty($filters['include_void']) && ($filters['status']??null)!=='void')
            $q->where('d.status','!=','void');
        if (!empty($filters['q'])) {
            $query='%'.addcslashes($filters['q'],'%_').'%';
            $q->where(fn($x)=>$x->where('d.client_name_snapshot','like',$query)
                ->orWhere('d.reference_number','like',$query)
                ->orWhere('j.reference_number','like',$query)
                ->orWhere('j.title','like',$query));
        }
        $total=(clone $q)->count();
        $per=min(100,max(1,(int)($filters['per_page']??30)));
        $page=max(1,(int)($filters['page']??1));
        $rows=$q->orderByDesc('d.deposit_date')->orderByDesc('d.id')
            ->offset(($page-1)*$per)->limit($per)->get();
        return ['records'=>$rows->map(fn($r)=>$this->payload($r))->all(),
            'total'=>$total,'page'=>$page,
            'last_page'=>max(1,(int)ceil($total/$per))];
    }

    public function save(Request $request,Company $company,?int $id=null): array
    {
        $data=$request->validate([
            'job_id'=>['required','integer','min:1'],
            'quote_id'=>['nullable','integer','min:1'],
            'client_name'=>['nullable','string','max:255'],
            'amount'=>['required','string','max:30'],
            'deposit_date'=>['required','date_format:Y-m-d'],
            'payment_method'=>['required',Rule::in(['cash','bank_transfer','other'])],
            'reference_number'=>['nullable','string','max:120'],
            'notes'=>['nullable','string','max:12000'],
            'lock_version'=>[$id===null?'nullable':'required','integer','min:1'],
            'request_key'=>['nullable','string','between:8,100','regex:/^[A-Za-z0-9._-]+$/D'],
        ]);
        $minor=self::minor($data['amount']);
        $key=$id===null?($data['request_key']??null):null;
        $fingerprint=$key===null?null:hash('sha256',json_encode([
            'job_id'=>(int)$data['job_id'],
            'quote_id'=>isset($data['quote_id'])?(int)$data['quote_id']:null,
            'amount_minor'=>$minor,'deposit_date'=>$data['deposit_date'],
            'payment_method'=>$data['payment_method'],
            'client_name'=>trim((string)($data['client_name']??'')),
            'reference_number'=>trim((string)($data['reference_number']??'')),
            'notes'=>trim((string)($data['notes']??'')),
        ],JSON_THROW_ON_ERROR));
        $actor=$request->user()?->id;
        $replayed=false;
        $saved=DB::transaction(function () use ($company,$data,$minor,$actor,$id,
            $key,$fingerprint,&$replayed): object {
            // A company row lock serializes concurrent deposit requests.
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            if($id===null && $key!==null) {
                $prior=DB::table('job_deposits')->where('company_id',$company->id)
                    ->where('request_key',$key)->first();
                if($prior!==null) {
                    abort_unless($prior->request_fingerprint!==null &&
                        hash_equals((string)$prior->request_fingerprint,(string)$fingerprint),
                        409,'The request key was previously used for different deposit details.');
                    $replayed=true;
                    return $this->find($company->id,(int)$prior->id);
                }
            }
            $job=ServiceJob::query()->where('company_id',$company->id)
                ->where('category','!=','archived')->findOrFail((int)$data['job_id']);
            $quote=$this->authorisedQuote($company,$job->id,
                isset($data['quote_id'])?(int)$data['quote_id']:null);
            $client=DB::table('clients')->where('company_id',$company->id)
                ->where('id',$job->client_id)->first();
            abort_unless($client!==null,422,'Job client is not valid for the company.');
            $clientName=trim((string)($data['client_name']??''));
            if ($clientName!=='' && strcasecmp($clientName,(string)$client->full_name)!==0) {
                throw ValidationException::withMessages([
                    'client_name'=>'Client must match the selected job.',
                ]);
            }
            $values=[
                'amount_minor'=>$minor,'deposit_date'=>$data['deposit_date'],
                'payment_method'=>$data['payment_method'],
                'reference_number'=>trim((string)($data['reference_number']??''))?:null,
                'notes'=>trim((string)($data['notes']??''))?:null,
                'updated_by'=>$actor,'updated_at'=>now(),
            ];
            if ($id===null) {
                if ($quote) $this->guardQuotedCapacity($quote,$minor);
                $new=DB::table('job_deposits')->insertGetId(array_merge($values,[
                    'company_id'=>$company->id,'service_job_id'=>$job->id,
                    'service_quote_id'=>$quote?->id,
                    'request_key'=>$key,'request_fingerprint'=>$fingerprint,
                    'client_name_snapshot'=>$client->full_name,
                    'status'=>'pending','lock_version'=>1,
                    'created_by'=>$actor,'created_at'=>now(),
                ]));
                $this->event($company->id,$new,$actor,'created',[
                    'amount_minor'=>$minor,'quote_id'=>$quote?->id]);
                return $this->find($company->id,$new);
            }
            $old=DB::table('job_deposits')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($old->status==='pending',409,
                'Received, refunded and voided deposits cannot be edited.');
            abort_unless((int)$old->lock_version===(int)$data['lock_version'],409,
                'Deposit changed. Reload before saving.');
            abort_unless((int)$old->service_job_id===$job->id &&
                ($old->service_quote_id===null?null:(int)$old->service_quote_id)===$quote?->id,
                409,'Linked job and approved quote cannot be reassigned.');
            if ($quote) $this->guardQuotedCapacity($quote,$minor,$id);
            DB::table('job_deposits')->where('id',$id)
                ->update(array_merge($values,['lock_version'=>$old->lock_version+1]));
            $this->event($company->id,$id,$actor,'updated',['amount_minor'=>$minor]);
            return $this->find($company->id,$id);
        });
        if(!$replayed) AuditLogger::record($id===null?'deposits.created':'deposits.updated',
            $request->user(),null,$company,['deposit_id'=>$saved->id],$request);
        return $this->payload($saved);
    }

    public function fromQuote(Request $request,Company $company,int $quoteId): array
    {
        $quote=ServiceQuote::query()->where('company_id',$company->id)
            ->findOrFail($quoteId);
        abort_unless($quote->service_job_id!==null,422,'Quote must be linked to a job.');
        $used=(int)DB::table('job_deposits')->where('company_id',$company->id)
            ->where('service_quote_id',$quoteId)
            ->whereIn('status',['pending','received'])->sum('amount_minor');
        $available=(int)$quote->deposit_minor-$used;
        abort_unless($available>0,409,'Approved quote deposit already fully requested.');
        $request->merge([
            'job_id'=>$quote->service_job_id,
            'quote_id'=>$quote->id,
            'amount'=>number_format($available/100,2,'.',''),
            'deposit_date'=>now()->format('Y-m-d'),
            'payment_method'=>'bank_transfer',
            'notes'=>'Deposit request from approved quote '.$quote->reference_number,
        ]);
        return $this->save($request,$company);
    }

    private function reviewPermission(Request $request,Company $company): void
    {
        $actor=$request->user();
        if ($actor?->is_platform_admin) return;
        $member=$actor===null?null:$company->users()
            ->where('users.id',$actor->id)->wherePivot('status','active')->first();
        abort_unless(in_array($member?->pivot?->role,['owner','admin'],true),403,
            'Company owner or administrator must confirm deposit payments.');
    }

    public function change(Request $request,Company $company,int $id,string $action): array
    {
        // Financial status changes cannot be self-approved by ordinary workers.
        $this->reviewPermission($request,$company);
        abort_unless(in_array($action,['receive','refund','void'],true),404);
        $data=$request->validate([
            'confirm'=>['required','accepted'],
            'reason'=>[$action==='receive'?'nullable':'required','string','min:8','max:2000'],
            'lock_version'=>['nullable','integer','min:1'],
        ]);
        $actor=$request->user()?->id;
        $saved=DB::transaction(function()use($company,$id,$action,$actor,$data):object {
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $old=DB::table('job_deposits')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            if (isset($data['lock_version'])) abort_unless(
                (int)$data['lock_version']===(int)$old->lock_version,409,
                'Deposit changed. Reload and try again.');
            $expected=$action==='refund'?'received':'pending';
            abort_unless($old->status===$expected,409,
                'Deposit status does not allow this transition.');
            $now=now();
            $values=['status'=>match($action){
                'receive'=>'received','refund'=>'refunded','void'=>'void',
            },'lock_version'=>$old->lock_version+1,'updated_by'=>$actor,
                'updated_at'=>$now];
            if ($action==='receive') {
                $values['received_at']=$now;$values['received_by']=$actor;
            } elseif ($action==='refund') {
                $values['refunded_at']=$now;$values['refunded_by']=$actor;
                $values['refund_reason']=trim((string)$data['reason']);
            } else {
                $values['voided_at']=$now;
                $values['void_reason']=trim((string)$data['reason']);
            }
            DB::table('job_deposits')->where('id',$id)->update($values);
            $this->event($company->id,$id,$actor,$action==='receive'?
                'received':($action==='refund'?'refunded':'voided'),[
                'reason'=>trim((string)($data['reason']??'')),
                'manual_only'=>true,'no_bank_transaction'=>true,
            ]);
            return $this->find($company->id,$id);
        });
        AuditLogger::record('deposits.'.$action,$request->user(),null,$company,
            ['deposit_id'=>$id,'manual_only'=>true],$request);
        return $this->payload($saved);
    }

    public function summary(int $company,int $job,?int $quoteId=null): array
    {
        ServiceJob::query()->where('company_id',$company)->findOrFail($job);
        // Read-only history remains available if an approved quote is later archived.
        if($quoteId!==null) ServiceQuote::query()->where('company_id',$company)
            ->where('service_job_id',$job)->findOrFail($quoteId);
        $base=DB::table('job_deposits')->where('company_id',$company)
            ->where('service_job_id',$job);
        if($quoteId!==null)$base->where('service_quote_id',$quoteId);
        $pending=(int)(clone $base)->where('status','pending')->sum('amount_minor');
        $received=(int)(clone $base)->where('status','received')->sum('amount_minor');
        $refunded=(int)(clone $base)->where('status','refunded')->sum('amount_minor');
        $expected=null;
        if($quoteId!==null)$expected=(int)ServiceQuote::query()
            ->where('company_id',$company)->findOrFail($quoteId)->deposit_minor;
        return ['job_id'=>$job,'quote_id'=>$quoteId,'currency'=>'CHF',
            'pending_minor'=>$pending,'received_minor'=>$received,
            'refunded_minor'=>$refunded,'net_received_minor'=>$received,
            'expected_quote_deposit_minor'=>$expected,
            'unrequested_quote_deposit_minor'=>$expected===null?null:
                max(0,$expected-$pending-$received),
            'remaining_quote_deposit_to_collect_minor'=>$expected===null?null:
                max(0,$expected-$received),
            'invoice_application_status'=>'not_yet_integrated',
        ];
    }

    public function receipts(int $company,int $id): array
    {
        $this->find($company,$id);
        return DB::table('job_deposit_receipts')->where('company_id',$company)
            ->where('job_deposit_id',$id)->whereNull('removed_at')
            ->orderBy('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'name'=>$r->original_name,
                'sha256'=>$r->sha256,'mime_type'=>$r->mime_type,
                'byte_size'=>(int)$r->byte_size,'created_at'=>$r->created_at,
            ])->all();
    }

    public function history(int $company,int $id): array
    {
        $this->find($company,$id);
        return DB::table('job_deposit_events')->where('company_id',$company)
            ->where('job_deposit_id',$id)->orderBy('id')->get()
            ->map(fn($r)=>[
                'id'=>(int)$r->id,'type'=>$r->event_type,
                'actor_user_id'=>$r->actor_user_id,'created_at'=>$r->created_at,
                'metadata'=>json_decode($r->metadata??'{}',true)?:[],
            ])->all();
    }

    public function upload(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:10240'],
        ]);
        $bytes=file_get_contents($data['file']->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)>=8&&
            strlen($bytes)<=10*1024*1024,422,'Invalid receipt.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime,['image/png','image/jpeg','application/pdf'],true),422);
        $valid=$mime==='application/pdf'
            ?str_starts_with($bytes,'%PDF-')&&str_contains(substr($bytes,-4096),'%%EOF')
            :is_array(@getimagesizefromstring($bytes));
        abort_unless($valid,422,'Invalid receipt content.');
        $hash=hash('sha256',$bytes);
        $path='deposits/'.$company->id.'/'.$id.'/'.Str::uuid().'_'.$hash.
            ($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'));
        abort_unless(Storage::disk('local')->put($path,$bytes),503);
        try {
            $rid=DB::transaction(function()use($company,$request,$id,$data,$bytes,$path,$mime,$hash):int {
                $row=DB::table('job_deposits')->where('company_id',$company->id)
                    ->where('id',$id)->lockForUpdate()->firstOrFail();
                abort_unless($row->status==='pending',409,'Receipts are locked after confirmation.');
                abort_if(DB::table('job_deposit_receipts')->where('job_deposit_id',$id)
                    ->whereNull('removed_at')->count()>=10,422,'Maximum 10 receipts.');
                $rid=DB::table('job_deposit_receipts')->insertGetId([
                    'company_id'=>$company->id,'job_deposit_id'=>$id,
                    'uploaded_by'=>$request->user()?->id,
                    'original_name'=>mb_substr(basename(
                        $data['file']->getClientOriginalName()),0,255),
                    'private_path'=>$path,'sha256'=>$hash,'mime_type'=>$mime,
                    'byte_size'=>strlen($bytes),'created_at'=>now(),
                ]);
                $this->event($company->id,$id,$request->user()?->id,
                    'receipt_added',['receipt_id'=>$rid]);
                return $rid;
            });
        } catch(\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return ['id'=>$rid,'receipts'=>$this->receipts($company->id,$id)];
    }

    public function removeReceipt(Request $request,Company $company,int $id,int $receipt): array
    {
        DB::transaction(function()use($request,$company,$id,$receipt):void {
            $row=DB::table('job_deposits')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='pending',409);
            $proof=DB::table('job_deposit_receipts')
                ->where('company_id',$company->id)->where('job_deposit_id',$id)
                ->where('id',$receipt)->whereNull('removed_at')->firstOrFail();
            DB::table('job_deposit_receipts')->where('id',$receipt)
                ->update(['removed_at'=>now()]);
            $this->event($company->id,$id,$request->user()?->id,
                'receipt_removed',['receipt_id'=>$receipt,'sha256'=>$proof->sha256]);
        });
        return ['receipts'=>$this->receipts($company->id,$id)];
    }

    public function receiptFile(int $company,int $id,int $receipt): array
    {
        $this->find($company,$id);
        $proof=DB::table('job_deposit_receipts')
            ->where('company_id',$company)->where('job_deposit_id',$id)
            ->where('id',$receipt)->whereNull('removed_at')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($proof->private_path),404);
        $bytes=Storage::disk('local')->get($proof->private_path);
        abort_unless(is_string($bytes)&&strlen($bytes)===(int)$proof->byte_size&&
            hash_equals((string)$proof->sha256,hash('sha256',$bytes)),409,
            'Receipt fingerprint mismatch.');
        return ['bytes'=>$bytes,'mime'=>$proof->mime_type];
    }
}
