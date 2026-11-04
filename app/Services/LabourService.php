<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\AuditLogger;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LabourService
{
    private const STATES=['pending','approved','void'];
    private const KINDS=['labour','time_sheet'];
    private const TZ='Europe/Zurich';

    private function role(Company $company,?User $actor): ?string
    {
        if($actor===null)return null;
        if($actor->is_platform_admin)return 'platform_admin';
        $member=$company->users()->where('users.id',$actor->id)
            ->wherePivot('status','active')->first();
        return $member?->pivot?->role;
    }

    public function reviewer(Company $company,?User $actor): void
    {
        abort_unless(in_array($this->role($company,$actor),
            ['owner','admin','platform_admin'],true),403,
            'Company owner or admin review required.');
    }

    private function assigned(Company $company,int $jobId,int $worker,?User $actor,
        bool $requireActorOwnership=true): array
    {
        $job=ServiceJob::query()->where('company_id',$company->id)
            ->where('category','!=','archived')->findOrFail($jobId);
        $employee=$company->users()->where('users.id',$worker)
            ->wherePivot('status','active')->first();
        abort_unless($employee!==null,422,'Worker is not an active company member.');
        abort_unless(DB::table('service_job_user')->where('service_job_id',$job->id)
            ->where('user_id',$worker)->exists(),422,
            'Worker must be assigned to this job.');
        $role=$this->role($company,$actor);
        abort_unless($role!==null,403);
        if($requireActorOwnership&&!in_array($role,['owner','admin','platform_admin'],true))
            abort_unless($actor?->id===$worker,403,
                'Team members may only submit their own time.');
        return [$job,$employee];
    }

    public static function rateMinor(mixed $raw): ?int
    {
        if($raw===null||$raw==='')return null;
        if(!is_string($raw)||!preg_match('/^(?:0|[1-9][0-9]{0,5})(?:[.][0-9]{1,2})?$/D',$raw))
            throw ValidationException::withMessages([
                'hourly_rate'=>'Hourly rate must be a nonnegative CHF amount with two decimals.',
            ]);
        [$whole,$part]=array_pad(explode('.',$raw,2),2,'');
        $amount=(int)$whole*100+(int)str_pad($part,2,'0');
        abort_if($amount>99999999,422,'Hourly rate exceeds supported limit.');
        return $amount;
    }

    public static function cost(int $minutes,?int $rateMinor): ?int
    {
        return $rateMinor===null?null:intdiv($minutes*$rateMinor+30,60);
    }

    public static function workSpan(string $date,int $start,int $end): array
    {
        if($start<0||$start>=1440||$end<0||$end>=1440||$start===$end)
            throw ValidationException::withMessages(['start_minutes'=>'Invalid time range.']);
        $tz=new DateTimeZone(self::TZ);
        $startClock=sprintf('%02d:%02d',intdiv($start,60),$start%60);
        $endClock=sprintf('%02d:%02d',intdiv($end,60),$end%60);
        $startLocal=DateTimeImmutable::createFromFormat('!Y-m-d H:i',
            $date.' '.$startClock,$tz);
        $endDate=(new DateTimeImmutable($date,new DateTimeZone('UTC')))
            ->modify($end<=$start?'+1 day':'+0 day')->format('Y-m-d');
        $endLocal=DateTimeImmutable::createFromFormat('!Y-m-d H:i',
            $endDate.' '.$endClock,$tz);
        if(!$startLocal||!$endLocal||
            $startLocal->format('Y-m-d H:i')!==$date.' '.$startClock||
            $endLocal->format('Y-m-d H:i')!==$endDate.' '.$endClock)
            throw ValidationException::withMessages(['work_date'=>'Nonexistent local time (DST).']);
        $seconds=$endLocal->getTimestamp()-$startLocal->getTimestamp();
        if($seconds<60||$seconds>24*3600||$seconds%60!==0)
            throw ValidationException::withMessages([
                'end_minutes'=>'Recorded time must be between 1 minute and 24 hours.',
            ]);
        return [
            $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            intdiv($seconds,60),
        ];
    }

    private function overlap(int $companyId,int $worker,string $start,string $end,
        ?int $exclude=null): void
    {
        $query=DB::table('job_labour_entries')->where('company_id',$companyId)
            ->where('worker_user_id',$worker)->where('status','!=','void')
            ->where('work_started_at','<',$end)->where('work_ended_at','>',$start);
        if($exclude!==null)$query->where('id','!=',$exclude);
        abort_if($query->exists(),409,'Overlapping work for this worker.');
    }

    private function event(int $companyId,?int $entryId,?int $worker,?int $actor,
        string $type,array $metadata=[]): void
    {
        DB::table('job_labour_events')->insert([
            'company_id'=>$companyId,'job_labour_entry_id'=>$entryId,
            'worker_user_id'=>$worker,'actor_user_id'=>$actor,
            'event_type'=>$type,'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }

    public function find(int $companyId,int $id): object
    {
        return DB::table('job_labour_entries as e')
            ->join('service_jobs as j','j.id','=','e.service_job_id')
            ->where('e.company_id',$companyId)->where('j.company_id',$companyId)
            ->where('e.id',$id)->select('e.*','j.title as job_title',
                'j.reference_number as job_reference')->firstOrFail();
    }

    public function payload(object $r): array
    {
        return [
            'id'=>(int)$r->id,'company_id'=>(int)$r->company_id,
            'job_id'=>(int)$r->service_job_id,'job'=>(string)($r->job_title??''),
            'job_reference'=>$r->job_reference??null,
            'worker_id'=>(int)$r->worker_user_id,
            'worker_name'=>$r->worker_name_snapshot,
            'role'=>$r->role,'task'=>$r->task,'kind'=>$r->kind,'source'=>$r->source,
            'work_date'=>$r->work_date,'timezone'=>$r->timezone,
            'start_minutes'=>(int)$r->start_minutes,
            'end_minutes'=>(int)$r->end_minutes,
            'duration_minutes'=>(int)$r->duration_minutes,
            'hourly_rate_minor'=>$r->hourly_rate_minor===null
                ?null:(int)$r->hourly_rate_minor,
            'cost_minor'=>$r->cost_minor===null?null:(int)$r->cost_minor,
            'currency'=>'CHF','status'=>$r->status,
            'lock_version'=>(int)$r->lock_version,
            'approved_at'=>$r->approved_at,'approved_by'=>$r->approved_by,
            'voided_at'=>$r->voided_at,'void_reason'=>$r->void_reason,
            'created_at'=>$r->created_at,'updated_at'=>$r->updated_at,
        ];
    }

    public function listing(?int $companyId,array $filters=[]): array
    {
        $q=DB::table('job_labour_entries as e')
            ->join('service_jobs as j','j.id','=','e.service_job_id')
            ->whereColumn('e.company_id','j.company_id')
            ->select('e.*','j.title as job_title','j.reference_number as job_reference');
        if($companyId!==null)$q->where('e.company_id',$companyId);
        elseif(!empty($filters['company_id']))$q->where('e.company_id',(int)$filters['company_id']);
        foreach(['job_id'=>'e.service_job_id','worker_id'=>'e.worker_user_id',
                 'status'=>'e.status','kind'=>'e.kind'] as $key=>$col) {
            if(isset($filters[$key])&&$filters[$key]!=='')$q->where($col,$filters[$key]);
        }
        if(!empty($filters['from']))$q->whereDate('e.work_date','>=',$filters['from']);
        if(!empty($filters['to']))$q->whereDate('e.work_date','<=',$filters['to']);
        if(!empty($filters['q'])) {
            $term='%'.addcslashes($filters['q'],'%_').'%';
            $q->where(fn($q)=>$q->where('e.worker_name_snapshot','like',$term)
                ->orWhere('e.role','like',$term)->orWhere('e.task','like',$term)
                ->orWhere('j.reference_number','like',$term));
        }
        $total=(clone $q)->count();
        $per=min(100,max(1,(int)($filters['per_page']??30)));
        $page=max(1,(int)($filters['page']??1));
        $rows=$q->orderByDesc('e.work_date')->orderByDesc('e.id')
            ->offset(($page-1)*$per)->limit($per)->get();
        return ['records'=>$rows->map(fn($r)=>$this->payload($r))->all(),
            'total'=>$total,'page'=>$page,
            'last_page'=>max(1,(int)ceil($total/$per))];
    }

    public function history(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('job_labour_events')->where('company_id',$companyId)
            ->where('job_labour_entry_id',$id)->orderBy('id')->get()
            ->map(fn($r)=>[
                'id'=>(int)$r->id,'title'=>str_replace('_',' ',$r->event_type),
                'actor_user_id'=>$r->actor_user_id,'occurred_at'=>$r->created_at,
                'metadata'=>json_decode((string)($r->metadata??'{}'),true)?:[],
            ])->all();
    }

    public function save(Request $request,Company $company,?int $id=null): array
    {
        $data=$request->validate([
            'job_id'=>['required','integer','min:1'],
            'worker_id'=>['required','integer','min:1'],
            'kind'=>['required',Rule::in(self::KINDS)],
            'worker_role'=>['nullable','string','max:120'],
            'task'=>['nullable','string','max:12000'],
            'work_date'=>['required','date_format:Y-m-d'],
            'start_minutes'=>['required','integer','between:0,1439'],
            'end_minutes'=>['required','integer','between:0,1439'],
            'hourly_rate'=>['present','nullable','string','max:30'],
            'lock_version'=>[$id===null?'nullable':'required','integer','min:1'],
        ]);
        [$job,$worker]=$this->assigned($company,(int)$data['job_id'],
            (int)$data['worker_id'],$request->user());
        [$start,$end,$minutes]=self::workSpan(
            $data['work_date'],(int)$data['start_minutes'],(int)$data['end_minutes']);
        $rate=self::rateMinor($data['hourly_rate']);
        $actor=$request->user()?->id;
        // Lock company before overlap check so simultaneous requests cannot double-book.
        $row=DB::transaction(function()use($data,$company,$job,$worker,
            $start,$end,$minutes,$rate,$id,$actor): object {
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $values=[
                'worker_name_snapshot'=>$worker->name,'role'=>trim((string)($data['worker_role']??''))?:null,
                'task'=>trim((string)($data['task']??''))?:null,'work_date'=>$data['work_date'],
                'timezone'=>self::TZ,'start_minutes'=>(int)$data['start_minutes'],
                'end_minutes'=>(int)$data['end_minutes'],
                'work_started_at'=>$start,'work_ended_at'=>$end,
                'duration_minutes'=>$minutes,'hourly_rate_minor'=>$rate,
                'cost_minor'=>self::cost($minutes,$rate),
                'updated_by'=>$actor,'updated_at'=>now(),
            ];
            if($id===null) {
                $active=DB::table('job_labour_timers')->where('company_id',$company->id)
                    ->where('worker_user_id',$worker->id)
                    ->whereIn('status',['running','paused'])->exists();
                abort_if($active,409,'This worker already has an active timer.');
                $this->overlap($company->id,$worker->id,$start,$end);
                $new=DB::table('job_labour_entries')->insertGetId(array_merge($values,[
                    'company_id'=>$company->id,'service_job_id'=>$job->id,
                    'worker_user_id'=>$worker->id,'created_by'=>$actor,
                    'kind'=>$data['kind'],'source'=>'manual','status'=>'pending',
                    'currency'=>'CHF','lock_version'=>1,'created_at'=>now(),
                ]));
                $this->event($company->id,$new,$worker->id,$actor,'created');
                return $this->find($company->id,$new);
            }
            $existing=DB::table('job_labour_entries')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($existing->status==='pending',409,
                'Approved or voided time cannot be edited.');
            abort_unless($existing->source==='manual',409,
                'Timer records require an audited correction.');
            abort_unless($existing->service_job_id==$job->id
                &&$existing->worker_user_id==$worker->id
                &&$existing->kind===$data['kind'],409,
                'Linked job, employee and entry type cannot be reassigned.');
            abort_unless($existing->lock_version==(int)$data['lock_version'],409,
                'Entry has changed; reload.');
            $this->overlap($company->id,$worker->id,$start,$end,$id);
            DB::table('job_labour_entries')->where('id',$id)->update(array_merge(
                $values,['lock_version'=>$existing->lock_version+1]));
            $this->event($company->id,$id,$worker->id,$actor,'updated',[
                'version'=>$existing->lock_version+1]);
            return $this->find($company->id,$id);
        });
        AuditLogger::record($id===null?'mobile.labour.created':'mobile.labour.updated',
            $request->user(),null,$company,['entry_id'=>$row->id],$request);
        return $this->payload($row);
    }

    public function transition(Request $request,Company $company,int $id,string $action): array
    {
        abort_unless(in_array($action,['approve','void'],true),404);
        $reason=trim((string)$request->input('reason',''));
        if($action==='void') {
            $request->validate(['reason'=>['required','string','min:5','max:2000']]);
        } else $this->reviewer($company,$request->user());
        $actor=$request->user()?->id;
        $row=DB::transaction(function()use($company,$id,$action,$actor,$reason,$request):object {
            $old=DB::table('job_labour_entries')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($old->status!=='void',409,'Entry already voided.');
            if($action==='approve') {
                abort_unless($old->status==='pending',409,'Only pending entries can be approved.');
                DB::table('job_labour_entries')->where('id',$id)->update([
                    'status'=>'approved','approved_at'=>now(),'approved_by'=>$actor,
                    'lock_version'=>$old->lock_version+1,'updated_at'=>now(),
                ]);
            } else {
                if($old->status==='approved')$this->reviewer($company,$request->user());
                elseif($actor!==$old->worker_user_id)$this->reviewer($company,$request->user());
                DB::table('job_labour_entries')->where('id',$id)->update([
                    'status'=>'void','voided_at'=>now(),'voided_by'=>$actor,
                    'void_reason'=>$reason,'lock_version'=>$old->lock_version+1,
                    'updated_at'=>now(),
                ]);
            }
            $this->event($company->id,$id,$old->worker_user_id,$actor,
                $action==='approve'?'approved':'voided',
                $action==='void'?['reason'=>$reason]:[]);
            return $this->find($company->id,$id);
        });
        AuditLogger::record('labour.'.$action,$request->user(),null,$company,
            ['entry_id'=>$id],$request);
        return $this->payload($row);
    }

    public function workforce(Company $company,int $jobId): array
    {
        $job=ServiceJob::query()->where('company_id',$company->id)->findOrFail($jobId);
        return $job->assignees()->whereHas('companies',fn($q)=>$q
            ->where('companies.id',$company->id)
            ->where('companies.status','active')
            ->where('company_user.status','active'))
            ->get(['users.id','users.name'])->map(fn($u)=>[
                'id'=>(int)$u->id,'name'=>$u->name])->all();
    }

    public function jobTotals(int $companyId,int $jobId): array
    {
        $job=ServiceJob::query()->where('company_id',$companyId)->findOrFail($jobId);
        $rows=DB::table('job_labour_entries')->where('company_id',$companyId)
            ->where('service_job_id',$jobId)->whereIn('status',['pending','approved'])->get();
        $approvedMinutes=0;$pendingMinutes=0;
        $approvedCost=0;$pendingCost=0;$missingRateApproved=0;$missingRatePending=0;
        foreach($rows as $r) {
            if($r->status==='approved') {
                $approvedMinutes+=$r->duration_minutes;
                if($r->cost_minor===null)$missingRateApproved++;
                else $approvedCost+=(int)$r->cost_minor;
            } else {
                $pendingMinutes+=$r->duration_minutes;
                if($r->cost_minor===null)$missingRatePending++;
                else $pendingCost+=(int)$r->cost_minor;
            }
        }
        // M14 purchase totals exclude its 'labour' category to prevent counting
        // manual contractor invoices as the same worker's M15 time. Such external
        // labour invoices are separately surfaced for human reconciliation.
        $expenseQuery=DB::table('job_expenses')->where('company_id',$companyId)
            ->where('service_job_id',$jobId);
        $purchases=(int)(clone $expenseQuery)->where('category','!=','labour')
            ->whereIn('status',['approved','reimbursed'])->sum('amount_minor');
        $pendingPurchases=(int)(clone $expenseQuery)->where('category','!=','labour')
            ->where('status','to_review')->sum('amount_minor');
        $externalLabour=(int)(clone $expenseQuery)->where('category','labour')
            ->whereIn('status',['approved','reimbursed'])->sum('amount_minor');
        $actual=$missingRateApproved>0?null:$approvedCost+$purchases;
        $forecast=$missingRatePending>0||$actual===null?null:
            $actual+$pendingCost+$pendingPurchases;
        return [
            'job_id'=>$jobId,'currency'=>'CHF',
            'approved_minutes'=>$approvedMinutes,'pending_minutes'=>$pendingMinutes,
            'approved_labour_cost_minor'=>$missingRateApproved>0?null:$approvedCost,
            'pending_labour_cost_minor'=>$missingRatePending>0?null:$pendingCost,
            'approved_missing_rate_count'=>$missingRateApproved,
            'pending_missing_rate_count'=>$missingRatePending,
            'approved_purchase_minor'=>$purchases,
            'pending_purchase_minor'=>$pendingPurchases,
            'unreconciled_external_labour_expense_minor'=>$externalLabour,
            'overall_actual_cost_minor'=>$actual,
            'overall_forecast_cost_minor'=>$forecast,
            'budget_chf'=>$job->budget_chf,
            'excludes_unreconciled_labour_expenses'=>true,
        ];
    }

    public function evidence(int $companyId,int $id): array
    {
        $this->find($companyId,$id);
        return DB::table('job_labour_evidence')->where('company_id',$companyId)
            ->where('job_labour_entry_id',$id)->whereNull('removed_at')
            ->orderBy('id')->get()->map(fn($x)=>[
                'id'=>(int)$x->id,'name'=>$x->original_name,
                'mime_type'=>$x->mime_type,'byte_size'=>(int)$x->byte_size,
                'sha256'=>$x->sha256,'created_at'=>$x->created_at,
            ])->all();
    }

    public function upload(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:10240'],
        ]);
        $entry=$this->find($company->id,$id);
        abort_unless($entry->status==='pending',409);
        $bytes=file_get_contents($data['file']->getRealPath());
        abort_unless(is_string($bytes)&&strlen($bytes)>=8&&
            strlen($bytes)<=10*1024*1024,422);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        abort_unless(in_array($mime,['image/png','image/jpeg','application/pdf'],true),422);
        $valid=$mime==='application/pdf'
            ?str_starts_with($bytes,'%PDF-')&&str_contains(substr($bytes,-4096),'%%EOF')
            :is_array(@getimagesizefromstring($bytes));
        abort_unless($valid,422,'Invalid evidence content.');
        $hash=hash('sha256',$bytes);
        $path='labour/'.$company->id.'/'.$id.'/'.Str::uuid().'_'.$hash.
            ($mime==='application/pdf'?'.pdf':($mime==='image/png'?'.png':'.jpg'));
        abort_unless(Storage::disk('local')->put($path,$bytes),503);
        try {
            $rid=DB::transaction(function()use($company,$id,$request,$data,$path,$hash,$bytes,$mime):int {
                $row=DB::table('job_labour_entries')->where('company_id',$company->id)
                    ->where('id',$id)->lockForUpdate()->firstOrFail();
                abort_unless($row->status==='pending',409);
                abort_if(DB::table('job_labour_evidence')->where('job_labour_entry_id',$id)
                    ->whereNull('removed_at')->count()>=10,422,'Maximum 10 files.');
                $rid=DB::table('job_labour_evidence')->insertGetId([
                    'company_id'=>$company->id,'job_labour_entry_id'=>$id,
                    'uploaded_by'=>$request->user()?->id,
                    'original_name'=>mb_substr(basename(
                        $data['file']->getClientOriginalName()),0,255),
                    'private_path'=>$path,'sha256'=>$hash,'mime_type'=>$mime,
                    'byte_size'=>strlen($bytes),'created_at'=>now(),
                ]);
                $this->event($company->id,$id,$row->worker_user_id,
                    $request->user()?->id,'evidence_added',['evidence_id'=>$rid]);
                return $rid;
            });
        } catch(\Throwable $exception) {
            Storage::disk('local')->delete($path);throw $exception;
        }
        return ['id'=>$rid,'evidence'=>$this->evidence($company->id,$id)];
    }

    public function removeEvidence(Request $request,Company $company,int $id,int $evidence): array
    {
        DB::transaction(function()use($request,$company,$id,$evidence):void {
            $row=DB::table('job_labour_entries')->where('company_id',$company->id)
                ->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status==='pending',409);
            $proof=DB::table('job_labour_evidence')->where('company_id',$company->id)
                ->where('job_labour_entry_id',$id)->where('id',$evidence)
                ->whereNull('removed_at')->firstOrFail();
            DB::table('job_labour_evidence')->where('id',$proof->id)->update(['removed_at'=>now()]);
            $this->event($company->id,$id,$row->worker_user_id,$request->user()?->id,
                'evidence_removed',['evidence_id'=>$proof->id,'sha256'=>$proof->sha256]);
        });
        return ['evidence'=>$this->evidence($company->id,$id)];
    }

    public function evidenceFile(int $companyId,int $id,int $evidence): array
    {
        $this->find($companyId,$id);
        $file=DB::table('job_labour_evidence')->where('company_id',$companyId)
            ->where('job_labour_entry_id',$id)->where('id',$evidence)
            ->whereNull('removed_at')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->private_path),404);
        $bytes=Storage::disk('local')->get($file->private_path);
        abort_unless(is_string($bytes)&&strlen($bytes)===(int)$file->byte_size
            &&hash_equals($file->sha256,hash('sha256',$bytes)),409,
            'Evidence integrity mismatch.');
        return ['bytes'=>$bytes,'mime'=>$file->mime_type,'name'=>$file->original_name];
    }
}
