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

final class InvoiceService
{
    public static function money(mixed $raw, bool $zero=false): int
    {
        if(!is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$raw))
            throw ValidationException::withMessages(['amount'=>'Provide CHF with at most two decimals.']);
        [$whole,$fraction]=array_pad(explode('.',$raw,2),2,'');
        $n=((int)$whole)*100+(int)str_pad($fraction,2,'0');
        if($n>99999999999||($zero?$n<0:$n<1))
            throw ValidationException::withMessages(['amount'=>'Invoice amount out of range.']);
        return $n;
    }

    private function event(int $company,int $invoice,?int $actor,string $type,array $meta=[]): void
    {
        DB::table('service_invoice_events')->insert([
            'company_id'=>$company,'service_invoice_id'=>$invoice,
            'actor_user_id'=>$actor,'event_type'=>$type,
            'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR),'created_at'=>now(),
        ]);
    }

    public function find(int $company,int $id): object
    {
        return DB::table('service_invoices as i')
            ->join('service_jobs as j','j.id','=','i.service_job_id')
            ->whereColumn('i.company_id','j.company_id')
            ->where('i.company_id',$company)->where('i.id',$id)
            ->select('i.*','j.title as job_title','j.reference_number as job_reference')
            ->firstOrFail();
    }

    private function sums(int $company,int $invoice): array
    {
        // Re-read M16 deposit status. Refunded money is never a customer credit.
        $deposits=(int)DB::table('service_invoice_deposit_allocations as a')
            ->join('job_deposits as d','d.id','=','a.job_deposit_id')
            ->whereColumn('a.company_id','d.company_id')
            ->where('a.company_id',$company)
            ->where('a.service_invoice_id',$invoice)
            ->where('d.status','received')
            ->sum('a.amount_minor');
        $paid=(int)DB::table('service_invoice_payments')
            ->where('company_id',$company)->where('service_invoice_id',$invoice)
            ->where('status','recorded')->sum('amount_minor');
        return [$deposits,$paid];
    }

    public function payload(object $row): array
    {
        [$deposits,$paid]=$this->sums((int)$row->company_id,(int)$row->id);
        $total=(int)$row->total_minor;
        $balance=max(0,$total-$deposits-$paid);
        $status=$row->status;
        if(in_array($status,['approved','sent'],true)){
            if($balance===0)$status='paid';
            elseif($paid+$deposits>0)$status='partially_paid';
            elseif($row->due_date<now()->format('Y-m-d'))$status='overdue';
            elseif($status==='approved')$status='pending';
        }
        return [
            'id'=>(int)$row->id,'company_id'=>(int)$row->company_id,
            'job_id'=>(int)$row->service_job_id,'client_id'=>(int)$row->client_id,
            'quote_id'=>$row->service_quote_id===null?null:(int)$row->service_quote_id,
            'number'=>$row->invoice_number,'type'=>$row->invoice_type,
            'status'=>$status,'lifecycle_status'=>$row->status,
            'job'=>$row->job_title,'job_reference'=>$row->job_reference,
            'client_name'=>$row->client_name_snapshot,
            'client_email'=>$row->client_email_snapshot??'',
            'client_phone'=>$row->client_phone_snapshot??'',
            'billing_address'=>$row->billing_address??'',
            'invoice_date'=>$row->invoice_date,'due_date'=>$row->due_date,
            'currency'=>'CHF','iban'=>$row->iban??'',
            'notes'=>$row->notes??'','terms'=>$row->terms??'',
            'vat_basis_points'=>(int)$row->vat_basis_points,
            'subtotal_minor'=>(int)$row->subtotal_minor,
            'vat_minor'=>(int)$row->vat_minor,'total_minor'=>$total,
            'deposit_paid_minor'=>$deposits,'received_minor'=>$paid,
            'amount_due_minor'=>$balance,
            'lock_version'=>(int)$row->lock_version,
            'revision'=>(int)$row->revision,
            'approved_at'=>$row->approved_at,'sent_at'=>$row->sent_at,
            'voided_at'=>$row->voided_at,'created_at'=>$row->created_at,
            'updated_at'=>$row->updated_at,
            'invoice_application_status'=>'invoice_ledger',
        ];
    }

    public function listing(?int $company,array $filters=[]): array
    {
        $q=DB::table('service_invoices as i')
            ->join('service_jobs as j','j.id','=','i.service_job_id')
            ->whereColumn('i.company_id','j.company_id')
            ->select('i.*','j.title as job_title','j.reference_number as job_reference');
        if($company!==null)$q->where('i.company_id',$company);
        elseif(isset($filters['company_id']))$q->where('i.company_id',(int)$filters['company_id']);
        if(isset($filters['job_id']))$q->where('i.service_job_id',$filters['job_id']);
        if(isset($filters['type']))$q->where('i.invoice_type',$filters['type']);
        if(!empty($filters['q'])){
            $search='%'.addcslashes($filters['q'],'%_').'%';
            $q->where(fn($b)=>$b->where('i.invoice_number','like',$search)
                ->orWhere('i.client_name_snapshot','like',$search)
                ->orWhere('j.reference_number','like',$search)
                ->orWhere('j.title','like',$search));
        }
        $limit=min(100,max(1,(int)($filters['per_page']??30)));
        $page=max(1,(int)($filters['page']??1));
        // Status is computed from the ledger. Do not guess from cached flags.
        if(!empty($filters['status'])){
            $filtered=$q->orderByDesc('i.id')->get()->map(
                fn($r)=>$this->payload($r)
            )->filter(fn($x)=>$x['status']===$filters['status'])->values();
            $total=$filtered->count();
            $records=$filtered->slice(($page-1)*$limit,$limit)->values()->all();
        }else{
            $total=(clone $q)->count();
            $records=$q->orderByDesc('i.invoice_date')->orderByDesc('i.id')
                ->offset(($page-1)*$limit)->limit($limit)->get()
                ->map(fn($r)=>$this->payload($r))->all();
        }
        return ['records'=>$records,'total'=>$total,'page'=>$page,
            'last_page'=>max(1,(int)ceil($total/$limit))];
    }

    public function sources(int $company,int $jobId): array
    {
        ServiceJob::query()->where('company_id',$company)->findOrFail($jobId);
        $quotes=DB::table('service_quotes')->where('company_id',$company)
            ->where('service_job_id',$jobId)->whereNotNull('approved_at')
            ->whereIn('status',['approved','sent'])
            ->orderByDesc('id')->limit(20)->get();
        $availableQuotes=[];
        foreach($quotes as $q){
            $items=DB::table('service_quote_items')
                ->where('service_quote_id',$q->id)->orderBy('position')->get();
            $lines=[];
            foreach($items as $item){
                // Missing prices remain missing, not guessed as CHF 0.
                $lines[]=['description'=>trim($item->title.
                    ($item->description?"\n".$item->description:'')).
                    ($item->dimensions?"\nDimensions: ".$item->dimensions:''),
                    'quantity'=>$item->quantity,
                    'unit_price_minor'=>$item->unit_price_minor,
                    'priced'=>$item->unit_price_minor!==null &&
                        $item->quantity!==null];
            }
            $availableQuotes[]=[
                'kind'=>'approved_quote','id'=>(int)$q->id,
                'number'=>$q->custom_quote_number?:$q->reference_number,
                'vat_basis_points'=>(int)$q->vat_basis_points,
                'total_minor'=>$q->total_minor===null?null:(int)$q->total_minor,
                'items'=>$lines,
            ];
        }
        $addenda=DB::table('addenda')->where('company_id',$company)
            ->where('service_job_id',$jobId)->whereNotNull('signed_at')
            ->whereNotNull('signed_pdf_sha256')
            ->orderByDesc('id')->limit(20)->get();
        $availableAddenda=[];
        foreach($addenda as $a){
            $invoiced=DB::table('service_invoice_addenda')
                ->where('addendum_id',$a->id)->exists();
            $availableAddenda[]=[
                'kind'=>'signed_addendum','id'=>(int)$a->id,
                'number'=>$a->number,'already_invoiced'=>$invoiced,
                'vat_basis_points'=>(int)$a->vat_basis_points,
                'total_minor'=>(int)$a->total_minor,
                'items'=>[[
                    'description'=>trim($a->title."\n".$a->change_description.
                        ($a->additional_work?"\n".$a->additional_work:'')),
                    'quantity'=>$a->quantity,'unit_price_minor'=>$a->unit_price_minor,
                    'priced'=>$a->quantity!==null&&$a->unit_price_minor!==null,
                ]],
            ];
        }
        $accepted=DB::table('work_acceptances')
            ->where('company_id',$company)->where('service_job_id',$jobId)
            ->whereNotNull('signed_at')->orderByDesc('id')->limit(20)
            ->get(['id','number','completed_work','completion_date','acceptance_result']);
        return ['job_id'=>$jobId,'currency'=>'CHF',
            'quotes'=>$availableQuotes,'addenda'=>$availableAddenda,
            // Accepted work describes scope only; never assume labour prices.
            'completed_work'=>$accepted->map(fn($w)=>[
                'id'=>(int)$w->id,'number'=>$w->number,
                'description'=>$w->completed_work,'date'=>$w->completion_date,
                'pricing_available'=>false,
            ])->all(),
        ];
    }

    public function availableDeposits(int $company,int $job): array
    {
        ServiceJob::query()->where('company_id',$company)->findOrFail($job);
        $rows=DB::table('job_deposits as d')
            ->leftJoin('service_invoice_deposit_allocations as a',
                'a.job_deposit_id','=','d.id')
            ->where('d.company_id',$company)->where('d.service_job_id',$job)
            ->where('d.status','received')->whereNull('a.id')
            ->orderBy('d.id')->get(['d.id','d.amount_minor']);
        return ['job_id'=>$job,'currency'=>'CHF',
            'available_minor'=>(int)$rows->sum('amount_minor'),
            'deposit_ids'=>$rows->pluck('id')->map(fn($n)=>(int)$n)->all(),
            'allocation_policy'=>'whole_deposits_once'];
    }

    public function items(int $company,int $invoice): array
    {
        $this->find($company,$invoice);
        return DB::table('service_invoice_items')
            ->where('service_invoice_id',$invoice)->orderBy('position')
            ->get()->map(fn($r)=>[
                'description'=>$r->description,'quantity'=>(int)$r->quantity,
                'unit_price_minor'=>(int)$r->unit_price_minor,
                'total_minor'=>(int)$r->total_minor,
            ])->all();
    }

    public function payments(int $company,int $invoice): array
    {
        $this->find($company,$invoice);
        return DB::table('service_invoice_payments')
            ->where('company_id',$company)->where('service_invoice_id',$invoice)
            ->orderBy('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'amount_minor'=>(int)$r->amount_minor,
                'paid_at'=>$r->payment_date,'method'=>$r->method,
                'reference_number'=>$r->reference_number,
                'status'=>$r->status==='recorded'?'paid':'void',
                'recorded_by'=>$r->recorded_by,'voided_at'=>$r->voided_at,
            ])->all();
    }

    public function history(int $company,int $invoice): array
    {
        $this->find($company,$invoice);
        return DB::table('service_invoice_events')
            ->where('company_id',$company)->where('service_invoice_id',$invoice)
            ->orderBy('id')->get()->map(fn($e)=>[
                'id'=>(int)$e->id,'type'=>$e->event_type,
                'actor_user_id'=>$e->actor_user_id,'created_at'=>$e->created_at,
                'metadata'=>json_decode($e->metadata??'{}',true)?:[],
            ])->all();
    }

    public function detail(int $company,int $invoice): array
    {
        $r=$this->find($company,$invoice);
        return ['invoice'=>$this->payload($r),'items'=>$this->items($company,$invoice),
            'payments'=>$this->payments($company,$invoice),
            'history'=>$this->history($company,$invoice),
            'attachments'=>$this->attachments($company,$invoice)];
    }

    private function manager(Request $request,Company $company): void
    {
        $actor=$request->user();
        if($actor?->is_platform_admin)return;
        $member=$actor===null?null:$company->users()
            ->where('users.id',$actor->id)->wherePivot('status','active')->first();
        abort_unless(in_array($member?->pivot?->role,['owner','admin'],true),403,
            'Only an owner or company administrator may confirm invoice finances.');
    }

    public function save(Request $request,Company $company,?int $id=null): array
    {
        $fields=$request->validate([
            'job_id'=>['required','integer','min:1'],
            'quote_id'=>['nullable','integer','min:1'],
            'addenda_ids'=>['sometimes','array','max:20'],
            'addenda_ids.*'=>['integer','min:1','distinct'],
            'invoice_number'=>['nullable','string','max:50','regex:/^[A-Za-z0-9._\/-]+$/D'],
            'invoice_type'=>['required',Rule::in(['deposit','final'])],
            'invoice_date'=>['required','date_format:Y-m-d'],
            'due_date'=>['required','date_format:Y-m-d','after_or_equal:invoice_date'],
            'vat_basis_points'=>['required','integer','between:0,10000'],
            'iban'=>['nullable','string','max:64'],
            'notes'=>['nullable','string','max:12000'],
            'terms'=>['nullable','string','max:12000'],
            'items'=>['required','array','min:1','max:100'],
            'items.*.description'=>['required','string','max:1000'],
            'items.*.quantity'=>['required','integer','between:1,100000'],
            'items.*.unit_price_minor'=>['required','integer','between:0,99999999999'],
            'allocate_deposits'=>['sometimes','boolean'],
            'request_key'=>['nullable','string','between:8,100','regex:/^[A-Za-z0-9._-]+$/D'],
            'lock_version'=>[$id===null?'nullable':'required','integer','min:1'],
        ]);
        $subtotal=0;$items=[];
        foreach($fields['items'] as $i=>$item){
            $price=(int)$item['unit_price_minor'];
            $quantity=(int)$item['quantity'];
            $line=$price*$quantity;
            abort_if($line>99999999999,422,'Invoice line exceeds CHF maximum.');
            $subtotal+=$line;
            abort_if($subtotal>99999999999,422,'Invoice subtotal exceeds CHF maximum.');
            $items[]=['position'=>$i+1,'description'=>trim($item['description']),
                'quantity'=>$quantity,'unit_price_minor'=>$price,'total_minor'=>$line];
        }
        $vat=intdiv($subtotal*(int)$fields['vat_basis_points']+5000,10000);
        $total=$subtotal+$vat;
        abort_unless($total>0&&$total<=99999999999,422,
            'Invoice total must be a positive CHF amount.');
        $alloc=$fields['invoice_type']==='final'&&
            (bool)($fields['allocate_deposits']??false);
        $key=$id===null?($fields['request_key']??null):null;
        $fp=$key===null?null:hash('sha256',json_encode([
            $fields['job_id'],$fields['quote_id']??null,
            $fields['invoice_type'],$fields['invoice_number']??null,
            $fields['invoice_date'],$fields['due_date'],$fields['vat_basis_points'],
            trim((string)($fields['iban']??'')),trim((string)($fields['notes']??'')),
            trim((string)($fields['terms']??'')),array_values($fields['addenda_ids']??[]),$items,$alloc,
        ],JSON_THROW_ON_ERROR));
        $actor=$request->user()?->id;
        $replayed=false;
        $saved=DB::transaction(function()use($company,$fields,$items,$subtotal,$vat,
            $total,$id,$actor,$key,$fp,$alloc,&$replayed):object{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            if($id===null&&$key!==null){
                $prior=DB::table('service_invoices')->where('company_id',$company->id)
                    ->where('request_key',$key)->first();
                if($prior!==null){
                    abort_unless($prior->request_fingerprint!==null
                        &&hash_equals((string)$prior->request_fingerprint,(string)$fp),409,
                        'Invoice request key already used for different details.');
                    $replayed=true;
                    return $this->find($company->id,(int)$prior->id);
                }
            }
            $job=ServiceJob::query()->where('company_id',$company->id)
                ->findOrFail((int)$fields['job_id']);
            $client=DB::table('clients')->where('company_id',$company->id)
                ->where('id',$job->client_id)->firstOrFail();
            $quoteId=isset($fields['quote_id'])?(int)$fields['quote_id']:null;
            if($quoteId!==null){
                $quote=ServiceQuote::query()->where('company_id',$company->id)
                    ->where('service_job_id',$job->id)->where('client_id',$job->client_id)
                    ->findOrFail($quoteId);
                abort_unless($quote->approved_at!==null &&
                    in_array($quote->status,['approved','sent'],true),422,
                    'Only approved job quotes can be invoice sources.');
            }
            $linkedAddenda=array_map('intval',$fields['addenda_ids']??[]);
            if($linkedAddenda!==[]){
                abort_unless($fields['invoice_type']==='final',422,
                    'Only final invoices may include signed addenda.');
                $verified=DB::table('addenda')->where('company_id',$company->id)
                    ->where('service_job_id',$job->id)
                    ->whereIn('id',$linkedAddenda)
                    ->whereNotNull('signed_at')
                    ->whereNotNull('signed_pdf_sha256')->pluck('id')
                    ->map(fn($n)=>(int)$n)->all();
                abort_unless(count($verified)===count($linkedAddenda),422,
                    'All addenda must be signed originals for the same job.');
                $used=DB::table('service_invoice_addenda')
                    ->whereIn('addendum_id',$linkedAddenda);
                if($id!==null)$used->where('service_invoice_id','!=',$id);
                abort_if($used->exists(),409,'A signed addendum was already invoiced.');
            }
            $value=[
                'invoice_date'=>$fields['invoice_date'],
                'due_date'=>$fields['due_date'],
                'invoice_type'=>$fields['invoice_type'],
                'service_quote_id'=>$quoteId,
                'vat_basis_points'=>(int)$fields['vat_basis_points'],
                'subtotal_minor'=>$subtotal,'vat_minor'=>$vat,'total_minor'=>$total,
                'iban'=>trim((string)($fields['iban']??''))?:null,
                'notes'=>trim((string)($fields['notes']??''))?:null,
                'terms'=>trim((string)($fields['terms']??''))?:null,
                'updated_at'=>now(),'updated_by'=>$actor,
            ];
            if($id===null){
                $invoiceNumber=trim((string)($fields['invoice_number']??''));
                if($invoiceNumber===''){
                    $year=substr($fields['invoice_date'],0,4);
                    $next=(int)DB::table('service_invoices')
                        ->where('company_id',$company->id)->count()+1;
                    do{
                        $invoiceNumber='INV-'.$company->id.'-'.$year.'-'.
                            str_pad((string)$next++,6,'0',STR_PAD_LEFT);
                    }while(DB::table('service_invoices')->where('company_id',$company->id)
                        ->where('invoice_number',$invoiceNumber)->exists());
                }
                abort_if(DB::table('service_invoices')->where('company_id',$company->id)
                    ->where('invoice_number',$invoiceNumber)->exists(),409,
                    'Invoice number already exists in this company.');
                $created=DB::table('service_invoices')->insertGetId(array_merge($value,[
                    'company_id'=>$company->id,'service_job_id'=>$job->id,
                    'client_id'=>$client->id,'invoice_number'=>$invoiceNumber,
                    'client_name_snapshot'=>$client->full_name,
                    'client_email_snapshot'=>$client->email,
                    'client_phone_snapshot'=>$client->phone_number??null,
                    'billing_address'=>$client->address??null,
                    'currency'=>'CHF','status'=>'draft','lock_version'=>1,
                    'revision'=>1,'request_key'=>$key,'request_fingerprint'=>$fp,
                    'created_at'=>now(),'created_by'=>$actor,
                ]));
            }else{
                $old=DB::table('service_invoices')
                    ->where('company_id',$company->id)->where('id',$id)
                    ->lockForUpdate()->firstOrFail();
                abort_unless($old->status==='draft',409,
                    'Approved, sent and voided invoices cannot be edited.');
                abort_unless((int)$fields['lock_version']===(int)$old->lock_version,409,
                    'Invoice was changed. Reload before editing.');
                abort_unless((int)$old->service_job_id===$job->id &&
                    ($old->service_quote_id===null?null:(int)$old->service_quote_id)===$quoteId,
                    409,'Invoice source cannot be reassigned.');
                $invoiceNumber=trim((string)($fields['invoice_number']??$old->invoice_number));
                abort_unless($invoiceNumber===$old->invoice_number,409,
                    'Invoice number is stable after creation.');
                $created=$id;
                DB::table('service_invoices')->where('id',$created)->update(
                    array_merge($value,['revision'=>$old->revision+1,
                        'lock_version'=>$old->lock_version+1])
                );
                DB::table('service_invoice_items')->where('service_invoice_id',$created)->delete();
                DB::table('service_invoice_deposit_allocations')
                    ->where('service_invoice_id',$created)->delete();
            }
            foreach($items as $line){
                DB::table('service_invoice_items')->insert(array_merge($line,[
                    'service_invoice_id'=>$created,
                ]));
            }
            DB::table('service_invoice_addenda')
                ->where('service_invoice_id',$created)->delete();
            foreach($linkedAddenda as $addendum){
                DB::table('service_invoice_addenda')->insert([
                    'company_id'=>$company->id,'service_invoice_id'=>$created,
                    'addendum_id'=>$addendum,'created_at'=>now(),
                ]);
            }
            if($alloc){
                $remaining=$total;
                $available=DB::table('job_deposits as d')
                    ->leftJoin('service_invoice_deposit_allocations as a',
                        'a.job_deposit_id','=','d.id')
                    ->where('d.company_id',$company->id)
                    ->where('d.service_job_id',$job->id)
                    ->where('d.status','received')->whereNull('a.id')
                    ->orderBy('d.id')->get(['d.id','d.amount_minor']);
                foreach($available as $deposit){
                    // All-or-nothing application avoids splitting one deposit
                    // across multiple invoices without an allocation history.
                    if((int)$deposit->amount_minor>$remaining)continue;
                    DB::table('service_invoice_deposit_allocations')->insert([
                        'company_id'=>$company->id,'service_invoice_id'=>$created,
                        'job_deposit_id'=>$deposit->id,
                        'amount_minor'=>(int)$deposit->amount_minor,
                        'created_at'=>now(),
                    ]);
                    $remaining-=(int)$deposit->amount_minor;
                    if($remaining===0)break;
                }
            }
            $this->event($company->id,$created,$actor,$id===null?'draft_created':'draft_updated',[
                'total_minor'=>$total,'source_quote_id'=>$quoteId,
                'allocations_enabled'=>$alloc,'source_addenda_ids'=>$linkedAddenda,
            ]);
            return $this->find($company->id,$created);
        });
        if(!$replayed)AuditLogger::record($id===null?'invoices.created':'invoices.updated',
            $request->user(),null,$company,['invoice_id'=>$saved->id],$request);
        return $this->payload($saved);
    }

    public function approve(Request $request,Company $company,int $id): array
    {
        $this->manager($request,$company);
        $data=$request->validate(['confirm'=>['required','accepted'],
            'lock_version'=>['required','integer','min:1']]);
        $saved=DB::transaction(function()use($request,$company,$id,$data){
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $row=DB::table('service_invoices')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='draft' &&
                (int)$row->lock_version===(int)$data['lock_version'],409,
                'Invoice is no longer an editable draft.');
            abort_unless((int)$row->total_minor>0,422);
            DB::table('service_invoices')->where('id',$id)->update([
                'status'=>'approved','approved_at'=>now(),
                'approved_by'=>$request->user()?->id,
                'lock_version'=>$row->lock_version+1,'updated_at'=>now(),
            ]);
            $this->event($company->id,$id,$request->user()?->id,
                'approved',['total_minor'=>(int)$row->total_minor]);
            return $this->find($company->id,$id);
        });
        AuditLogger::record('invoices.approved',$request->user(),null,$company,
            ['invoice_id'=>$id],$request);
        return $this->payload($saved);
    }

    public function payment(Request $request,Company $company,int $id): array
    {
        $this->manager($request,$company);
        $data=$request->validate([
            'amount'=>['required','string','max:30'],
            'method'=>['required',Rule::in(['cash','bank_transfer','other'])],
            'payment_date'=>['required','date_format:Y-m-d'],
            'reference_number'=>['nullable','string','max:120'],
            'confirm'=>['required','accepted'],
            'request_key'=>['nullable','string','between:8,100','regex:/^[A-Za-z0-9._-]+$/D'],
        ]);
        $amount=self::money($data['amount']);
        $key=$data['request_key']??null;
        $fp=$key===null?null:hash('sha256',json_encode([
            $id,$amount,$data['method'],$data['payment_date'],
            trim((string)($data['reference_number']??'')),
        ],JSON_THROW_ON_ERROR));
        $actor=$request->user()?->id;$replayed=false;
        $saved=DB::transaction(function()use($company,$id,$data,$amount,$key,$fp,$actor,
            &$replayed){
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $row=DB::table('service_invoices')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            if($key!==null){
                $prior=DB::table('service_invoice_payments')->where('company_id',$company->id)
                    ->where('request_key',$key)->first();
                if($prior!==null){
                    abort_unless((int)$prior->service_invoice_id===$id &&
                        $prior->request_fingerprint!==null &&
                        hash_equals((string)$prior->request_fingerprint,(string)$fp),
                        409,'Payment request key belongs to different details.');
                    $replayed=true;
                    return (int)$prior->id;
                }
            }
            abort_unless(in_array($row->status,['approved','sent'],true),409,
                'Only approved invoices can record manual payments.');
            $balance=$this->payload($this->find($company->id,$id))['amount_due_minor'];
            abort_unless($amount<=$balance,409,
                'Payment exceeds outstanding invoice balance.');
            $payment=DB::table('service_invoice_payments')->insertGetId([
                'company_id'=>$company->id,'service_invoice_id'=>$id,
                'recorded_by'=>$actor,'amount_minor'=>$amount,
                'method'=>$data['method'],'payment_date'=>$data['payment_date'],
                'reference_number'=>trim((string)($data['reference_number']??''))?:null,
                'status'=>'recorded','request_key'=>$key,'request_fingerprint'=>$fp,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($company->id,$id,$actor,'payment_recorded',[
                'payment_id'=>$payment,'amount_minor'=>$amount,
                'manual_only'=>true,'no_bank_transaction'=>true,
            ]);
            return $payment;
        });
        if(!$replayed)AuditLogger::record('invoices.payment_recorded',$request->user(),
            null,$company,['invoice_id'=>$id,'payment_id'=>$saved],$request);
        return ['payment_id'=>$saved,'replayed'=>$replayed,
            'invoice'=>$this->payload($this->find($company->id,$id))];
    }

    public function voidPayment(Request $request,Company $company,int $invoice,int $payment): array
    {
        $this->manager($request,$company);
        $data=$request->validate(['confirm'=>['required','accepted'],
            'reason'=>['required','string','min:8','max:2000']]);
        DB::transaction(function()use($request,$company,$invoice,$payment,$data):void{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $this->find($company->id,$invoice);
            $row=DB::table('service_invoice_payments')
                ->where('company_id',$company->id)->where('service_invoice_id',$invoice)
                ->where('id',$payment)->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='recorded',409,
                'Payment has already been voided.');
            DB::table('service_invoice_payments')->where('id',$payment)->update([
                'status'=>'void','voided_at'=>now(),'voided_by'=>$request->user()?->id,
                'void_reason'=>trim($data['reason']),'updated_at'=>now(),
            ]);
            $this->event($company->id,$invoice,$request->user()?->id,
                'payment_voided',['payment_id'=>$payment,'reason'=>trim($data['reason'])]);
        });
        return $this->payload($this->find($company->id,$invoice));
    }

    public function voidInvoice(Request $request,Company $company,int $invoice): array
    {
        $this->manager($request,$company);
        $data=$request->validate([
            'confirm'=>['required','accepted'],
            'reason'=>['required','string','min:8','max:2000'],
            'lock_version'=>['required','integer','min:1'],
        ]);
        return DB::transaction(function()use($company,$request,$invoice,$data){
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $row=DB::table('service_invoices')->where('company_id',$company->id)
                ->where('id',$invoice)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($row->status,['draft','approved'],true) &&
                (int)$row->lock_version===(int)$data['lock_version'],409,
                'Only an unchanged unsent invoice may be voided.');
            abort_if(DB::table('service_invoice_payments')
                ->where('company_id',$company->id)->where('service_invoice_id',$invoice)
                ->where('status','recorded')->exists(),409,
                'Recorded payments must be reconciled before voiding.');
            DB::table('service_invoice_deposit_allocations')
                ->where('service_invoice_id',$invoice)->delete();
            DB::table('service_invoice_addenda')
                ->where('service_invoice_id',$invoice)->delete();
            DB::table('service_invoices')->where('id',$invoice)->update([
                'status'=>'void','voided_at'=>now(),
                'void_reason'=>trim($data['reason']),
                'lock_version'=>$row->lock_version+1,'updated_at'=>now(),
            ]);
            $this->event($company->id,$invoice,$request->user()?->id,
                'voided',['reason'=>trim($data['reason'])]);
            return $this->payload($this->find($company->id,$invoice));
        });
    }

    public function attachments(int $company,int $invoice): array
    {
        $this->find($company,$invoice);
        return DB::table('service_invoice_attachments')
            ->where('company_id',$company)->where('service_invoice_id',$invoice)
            ->whereNull('removed_at')->orderBy('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'name'=>$r->original_name,
                'sha256'=>$r->sha256,'mime_type'=>$r->mime_type,
                'byte_size'=>(int)$r->byte_size,'created_at'=>$r->created_at,
            ])->all();
    }

    public function upload(Request $request,Company $company,int $invoice): array
    {
        $data=$request->validate([
            'file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:10240'],
        ]);
        $bytes=file_get_contents($data['file']->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)>=8
            &&strlen($bytes)<=10485760,422,'Invalid invoice attachment.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime,['image/png','image/jpeg','application/pdf'],true),
            422,'Unsupported invoice attachment.');
        $valid=$mime==='application/pdf'?
            str_starts_with($bytes,'%PDF-')&&str_contains(substr($bytes,-4096),'%%EOF'):
            is_array(@getimagesizefromstring($bytes));
        abort_unless($valid,422,'Invalid attachment contents.');
        $hash=hash('sha256',$bytes);
        $path='invoices/'.$company->id.'/'.$invoice.'/'.Str::uuid().'_'.
            $hash.($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'));
        abort_unless(Storage::disk('local')->put($path,$bytes),503);
        try{
            $created=false;
            $id=DB::transaction(function()use($request,$company,$invoice,$data,$hash,
                $bytes,$mime,$path,&$created){
                DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
                $row=DB::table('service_invoices')->where('company_id',$company->id)
                    ->where('id',$invoice)->lockForUpdate()->firstOrFail();
                abort_unless($row->status==='draft',409,
                    'Attachments are frozen after invoice approval.');
                $prior=DB::table('service_invoice_attachments')
                    ->where('company_id',$company->id)
                    ->where('service_invoice_id',$invoice)
                    ->where('sha256',$hash)->whereNull('removed_at')->first();
                if($prior!==null)return (int)$prior->id;
                abort_if(DB::table('service_invoice_attachments')
                    ->where('service_invoice_id',$invoice)->whereNull('removed_at')
                    ->count()>=10,422,'Maximum 10 attachments.');
                $id=DB::table('service_invoice_attachments')->insertGetId([
                    'company_id'=>$company->id,'service_invoice_id'=>$invoice,
                    'uploaded_by'=>$request->user()?->id,
                    'original_name'=>mb_substr(basename(
                        $data['file']->getClientOriginalName()),0,255),
                    'private_path'=>$path,'sha256'=>$hash,
                    'mime_type'=>$mime,'byte_size'=>strlen($bytes),
                    'created_at'=>now(),
                ]);
                $this->event($company->id,$invoice,$request->user()?->id,
                    'attachment_added',['attachment_id'=>$id]);
                $created=true;
                return $id;
            });
            if(!$created)Storage::disk('local')->delete($path);
        }catch(\Throwable $e){
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return ['id'=>$id,'attachments'=>$this->attachments($company->id,$invoice)];
    }

    public function removeAttachment(Request $request,Company $company,int $invoice,int $attachment): array
    {
        DB::transaction(function()use($request,$company,$invoice,$attachment):void{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $row=DB::table('service_invoices')
                ->where('company_id',$company->id)->where('id',$invoice)
                ->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='draft',409);
            $old=DB::table('service_invoice_attachments')
                ->where('company_id',$company->id)
                ->where('service_invoice_id',$invoice)
                ->where('id',$attachment)->whereNull('removed_at')->firstOrFail();
            DB::table('service_invoice_attachments')->where('id',$attachment)
                ->update(['removed_at'=>now()]);
            $this->event($company->id,$invoice,$request->user()?->id,
                'attachment_removed',['attachment_id'=>$attachment,
                    'sha256'=>$old->sha256]);
        });
        return ['attachments'=>$this->attachments($company->id,$invoice)];
    }

    public function attachmentFile(int $company,int $invoice,int $attachment): array
    {
        $this->find($company,$invoice);
        $row=DB::table('service_invoice_attachments')
            ->where('company_id',$company)->where('service_invoice_id',$invoice)
            ->where('id',$attachment)->whereNull('removed_at')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($row->private_path),404);
        $bytes=Storage::disk('local')->get($row->private_path);
        abort_unless(is_string($bytes)&&strlen($bytes)===(int)$row->byte_size
            &&hash_equals((string)$row->sha256,hash('sha256',$bytes)),409,
            'Invoice attachment fingerprint mismatch.');
        return ['bytes'=>$bytes,'mime'=>$row->mime_type];
    }
}
