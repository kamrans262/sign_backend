<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EquipmentLoanService
{
    private const STATES=['available','in_use','maintenance'];

    public function companyDate(Company $company): string
    {
        // Until a formal company-timezone setting exists, CH companies use
        // the Swiss business date and other companies default to app timezone.
        return Carbon::now($company->country_iso_code==='CH'
            ? 'Europe/Zurich':config('app.timezone','UTC'))->toDateString();
    }

    private function event(string $table,string $foreign,int $companyId,
        int $entityId,?int $actor,string $kind,array $metadata=[]): void
    {
        DB::table($table)->insert([
            'company_id'=>$companyId,$foreign=>$entityId,
            'actor_user_id'=>$actor,'event_type'=>$kind,
            'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),
        ]);
    }

    private function assetEvent(object $r,?int $actor,string $type,array $meta=[]): void
    {
        $this->event('equipment_asset_events','equipment_asset_id',
            $r->company_id,$r->id,$actor,$type,$meta);
    }
    private function loanEvent(object $r,?int $actor,string $type,array $meta=[]): void
    {
        $this->event('equipment_loan_events','equipment_loan_id',
            $r->company_id,$r->id,$actor,$type,$meta);
    }

    public function asset(int $companyId,int $id): object
    {
        return DB::table('equipment_assets')->where('company_id',$companyId)
            ->where('id',$id)->whereNull('archived_at')->firstOrFail();
    }

    public function loan(int $companyId,int $id): object
    {
        return DB::table('equipment_loans')->where('company_id',$companyId)
            ->where('id',$id)->firstOrFail();
    }

    private function activeLoan(int $companyId,int $assetId): ?object
    {
        return DB::table('equipment_loans')
            ->where('company_id',$companyId)->where('equipment_asset_id',$assetId)
            ->where('status','active')->first();
    }

    private function loanStatus(object $r,string $today): string
    {
        if($r->status==='returned')return 'returned';
        return $r->expected_return_date<$today?'overdue':'active';
    }

    public function assetPayload(object $r): array
    {
        $usage=DB::table('equipment_usage_entries')->where('company_id',$r->company_id)
            ->where('equipment_asset_id',$r->id)->orderBy('id')->get();
        $maintenance=DB::table('equipment_maintenance_entries')
            ->where('company_id',$r->company_id)
            ->where('equipment_asset_id',$r->id)->orderBy('id')->get();
        $photos=$this->photos($r->company_id,$r->id);
        $active=$this->activeLoan($r->company_id,$r->id);
        $history=DB::table('equipment_asset_events')
            ->where('company_id',$r->company_id)
            ->where('equipment_asset_id',$r->id)->orderBy('id')->get();
        $hours=(float)$r->initial_hours+(float)$usage->sum('hours');
        return [
            'id'=>(int)$r->id,'company_id'=>(int)$r->company_id,
            'code'=>$r->code,'name'=>$r->name,
            'category'=>$r->category,'brand'=>$r->brand,'model'=>$r->model,
            'purchase_date'=>$r->purchase_date,'status'=>$r->status,
            'location'=>$r->location,'notes'=>$r->notes??'',
            'initial_hours'=>(float)$r->initial_hours,'hours'=>$hours,
            'lock_version'=>(int)$r->lock_version,'created_at'=>$r->created_at,
            'current_loan_id'=>$active?->id,
            'usage'=>$usage->map(fn($u)=>[
                'id'=>(int)$u->id,'date'=>$u->usage_date,'hours'=>(float)$u->hours,
                'job_id'=>$u->service_job_id,'notes'=>$u->notes??'',
            ])->all(),
            'maintenance'=>$maintenance->map(fn($m)=>[
                'id'=>(int)$m->id,'date'=>$m->maintenance_date,
                'description'=>$m->description,'completed'=>(bool)$m->completed,
                'completed_at'=>$m->completed_at,
            ])->all(),'photos'=>$photos,
            'history'=>$history->map(fn($e)=>[
                'id'=>(int)$e->id,'type'=>$e->event_type,
                'actor_id'=>$e->actor_user_id,'created_at'=>$e->created_at,
                'metadata'=>json_decode($e->metadata??'{}',true)?:[],
            ])->all(),
        ];
    }

    public function assets(?int $companyId,array $filters=[]): array
    {
        $q=DB::table('equipment_assets')->whereNull('archived_at');
        if($companyId!==null)$q->where('company_id',$companyId);
        elseif(!empty($filters['company_id']))$q->where('company_id',$filters['company_id']);
        if(!empty($filters['status']))$q->where('status',$filters['status']);
        if(!empty($filters['q'])){
            $t='%'.str_replace(['%','_'],['\%','\_'],$filters['q']).'%';
            $q->where(fn($b)=>$b->where('code','like',$t)
                ->orWhere('name','like',$t)->orWhere('category','like',$t)
                ->orWhere('brand','like',$t)->orWhere('model','like',$t));
        }
        $total=(clone $q)->count();
        $per=min(100,max(1,(int)($filters['per_page']??25)));
        $page=max(1,(int)($filters['page']??1));
        $rows=$q->orderByDesc('id')->offset(($page-1)*$per)->limit($per)->get();
        return ['records'=>$rows->map(fn($r)=>$this->assetPayload($r))->all(),
            'total'=>$total,'page'=>$page,
            'last_page'=>max(1,(int)ceil($total/$per))];
    }

    private function assetInput(Request $request,bool $update=false): array
    {
        return $request->validate([
            'code'=>['required','string','max:80','regex:/^[A-Za-z0-9._-]+$/D'],
            'name'=>['required','string','max:255'],
            'category'=>['required','string','max:120'],
            'brand'=>['required','string','max:160'],
            'model'=>['required','string','max:160'],
            'purchase_date'=>['required','date_format:Y-m-d'],
            'status'=>['required',Rule::in(self::STATES)],
            'location'=>['nullable','string','max:255'],
            'notes'=>['nullable','string','max:6000'],
            'initial_hours'=>['sometimes','numeric','between:0,100000000'],
            'lock_version'=>[$update?'required':'nullable','integer','min:1'],
        ]);
    }

    public function saveAsset(Request $request,Company $company,?int $id=null): array
    {
        $data=$this->assetInput($request,$id!==null);
        $r=DB::transaction(function()use($data,$company,$id,$request):object{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $actor=$request->user()?->id;
            $current=$id===null?null:DB::table('equipment_assets')
                ->where('company_id',$company->id)->where('id',$id)
                ->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            if($current){
                abort_unless((int)$current->lock_version===(int)$data['lock_version'],
                    409,'Equipment was changed by another user.');
                // Cannot archive or mark as maintenance with an active loan.
                if($data['status']==='maintenance')
                    abort_if($this->activeLoan($company->id,$id),409,
                        'Return this equipment before maintenance.');
            }
            $values=[
                'code'=>$data['code'],'name'=>trim($data['name']),
                'category'=>trim($data['category']),
                'brand'=>trim($data['brand']),'model'=>trim($data['model']),
                'purchase_date'=>$data['purchase_date'],'status'=>$data['status'],
                'location'=>trim($data['location']??''),
                'notes'=>$data['notes']??'',
                'updated_by'=>$actor,'updated_at'=>now(),
            ];
            if($current){
                $values['lock_version']=$current->lock_version+1;
                // Historical meter origin cannot be changed after usage is recorded.
                if(array_key_exists('initial_hours',$data)){
                    $hasUsage=DB::table('equipment_usage_entries')
                        ->where('company_id',$company->id)
                        ->where('equipment_asset_id',$id)->exists();
                    abort_if($hasUsage&&abs((float)$data['initial_hours']-
                        (float)$current->initial_hours)>0.00001,409,
                        'Initial hours are locked after usage is recorded.');
                    $values['initial_hours']=$data['initial_hours'];
                }
                $duplicate=DB::table('equipment_assets')
                    ->where('company_id',$company->id)->where('code',$data['code'])
                    ->where('id','!=',$id)->exists();
                abort_if($duplicate,409,'Equipment code already registered.');
                DB::table('equipment_assets')->where('id',$id)->update($values);
                $row=DB::table('equipment_assets')->where('id',$id)->firstOrFail();
                $this->assetEvent($row,$actor,'updated',[
                    'previous_status'=>$current->status,'new_status'=>$row->status]);
            }else{
                abort_if(DB::table('equipment_assets')->where('company_id',$company->id)
                    ->where('code',$data['code'])->exists(),409,
                    'Equipment code already registered.');
                $values['company_id']=$company->id;
                $values['initial_hours']=$data['initial_hours']??0;
                $values['created_by']=$actor;$values['created_at']=now();
                $newId=DB::table('equipment_assets')->insertGetId($values);
                $row=DB::table('equipment_assets')->where('id',$newId)->firstOrFail();
                $this->assetEvent($row,$actor,'created',['code'=>$row->code]);
            }
            return $row;
        });
        return $this->assetPayload($r);
    }

    public function archiveAsset(Request $request,Company $company,int $id): array
    {
        $request->validate(['confirm'=>['required','accepted'],
            'lock_version'=>['required','integer','min:1']]);
        return DB::transaction(function()use($request,$company,$id):array{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $r=DB::table('equipment_assets')->where('company_id',$company->id)
                ->where('id',$id)->whereNull('archived_at')->lockForUpdate()
                ->firstOrFail();
            abort_unless((int)$r->lock_version===(int)$request->input('lock_version'),
                409,'Equipment was changed by another user.');
            abort_if($this->activeLoan($company->id,$id),409,
                'Equipment with an active loan cannot be archived.');
            DB::table('equipment_assets')->where('id',$id)
                ->update(['archived_at'=>now(),'lock_version'=>$r->lock_version+1,
                    'updated_at'=>now(),'updated_by'=>$request->user()?->id]);
            $this->assetEvent($r,$request->user()?->id,'archived');
            return ['archived'=>true,'id'=>$id];
        });
    }

    public function usage(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'hours'=>['required','numeric','gt:0','lte:100000'],
            'job_id'=>['nullable','integer','min:1'],
            'notes'=>['nullable','string','max:2000'],
            'lock_version'=>['required','integer','min:1'],
        ]);
        $r=DB::transaction(function()use($request,$company,$id,$data):object{
            $r=DB::table('equipment_assets')->where('company_id',$company->id)
                ->where('id',$id)->whereNull('archived_at')->lockForUpdate()
                ->firstOrFail();
            abort_unless((int)$r->lock_version===(int)$data['lock_version'],409);
            $job=$data['job_id']??null;
            if($job!==null)abort_unless(DB::table('service_jobs')
                ->where('company_id',$company->id)->where('id',$job)->exists(),422,
                'Job does not belong to this company.');
            DB::table('equipment_usage_entries')->insert([
                'company_id'=>$company->id,'equipment_asset_id'=>$id,
                'service_job_id'=>$job,'usage_date'=>$data['date'],
                'hours'=>$data['hours'],'notes'=>$data['notes']??'',
                'created_by'=>$request->user()?->id,'created_at'=>now(),
            ]);
            DB::table('equipment_assets')->where('id',$id)->update([
                'lock_version'=>$r->lock_version+1,'updated_at'=>now()]);
            $this->assetEvent($r,$request->user()?->id,'usage_added',
                ['date'=>$data['date'],'hours'=>$data['hours'],'job_id'=>$job]);
            return DB::table('equipment_assets')->where('id',$id)->firstOrFail();
        });
        return $this->assetPayload($r);
    }

    public function maintenance(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'date'=>['required','date_format:Y-m-d'],
            'description'=>['required','string','max:2000'],
            'completed'=>['sometimes','boolean'],
            'lock_version'=>['required','integer','min:1'],
        ]);
        $r=DB::transaction(function()use($request,$company,$id,$data):object{
            $r=DB::table('equipment_assets')->where('company_id',$company->id)
                ->where('id',$id)->whereNull('archived_at')->lockForUpdate()
                ->firstOrFail();
            abort_unless((int)$r->lock_version===(int)$data['lock_version'],409);
            DB::table('equipment_maintenance_entries')->insert([
                'company_id'=>$company->id,'equipment_asset_id'=>$id,
                'description'=>trim($data['description']),
                'maintenance_date'=>$data['date'],
                'completed'=>$data['completed']??false,
                'completed_at'=>($data['completed']??false)?now():null,
                'created_by'=>$request->user()?->id,'created_at'=>now(),
            ]);
            DB::table('equipment_assets')->where('id',$id)->update([
                'lock_version'=>$r->lock_version+1,'updated_at'=>now()]);
            $this->assetEvent($r,$request->user()?->id,'maintenance_scheduled',
                ['date'=>$data['date'],'completed'=>$data['completed']??false]);
            return DB::table('equipment_assets')->where('id',$id)->firstOrFail();
        });
        return $this->assetPayload($r);
    }

    public function photos(int $companyId,int $assetId): array
    {
        return DB::table('equipment_asset_photos')->where('company_id',$companyId)
            ->where('equipment_asset_id',$assetId)->whereNull('removed_at')
            ->orderBy('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'name'=>$r->original_name,
                'mime_type'=>$r->mime_type,'byte_size'=>(int)$r->byte_size,
                'sha256'=>$r->sha256,'created_at'=>$r->created_at,
            ])->all();
    }

    public function addPhoto(Request $request,Company $company,int $assetId): array
    {
        $request->validate([
            'file'=>['required','file','mimes:jpg,jpeg,png','max:5120'],
            'lock_version'=>['required','integer','min:1'],
        ]);
        $file=$request->file('file');
        $bytes=file_get_contents($file->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)<=5*1024*1024,422);
        $mime=$file->getMimeType();
        abort_unless(in_array($mime,['image/jpeg','image/png'],true),422);
        $hash=hash('sha256',$bytes);
        $path='equipment/'.$company->id.'/'.$assetId.'/'.Str::uuid().'_'.$hash.
            ($mime==='image/png'?'.png':'.jpg');
        abort_unless(Storage::disk('local')->put($path,$bytes),503,'Photo storage failed.');
        try{
            $row=DB::transaction(function()use($request,$company,$assetId,$mime,
                $hash,$path,$bytes,$file):object{
                $r=DB::table('equipment_assets')->where('company_id',$company->id)
                    ->where('id',$assetId)->whereNull('archived_at')
                    ->lockForUpdate()->firstOrFail();
                abort_unless((int)$r->lock_version===
                    (int)$request->input('lock_version'),409,
                    'Equipment record changed.');
                DB::table('equipment_asset_photos')->insert([
                    'company_id'=>$company->id,'equipment_asset_id'=>$assetId,
                    'uploaded_by'=>$request->user()?->id,
                    'original_name'=>mb_substr($file->getClientOriginalName(),0,255),
                    'private_path'=>$path,'mime_type'=>$mime,
                    'sha256'=>$hash,'byte_size'=>strlen($bytes),'created_at'=>now(),
                ]);
                DB::table('equipment_assets')->where('id',$assetId)->update([
                    'lock_version'=>$r->lock_version+1,'updated_at'=>now()]);
                $this->assetEvent($r,$request->user()?->id,'photo_uploaded',
                    ['sha256'=>$hash]);
                return DB::table('equipment_assets')->where('id',$assetId)->firstOrFail();
            });
        }catch(\Throwable $e){
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return $this->assetPayload($row);
    }

    public function photoBytes(int $companyId,int $assetId,int $photo): array
    {
        $this->asset($companyId,$assetId);
        $r=DB::table('equipment_asset_photos')->where('company_id',$companyId)
            ->where('equipment_asset_id',$assetId)->where('id',$photo)
            ->whereNull('removed_at')->firstOrFail();
        $bytes=Storage::disk('local')->get($r->private_path);
        abort_unless(is_string($bytes)&&strlen($bytes)===(int)$r->byte_size
            &&hash_equals($r->sha256,hash('sha256',$bytes)),409,
            'Equipment photo failed integrity verification.');
        return ['bytes'=>$bytes,'mime'=>$r->mime_type];
    }

    public function removePhoto(Request $request,Company $company,int $assetId,int $photo): array
    {
        $request->validate(['lock_version'=>['required','integer','min:1']]);
        $row=DB::transaction(function()use($request,$company,$assetId,$photo):object{
            $r=DB::table('equipment_assets')->where('company_id',$company->id)
                ->where('id',$assetId)->whereNull('archived_at')
                ->lockForUpdate()->firstOrFail();
            abort_unless((int)$r->lock_version===
                (int)$request->input('lock_version'),409);
            $updated=DB::table('equipment_asset_photos')
                ->where('company_id',$company->id)
                ->where('equipment_asset_id',$assetId)->where('id',$photo)
                ->whereNull('removed_at')->update(['removed_at'=>now()]);
            abort_unless($updated===1,404);
            DB::table('equipment_assets')->where('id',$assetId)->update([
                'lock_version'=>$r->lock_version+1,'updated_at'=>now()]);
            $this->assetEvent($r,$request->user()?->id,'photo_removed',
                ['photo_id'=>$photo]);
            return DB::table('equipment_assets')->where('id',$assetId)->firstOrFail();
        });
        return $this->assetPayload($row);
    }

    private function checkBorrower(int $companyId,array $data): void
    {
        if($data['borrower_type']==='client'){
            abort_unless(!empty($data['client_id']),422,
                'Select a client from your company.');
            $c=DB::table('clients')->where('company_id',$companyId)
                ->where('id',$data['client_id'])->whereNull('deleted_at')->first();
            abort_unless($c!==null,422,'Client does not belong to your company.');
        }else abort_unless(empty($data['client_id']),422,
            'External borrowers cannot use a client identifier.');
        if(!empty($data['job_id'])){
            $job=DB::table('service_jobs')->where('company_id',$companyId)
                ->where('id',$data['job_id'])->first();
            abort_unless($job!==null,422,'Job does not belong to your company.');
            if($data['borrower_type']==='client')
                abort_unless((int)$job->client_id===(int)$data['client_id'],422,
                    'Selected job belongs to another client.');
        }
    }

    public function loanPayload(object $r,?string $today=null): array
    {
        $today??=Carbon::today('Europe/Zurich')->toDateString();
        $asset=DB::table('equipment_assets')->where('id',$r->equipment_asset_id)
            ->where('company_id',$r->company_id)->first();
        return [
            'id'=>(int)$r->id,'company_id'=>(int)$r->company_id,
            'equipment_asset_id'=>(int)$r->equipment_asset_id,
            'equipment_code'=>$asset?->code,'equipment_name'=>$asset?->name,
            'borrower_type'=>$r->borrower_type,
            'borrower_name'=>$r->borrower_name,
            'borrower_email'=>$r->borrower_email,
            'client_id'=>$r->client_id,'job_id'=>$r->service_job_id,
            'loan_date'=>$r->loan_date,
            'expected_return_date'=>$r->expected_return_date,
            'actual_return_date'=>$r->actual_return_date,
            'status'=>$this->loanStatus($r,$today),
            'stored_status'=>$r->status,'notes'=>$r->notes??'',
            'lock_version'=>(int)$r->lock_version,
            'created_at'=>$r->created_at,'returned_at'=>$r->returned_at,
        ];
    }

    public function loans(?int $companyId,array $filters=[]): array
    {
        $q=DB::table('equipment_loans');
        if($companyId!==null)$q->where('company_id',$companyId);
        elseif(!empty($filters['company_id']))$q->where('company_id',$filters['company_id']);
        $today=(string)($filters['today']??Carbon::today('Europe/Zurich')->toDateString());
        if(($filters['status']??null)==='active')
            $q->where('status','active')->where('expected_return_date','>=',$today);
        elseif(($filters['status']??null)==='overdue')
            $q->where('status','active')->where('expected_return_date','<',$today);
        elseif(($filters['status']??null)==='returned')$q->where('status','returned');
        if(!empty($filters['equipment_asset_id']))$q
            ->where('equipment_asset_id',$filters['equipment_asset_id']);
        if(!empty($filters['client_id']))$q->where('client_id',$filters['client_id']);
        if(!empty($filters['q']))$q->where('borrower_name','like','%'.
            str_replace(['%','_'],['\%','\_'],$filters['q']).'%');
        $total=(clone $q)->count();
        $per=min(100,max(1,(int)($filters['per_page']??25)));
        $page=max(1,(int)($filters['page']??1));
        $rows=$q->orderByDesc('id')->offset(($page-1)*$per)->limit($per)->get();
        return ['records'=>$rows->map(fn($r)=>$this->loanPayload($r,$today))->all(),
            'total'=>$total,'page'=>$page,'last_page'=>max(1,(int)ceil($total/$per))];
    }

    public function loanHistory(int $companyId,int $id): array
    {
        $this->loan($companyId,$id);
        return DB::table('equipment_loan_events')->where('company_id',$companyId)
            ->where('equipment_loan_id',$id)->orderBy('id')->get()->map(fn($r)=>[
                'id'=>(int)$r->id,'type'=>$r->event_type,
                'actor_id'=>$r->actor_user_id,'created_at'=>$r->created_at,
                'metadata'=>json_decode($r->metadata??'{}',true)?:[],
            ])->all();
    }

    public function createLoan(Request $request,Company $company): array
    {
        $data=$request->validate([
            'equipment_asset_id'=>['required','integer','min:1'],
            'borrower_type'=>['required',Rule::in(['client','external'])],
            'borrower_name'=>['required','string','between:2,255'],
            'borrower_email'=>['nullable','email','max:255'],
            'client_id'=>['nullable','integer','min:1'],
            'job_id'=>['nullable','integer','min:1'],
            'loan_date'=>['required','date_format:Y-m-d'],
            'expected_return_date'=>['required','date_format:Y-m-d','after_or_equal:loan_date'],
            'notes'=>['nullable','string','max:6000'],
            'request_key'=>['required','string','between:8,100',
                'regex:/^[A-Za-z0-9._-]+$/D'],
        ]);
        $this->checkBorrower($company->id,$data);
        $fingerprint=hash('sha256',json_encode([
            (int)$data['equipment_asset_id'],$data['borrower_type'],
            trim($data['borrower_name']),mb_strtolower(trim($data['borrower_email']??'')),
            $data['client_id']??null,$data['job_id']??null,
            $data['loan_date'],$data['expected_return_date'],$data['notes']??'',
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $r=DB::transaction(function()use($company,$request,$data,$fingerprint):object{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $existing=DB::table('equipment_loans')->where('company_id',$company->id)
                ->where('request_key',$data['request_key'])->first();
            if($existing){
                abort_unless(hash_equals($existing->request_fingerprint,$fingerprint),
                    409,'Loan retry key belongs to a different request.');
                return $existing;
            }
            $asset=DB::table('equipment_assets')->where('company_id',$company->id)
                ->where('id',$data['equipment_asset_id'])->whereNull('archived_at')
                ->lockForUpdate()->firstOrFail();
            abort_if($asset->status==='maintenance',409,
                'Equipment under maintenance cannot be lent out.');
            abort_if($this->activeLoan($company->id,$asset->id)!==null,409,
                'Equipment already has an active loan.');
            $id=DB::table('equipment_loans')->insertGetId([
                'company_id'=>$company->id,'equipment_asset_id'=>$asset->id,
                'borrower_type'=>$data['borrower_type'],
                'borrower_name'=>trim($data['borrower_name']),
                'borrower_email'=>isset($data['borrower_email'])
                    ?mb_strtolower(trim($data['borrower_email'])):null,
                'client_id'=>$data['client_id']??null,
                'service_job_id'=>$data['job_id']??null,
                'loan_date'=>$data['loan_date'],
                'expected_return_date'=>$data['expected_return_date'],
                'notes'=>$data['notes']??'','status'=>'active','active_slot'=>1,
                'request_key'=>$data['request_key'],
                'request_fingerprint'=>$fingerprint,
                'created_by'=>$request->user()?->id,
                'updated_by'=>$request->user()?->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $loan=DB::table('equipment_loans')->where('id',$id)->firstOrFail();
            $this->loanEvent($loan,$request->user()?->id,'created',[
                'equipment_code'=>$asset->code,
                'loan_date'=>$loan->loan_date,'expected_return_date'=>$loan->expected_return_date,
                'borrower_type'=>$loan->borrower_type,
            ]);
            $this->assetEvent($asset,$request->user()?->id,'lent',
                ['loan_id'=>$id,'due'=>$loan->expected_return_date]);
            return $loan;
        },3);
        return $this->loanPayload($r,$this->companyDate($company));
    }

    public function updateLoan(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'lock_version'=>['required','integer','min:1'],
            'notes'=>['sometimes','nullable','string','max:6000'],
            'borrower_email'=>['sometimes','nullable','email','max:255'],
        ]);
        $r=DB::transaction(function()use($request,$company,$id,$data):object{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $r=DB::table('equipment_loans')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless((int)$r->lock_version===(int)$data['lock_version'],409,
                'Loan changed on another device.');
            abort_unless($r->status==='active',409,'Returned loans are immutable.');
            $values=['lock_version'=>$r->lock_version+1,
                'updated_by'=>$request->user()?->id,'updated_at'=>now()];
            if(array_key_exists('notes',$data))$values['notes']=$data['notes']??'';
            if(array_key_exists('borrower_email',$data))$values['borrower_email']=
                $data['borrower_email']?mb_strtolower(trim($data['borrower_email'])):null;
            DB::table('equipment_loans')->where('id',$id)->update($values);
            $this->loanEvent($r,$request->user()?->id,'details_updated');
            return DB::table('equipment_loans')->where('id',$id)->firstOrFail();
        });
        return $this->loanPayload($r,$this->companyDate($company));
    }

    public function transitionLoan(Request $request,Company $company,int $id,
        string $action): array
    {
        abort_unless(in_array($action,['extend','return'],true),404);
        $data=$request->validate([
            'lock_version'=>['required','integer','min:1'],
            'confirm'=>['required','accepted'],
            'expected_return_date'=>[$action==='extend'?'required':'nullable',
                'date_format:Y-m-d'],
            'actual_return_date'=>[$action==='return'?'required':'nullable',
                'date_format:Y-m-d'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        $r=DB::transaction(function()use($company,$request,$id,$action,$data):object{
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $r=DB::table('equipment_loans')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless((int)$r->lock_version===(int)$data['lock_version'],409,
                'Loan record changed. Refresh before taking an action.');
            abort_unless($r->status==='active',409,'Loan already returned.');
            $values=['lock_version'=>$r->lock_version+1,'updated_at'=>now(),
                'updated_by'=>$request->user()?->id];
            $meta=[];
            if($action==='extend'){
                $next=$data['expected_return_date'];
                abort_unless($next>$r->expected_return_date
                    &&$next>=$r->loan_date,422,
                    'New return date must be later than the current date.');
                $values['expected_return_date']=$next;
                $meta=['old_date'=>$r->expected_return_date,'new_date'=>$next];
            }else{
                $date=$data['actual_return_date'];
                abort_unless($date>=$r->loan_date
                    &&$date<=$this->companyDate($company),422,
                    'Return date must be between loan date and today.');
                $values['status']='returned';
                $values['active_slot']=null;
                $values['actual_return_date']=$date;
                $values['returned_at']=now();
                $values['returned_by']=$request->user()?->id;
                $meta=['returned_date'=>$date];
            }
            if(!empty($data['notes']))$meta['reason']=$data['notes'];
            DB::table('equipment_loans')->where('id',$id)->update($values);
            $this->loanEvent($r,$request->user()?->id,
                $action==='extend'?'return_date_extended':'returned',$meta);
            $asset=$this->asset($company->id,$r->equipment_asset_id);
            $this->assetEvent($asset,$request->user()?->id,
                $action==='extend'?'loan_extended':'loan_returned',
                ['loan_id'=>$id]);
            return DB::table('equipment_loans')->where('id',$id)->firstOrFail();
        },3);
        return $this->loanPayload($r,$this->companyDate($company));
    }
}
