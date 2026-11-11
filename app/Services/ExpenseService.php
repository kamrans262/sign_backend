<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExpenseService
{
    private const CATEGORIES=['fuel','tools','parking','meals','other','material','labour'];
    private const FINAL=['approved','reimbursed'];

    /**
     * Expense approval is available to active company owners/admins and active
     * members explicitly granted expenses.write. Keep authorization server-side.
     */
    public function canApprove(Company $company, ?\App\Models\User $actor): bool
    {
        if ($actor === null || !$actor->is_active) return false;
        $member = $company->users()->where('users.id', $actor->id)
            ->wherePivot('status', 'active')->first();
        if (!$member) return false;
        if (in_array($member->pivot->role, ['owner', 'admin'], true)) return true;
        $permissions = json_decode((string) $member->pivot->permissions, true);
        return is_array($permissions) && in_array('expenses.write', $permissions, true);
    }

    public function find(int $companyId,int $id): object
    {
        $q=DB::table('job_expenses as e')
            ->join('service_jobs as j','j.id','=','e.service_job_id')
            ->join('clients as c','c.id','=','j.client_id')
            ->select('e.*','j.title as job_title','j.reference_number as job_reference',
                'j.budget_chf as job_budget_chf','c.full_name as client_name');
        return $q->where('e.company_id',$companyId)
            ->where('j.company_id',$companyId)->where('c.company_id',$companyId)
            ->where('e.id',$id)->firstOrFail();
    }

    public function payload(object $r): array
    {
        return [
            'id'=>(int)$r->id,'company_id'=>(int)$r->company_id,
            'job_id'=>(int)$r->service_job_id,
            'job'=>(string)($r->job_title??''),
            'job_reference'=>(string)($r->job_reference??''),
            'client_name'=>(string)($r->client_name??''),
            'category'=>$r->category,
            'custom_category'=>$r->custom_category,
            'supplier'=>$r->supplier,
            'description'=>$r->description,'expense_date'=>$r->expense_date,
            'amount_minor'=>(int)$r->amount_minor,'currency'=>'CHF',
            'tags'=>json_decode((string)($r->tags??'[]'),true)?:[],
            'status'=>$r->status,'lock_version'=>(int)$r->lock_version,
            'approved_at'=>$r->approved_at,'approved_by'=>$r->approved_by,
            'reimbursement_requested_at'=>$r->reimbursement_requested_at,
            'reimbursed_at'=>$r->reimbursed_at,
            'created_at'=>$r->created_at,'updated_at'=>$r->updated_at,
            'job_budget_chf'=>$r->job_budget_chf??null,
        ];
    }

    public function listing(?int $companyId,array $filters=[]): array
    {
        $q=DB::table('job_expenses as e')
            ->join('service_jobs as j','j.id','=','e.service_job_id')
            ->join('clients as c','c.id','=','j.client_id')
            ->select('e.*','j.title as job_title','j.reference_number as job_reference',
                'j.budget_chf as job_budget_chf','c.full_name as client_name');
        if($companyId!==null)$q->where('e.company_id',$companyId);
        if(!empty($filters['company_id'])&&$companyId===null)
            $q->where('e.company_id',(int)$filters['company_id']);
        // Always reject corrupt cross-tenant links, including for platform admin.
        $q->whereColumn('e.company_id','j.company_id')
            ->whereColumn('j.company_id','c.company_id');
        if(!empty($filters['job_id']))$q->where('e.service_job_id',(int)$filters['job_id']);
        if(!empty($filters['status']))$q->where('e.status',$filters['status']);
        if(!empty($filters['q'])) {
            $term='%'.str_replace(['%','_'],['\\%','\\_'],$filters['q']).'%';
            $q->where(fn($x)=>$x->where('e.description','like',$term)
                ->orWhere('e.supplier','like',$term)
                ->orWhere('j.title','like',$term)
                ->orWhere('j.reference_number','like',$term));
        }
        $total=(clone $q)->count();
        $limit=min(100,max(1,(int)($filters['per_page']??30)));
        $page=max(1,(int)($filters['page']??1));
        $rows=$q->orderByDesc('e.expense_date')->orderByDesc('e.id')
            ->offset(($page-1)*$limit)->limit($limit)->get();
        return ['records'=>$rows->map(fn($r)=>$this->payload($r))->all(),
            'total'=>$total,'page'=>$page,
            'last_page'=>max(1,(int)ceil($total/$limit))];
    }

    public function history(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('job_expense_events')->where('company_id',$companyId)
            ->where('job_expense_id',$id)->orderBy('id')->get()
            ->map(fn($r)=>[
                'id'=>(int)$r->id,'type'=>$r->event_type,
                'actor_id'=>$r->actor_user_id,'created_at'=>$r->created_at,
                'metadata'=>json_decode((string)($r->metadata??'{}'),true)?:[],
            ])->all();
    }

    private function event(object $r,string $type,?int $actor,array $meta=[]): void
    {
        DB::table('job_expense_events')->insert([
            'company_id'=>$r->company_id,'job_expense_id'=>$r->id,
            'actor_user_id'=>$actor,'event_type'=>$type,
            'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }

    public function receipts(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('job_expense_receipts')
            ->where('company_id',$companyId)->where('job_expense_id',$id)
            ->whereNull('removed_at')->orderBy('id')->get()
            ->map(fn($r)=>[
                'id'=>(int)$r->id,'name'=>$r->original_name,
                'mime_type'=>$r->mime_type,'byte_size'=>(int)$r->byte_size,
                'sha256'=>$r->sha256,'created_at'=>$r->created_at,
            ])->all();
    }

    public function receiptFile(int $companyId,int $id,int $receipt): array
    {
        $this->find($companyId,$id);
        $item=DB::table('job_expense_receipts')->where('company_id',$companyId)
            ->where('job_expense_id',$id)->where('id',$receipt)
            ->whereNull('removed_at')->firstOrFail();
        $disk=Storage::disk('local');
        abort_unless($disk->exists($item->private_path),404);
        $bytes=$disk->get($item->private_path);
        abort_unless(is_string($bytes)&&strlen($bytes)===(int)$item->byte_size
            &&hash_equals($item->sha256,hash('sha256',$bytes)),409,
            'Receipt failed integrity verification.');
        $mime=$item->mime_type;
        $valid=$mime==='application/pdf'
            ? (str_starts_with($bytes,'%PDF-')&&str_contains(substr($bytes,-4096),'%%EOF'))
            : (($mime==='image/png'&&str_starts_with($bytes,"\x89PNG\r\n\x1a\n"))
                ||($mime==='image/jpeg'&&str_starts_with($bytes,"\xFF\xD8\xFF")));
        abort_unless($valid,409,'Receipt content failed verification.');
        return ['bytes'=>$bytes,'mime'=>$mime,'name'=>$item->original_name];
    }

    /** Parse exact CHF centimes, never floating-point financial arithmetic. */
    public static function cents(string $amount): int
    {
        $value=trim($amount);
        if(!preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/D',$value))
            throw ValidationException::withMessages([
                'amount'=>'Enter an amount in CHF with up to two decimal places.',
            ]);
        [$units,$fraction]=array_pad(explode('.',$value,2),2,'');
        $cents=(int)$units*100+(int)str_pad($fraction,2,'0');
        if($cents<1||$cents>999999999999)
            throw ValidationException::withMessages(['amount'=>'Amount out of range.']);
        return $cents;
    }

    public function save(Request $request,Company $company,?int $id=null): array
    {
        $data=$request->validate([
            'job_id'=>['required','integer','min:1'],
            'category'=>['required',Rule::in(self::CATEGORIES)],
            'custom_category'=>['required_if:category,other','nullable','string','max:120'],
            'supplier'=>['nullable','string','max:255'],
            'description'=>['nullable','string','max:12000'],
            'expense_date'=>['required','date_format:Y-m-d'],
            'amount'=>['required','string','max:30'],
            'tags'=>['sometimes','array','max:12'],
            'tags.*'=>['string','max:60','distinct'],
            'lock_version'=>[$id!==null?'required':'nullable','integer','min:1'],
            'request_key'=>['sometimes','string','min:16','max:100',
                'regex:/^[A-Za-z0-9_.:-]+$/D'],
        ]);
        if ($data['category'] === 'other') {
            $data['custom_category'] = trim((string) ($data['custom_category'] ?? ''));
            if ($data['custom_category'] === '') {
                throw ValidationException::withMessages([
                    'custom_category' => 'Specify the expense type when Other is selected.',
                ]);
            }
        } else {
            $data['custom_category'] = null;
        }
        $cents=self::cents($data['amount']);
        $job=ServiceJob::query()->where('company_id',$company->id)
            ->findOrFail((int)$data['job_id']);
        abort_if($job->category==='archived',409,'Archived job cannot receive expenses.');
        $actor=$request->user()?->id;
        $key=$id===null?($data['request_key']??null):null;
        unset($data['request_key']);
        $fingerprintData=$data;
        ksort($fingerprintData);
        $fingerprint=hash('sha256',json_encode($fingerprintData,JSON_THROW_ON_ERROR));
        $replayed=false;
        $row=DB::transaction(function()use($company,$job,$data,$cents,$id,
            $actor,$key,$fingerprint,&$replayed):object {
            $values=['category'=>$data['category'],
                'custom_category'=>$data['custom_category'],
                'supplier'=>trim((string)($data['supplier']??''))?:null,
                'description'=>trim((string)($data['description']??''))?:null,
                'expense_date'=>$data['expense_date'],
                'amount_minor'=>$cents,
                'tags'=>json_encode($data['tags']??[],JSON_THROW_ON_ERROR),
                'updated_by'=>$actor,'updated_at'=>now()];
            if($id===null) {
                if($key!==null){
                    DB::table('companies')->where('id',$company->id)
                        ->lockForUpdate()->firstOrFail();
                    $prior=DB::table('job_expenses')
                        ->where('company_id',$company->id)
                        ->where('request_key',$key)->first();
                    if($prior!==null){
                        abort_unless(hash_equals((string)$prior->request_fingerprint,
                            $fingerprint),409,'Expense creation request key was already used.');
                        $replayed=true;
                        return $this->find($company->id,(int)$prior->id);
                    }
                }
                $new=DB::table('job_expenses')->insertGetId(array_merge($values,[
                    'company_id'=>$company->id,'service_job_id'=>$job->id,
                    'created_by'=>$actor,'created_at'=>now(),
                    'status'=>'to_review','lock_version'=>1,
                    'request_key'=>$key,
                    'request_fingerprint'=>$key===null?null:$fingerprint,
                ]));
                $row=$this->find($company->id,$new);
                $this->event($row,'created',$actor,[
                    'category'=>$row->category,
                    'custom_category'=>$row->custom_category,
                ]);
                return $row;
            }
            $current=DB::table('job_expenses')
                ->where('company_id',$company->id)->where('id',$id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($current->status==='to_review',409,
                'Only expenses awaiting review may be edited.');
            abort_unless((int)$current->lock_version===(int)$data['lock_version'],
                409,'This expense has changed; reload before editing.');
            abort_unless((int)$current->service_job_id===$job->id,
                409,'An expense cannot move to another job.');
            if(!array_key_exists('tags',$data))unset($values['tags']);
            $values['lock_version']=(int)$current->lock_version+1;
            DB::table('job_expenses')->where('id',$id)->update($values);
            $row=$this->find($company->id,$id);
            $this->event($row,'updated',$actor,[
                'version'=>$row->lock_version,
                'category'=>$row->category,
                'custom_category'=>$row->custom_category,
            ]);
            return $row;
        });
        if(!$replayed)AuditLogger::record(
            $id===null?'mobile.expense.created':'mobile.expense.updated',
            $request->user(),null,$company,['expense_id'=>$row->id],$request);
        return $this->payload($row);
    }

    public function upload(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:10240'],
        ]);
        $row=$this->find($company->id,$id);
        abort_unless($row->status==='to_review',409,'Reviewed expenses are immutable.');
        $file=$data['file'];$bytes=file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)>=8&&
            strlen($bytes)<=10*1024*1024,422,'Invalid receipt size.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime,['image/jpeg','image/png','application/pdf'],true),
            422,'Only genuine JPG, PNG or PDF receipts are allowed.');
        $valid=$mime==='application/pdf'
            ? (str_starts_with($bytes,'%PDF-')&&str_contains(substr($bytes,-4096),'%%EOF'))
            : is_array(@getimagesizefromstring($bytes));
        abort_unless($valid,422,'Invalid or incomplete receipt file.');
        $sha=hash('sha256',$bytes);
        $path='expenses/'.$company->id.'/'.$id.'/'.Str::uuid().'-'.$sha.
            ($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'));
        $disk=Storage::disk('local');
        abort_unless($disk->put($path,$bytes),503,'Unable to save receipt.');
        $replayed=false;
        try {
            $receiptId=DB::transaction(function()use($company,$id,$request,$file,$path,$mime,$bytes,$sha,&$replayed):int {
                $locked=DB::table('job_expenses')->where('company_id',$company->id)
                    ->where('id',$id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status==='to_review',409);
                // A timed-out upload retried with identical bytes is the same
                // receipt; preserve the existing artifact and audit event.
                $prior=DB::table('job_expense_receipts')
                    ->where('company_id',$company->id)
                    ->where('job_expense_id',$id)
                    ->where('sha256',$sha)->whereNull('removed_at')->first();
                if($prior!==null && (int)$prior->byte_size===strlen($bytes)
                    && $prior->mime_type===$mime){
                    $replayed=true;
                    return (int)$prior->id;
                }
                abort_if(DB::table('job_expense_receipts')->where('job_expense_id',$id)
                    ->whereNull('removed_at')->count()>=10,422);
                $key=DB::table('job_expense_receipts')->insertGetId([
                    'company_id'=>$company->id,'job_expense_id'=>$id,
                    'uploaded_by'=>$request->user()?->id,
                    'original_name'=>mb_substr(basename($file->getClientOriginalName()),0,255),
                    'private_path'=>$path,'mime_type'=>$mime,
                    'byte_size'=>strlen($bytes),'sha256'=>$sha,'created_at'=>now(),
                ]);
                $this->event($locked,'receipt_added',$request->user()?->id,
                    ['receipt_id'=>$key,'sha256'=>$sha]);
                return $key;
            });
        } catch(\Throwable $e) { $disk->delete($path);throw $e; }
        if($replayed)$disk->delete($path);
        return ['id'=>$receiptId,'sha256'=>$sha,'receipts'=>$this->receipts($company->id,$id)];
    }

    public function removeReceipt(Request $request,Company $company,int $id,int $receipt): array
    {
        DB::transaction(function()use($request,$company,$id,$receipt):void {
            $row=DB::table('job_expenses')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='to_review',409,'Reviewed expense cannot be edited.');
            $item=DB::table('job_expense_receipts')->where('company_id',$company->id)
                ->where('job_expense_id',$id)->where('id',$receipt)
                ->whereNull('removed_at')->firstOrFail();
            // Audit retention: bytes and hash remain stored but are inaccessible to clients.
            DB::table('job_expense_receipts')->where('id',$item->id)->update([
                'removed_at'=>now(),'removed_by'=>$request->user()?->id]);
            $this->event($row,'receipt_removed',$request->user()?->id,
                ['receipt_id'=>$receipt,'sha256'=>$item->sha256]);
        });
        return ['receipts'=>$this->receipts($company->id,$id)];
    }

    public function changeStatus(Request $request,Company $company,int $id,string $action): array
    {
        abort_unless(in_array($action,['approve','reimburse','request-reimbursement'],true),404);
        if ($action === 'approve') {
            abort_unless($this->canApprove($company, $request->user()), 403,
                'Expense approval requires company expense permissions.');
        }
        $row=DB::transaction(function()use($request,$company,$id,$action):object {
            $row=DB::table('job_expenses')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            $actor=$request->user()?->id;
            if($action==='approve') {
                abort_unless($row->status==='to_review',409);
                DB::table('job_expenses')->where('id',$id)->update([
                    'status'=>'approved','approved_by'=>$actor,
                    'approved_at'=>now(),'lock_version'=>$row->lock_version+1,
                    'updated_at'=>now()]);
                $this->event($row,'approved',$actor);
            } elseif($action==='request-reimbursement') {
                abort_unless($row->status==='approved' &&
                    $row->reimbursement_requested_at===null,409);
                DB::table('job_expenses')->where('id',$id)->update([
                    'reimbursement_requested_at'=>now(),'updated_at'=>now()]);
                $this->event($row,'reimbursement_requested',$actor);
            } else {
                abort_unless($row->status==='approved' &&
                    $row->reimbursement_requested_at!==null,409,
                    'Reimbursement must be requested before it is marked paid.');
                DB::table('job_expenses')->where('id',$id)->update([
                    'status'=>'reimbursed','reimbursed_at'=>now(),
                    'reimbursed_by'=>$actor,'updated_at'=>now()]);
                $this->event($row,'reimbursed',$actor);
            }
            return $this->find($company->id,$id);
        });
        AuditLogger::record('expense.'.$action,$request->user(),null,$company,
            ['expense_id'=>$id],$request);
        return $this->payload($row);
    }

    public function totals(int $companyId,int $jobId): array
    {
        $job=ServiceJob::query()->where('company_id',$companyId)->findOrFail($jobId);
        $total=(int)DB::table('job_expenses')->where('company_id',$companyId)
            ->where('service_job_id',$jobId)->sum('amount_minor');
        $budget=$job->budget_chf===null?null:self::budgetCents((string)$job->budget_chf);
        return ['job_id'=>$jobId,'currency'=>'CHF','total_expenses_minor'=>$total,
            'budget_minor'=>$budget,
            'remaining_budget_minor'=>$budget===null?null:$budget-$total];
    }

    private static function budgetCents(string $value): int
    {
        // DB decimal(14,2), no float conversion.
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'0');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }
}
