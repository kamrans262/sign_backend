<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LabourTimerService
{
    public function __construct(private readonly LabourService $labour) {}

    private function worker(Request $request,Company $company): int
    {
        $worker=(int)($request->input('worker_id')??$request->user()?->id);
        abort_unless($worker>0,403);
        $member=$company->users()->where('users.id',$worker)
            ->wherePivot('status','active')->first();
        abort_unless($member!==null,403);
        if($worker!==$request->user()?->id)
            $this->labour->reviewer($company,$request->user());
        return $worker;
    }

    public function current(Company $company,int $worker): array
    {
        $row=DB::table('job_labour_timers')->where('company_id',$company->id)
            ->where('worker_user_id',$worker)->first();
        return $this->snapshot($row);
    }

    private function snapshot(?object $row): array
    {
        if(!$row)return [
            'status'=>'stopped','job_id'=>null,'worker_id'=>null,
            'elapsed_seconds'=>0,'started_at'=>null,
            'hourly_rate_minor'=>null,'lock_version'=>0,
            'server_now'=>now('UTC')->toIso8601String(),
        ];
        $now=now('UTC');
        $elapsed=(int)$row->accumulated_seconds;
        if($row->status==='running'&&$row->running_since)
            $elapsed+=max(0,$now->timestamp-CarbonImmutable::parse(
                $row->running_since,'UTC')->timestamp);
        return [
            'status'=>$row->status,'job_id'=>(int)$row->service_job_id,
            'worker_id'=>(int)$row->worker_user_id,
            'elapsed_seconds'=>$elapsed,'started_at'=>$row->started_at,
            'running_since'=>$row->running_since,
            'hourly_rate_minor'=>$row->hourly_rate_minor===null
                ?null:(int)$row->hourly_rate_minor,
            'role'=>$row->role,'task'=>$row->task,
            'lock_version'=>(int)$row->lock_version,
            'server_now'=>$now->toIso8601String(),
        ];
    }

    public function perform(Request $request,Company $company,string $action): array
    {
        abort_unless(in_array($action,['start','pause','resume','stop'],true),404);
        $data=$request->validate([
            'request_key'=>['required','string','min:8','max:64',
                'regex:/^[A-Za-z0-9_-]+$/D'],
            'job_id'=>[$action==='start'?'required':'nullable','integer','min:1'],
            'worker_id'=>['nullable','integer','min:1'],
            'hourly_rate'=>['nullable','string','max:30'],
            'worker_role'=>['nullable','string','max:120'],
            'task'=>['nullable','string','max:12000'],
        ]);
        $worker=$this->worker($request,$company);
        $actor=$request->user()?->id;
        $result=DB::transaction(function()use($company,$worker,$actor,$request,$data,$action):array {
            // Serialize all timer requests with the company row, including the
            // first start when the worker's timer row does not yet exist.
            DB::table('companies')->where('id',$company->id)->lockForUpdate()->firstOrFail();
            $prior=DB::table('job_labour_timer_requests')
                ->where('company_id',$company->id)->where('worker_user_id',$worker)
                ->where('request_key',$data['request_key'])->first();
            if($prior) {
                abort_unless($prior->action===$action,409,'Idempotency key reused for another action.');
                $saved=json_decode($prior->response,true);
                abort_unless(is_array($saved),409);
                return $saved;
            }
            $old=DB::table('job_labour_timers')->where('company_id',$company->id)
                ->where('worker_user_id',$worker)->lockForUpdate()->first();
            $now=now('UTC');
            $entryId=null;
            if($action==='start') {
                abort_if($old&&$old->status!=='stopped',409,
                    'Worker has an active or paused timer.');
                $job=ServiceJob::query()->where('company_id',$company->id)
                    ->where('category','!=','archived')->findOrFail((int)$data['job_id']);
                abort_unless(DB::table('service_job_user')
                    ->where('service_job_id',$job->id)->where('user_id',$worker)->exists(),
                    422,'Worker must be assigned to the selected job.');
                $rate=LabourService::rateMinor($data['hourly_rate']??null);
                $values=[
                    'service_job_id'=>$job->id,'status'=>'running',
                    'role'=>trim((string)($data['worker_role']??''))?:null,
                    'task'=>trim((string)($data['task']??''))?:null,
                    'hourly_rate_minor'=>$rate,'started_at'=>$now,
                    'running_since'=>$now,'accumulated_seconds'=>0,
                    'lock_version'=>($old?->lock_version??0)+1,'updated_at'=>$now,
                ];
                if($old)DB::table('job_labour_timers')->where('id',$old->id)->update($values);
                else DB::table('job_labour_timers')->insert(array_merge($values,[
                    'company_id'=>$company->id,'worker_user_id'=>$worker,'created_at'=>$now,
                ]));
            } elseif($action==='pause') {
                abort_unless($old&&$old->status==='running',409,
                    'Only a running timer may be paused.');
                $elapsed=max(0,$now->timestamp-CarbonImmutable::parse(
                    $old->running_since,'UTC')->timestamp);
                $seconds=(int)$old->accumulated_seconds+$elapsed;
                abort_if($seconds>24*3600,409,'Timer exceeded 24 hours; correction required.');
                DB::table('job_labour_timers')->where('id',$old->id)->update([
                    'status'=>'paused','running_since'=>null,
                    'accumulated_seconds'=>$seconds,
                    'lock_version'=>$old->lock_version+1,'updated_at'=>$now,
                ]);
            } elseif($action==='resume') {
                abort_unless($old&&$old->status==='paused',409,
                    'Only a paused timer may resume.');
                DB::table('job_labour_timers')->where('id',$old->id)->update([
                    'status'=>'running','running_since'=>$now,
                    'lock_version'=>$old->lock_version+1,'updated_at'=>$now,
                ]);
            } else {
                abort_unless($old&&in_array($old->status,['running','paused'],true),
                    409,'No active timer to stop.');
                $elapsed=(int)$old->accumulated_seconds;
                if($old->status==='running')$elapsed+=max(0,$now->timestamp-
                    CarbonImmutable::parse($old->running_since,'UTC')->timestamp);
                abort_unless($elapsed>0&&$elapsed<=24*3600,409,
                    'Timer duration must be between 1 second and 24 hours.');
                $start=CarbonImmutable::parse($old->started_at,'UTC');
                abort_if($now->timestamp-$start->timestamp>24*3600,409,
                    'Timer older than 24 hours; admin correction required.');
                // Store billable time in integer minutes (half-up) once only.
                $minutes=max(1,intdiv($elapsed+30,60));
                $localStart=$start->setTimezone('Europe/Zurich');
                $localEnd=CarbonImmutable::instance($now)->setTimezone('Europe/Zurich');
                $startSql=$start->format('Y-m-d H:i:s');
                $endSql=$now->format('Y-m-d H:i:s');
                abort_if(DB::table('job_labour_entries')->where('company_id',$company->id)
                    ->where('worker_user_id',$worker)->where('status','!=','void')
                    ->where('work_started_at','<',$endSql)
                    ->where('work_ended_at','>',$startSql)->exists(),409,
                    'Another entry overlaps this timer; correct it before stopping.');
                $employee=User::query()->findOrFail($worker);
                $entryId=DB::table('job_labour_entries')->insertGetId([
                    'company_id'=>$company->id,'service_job_id'=>$old->service_job_id,
                    'worker_user_id'=>$worker,'worker_name_snapshot'=>$employee->name,
                    'created_by'=>$actor,'updated_by'=>$actor,
                    'role'=>$old->role,'task'=>$old->task,'kind'=>'time_sheet',
                    'source'=>'timer','work_date'=>$localStart->format('Y-m-d'),
                    'timezone'=>'Europe/Zurich',
                    'start_minutes'=>$localStart->hour*60+$localStart->minute,
                    'end_minutes'=>$localEnd->hour*60+$localEnd->minute,
                    'work_started_at'=>$startSql,'work_ended_at'=>$endSql,
                    'duration_minutes'=>$minutes,'hourly_rate_minor'=>$old->hourly_rate_minor,
                    'cost_minor'=>LabourService::cost($minutes,$old->hourly_rate_minor),
                    'currency'=>'CHF','status'=>'pending','lock_version'=>1,
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
                DB::table('job_labour_timers')->where('id',$old->id)->update([
                    'status'=>'stopped','running_since'=>null,
                    'accumulated_seconds'=>$elapsed,
                    'lock_version'=>$old->lock_version+1,'updated_at'=>$now,
                ]);
                DB::table('job_labour_events')->insert([
                    'company_id'=>$company->id,'job_labour_entry_id'=>$entryId,
                    'worker_user_id'=>$worker,'actor_user_id'=>$actor,
                    'event_type'=>'timer_stopped','metadata'=>json_encode([
                        'recorded_seconds'=>$elapsed,'rounded_minutes'=>$minutes,
                    ],JSON_THROW_ON_ERROR),'created_at'=>$now,
                ]);
            }
            $row=DB::table('job_labour_timers')->where('company_id',$company->id)
                ->where('worker_user_id',$worker)->firstOrFail();
            $answer=['timer'=>$this->snapshot($row)];
            if($entryId!==null)
                $answer['entry']=$this->labour->payload(
                    $this->labour->find($company->id,$entryId));
            DB::table('job_labour_timer_requests')->insert([
                'company_id'=>$company->id,'worker_user_id'=>$worker,
                'request_key'=>$data['request_key'],'action'=>$action,
                'response'=>json_encode($answer,JSON_THROW_ON_ERROR),
                'created_at'=>$now,
            ]);
            DB::table('job_labour_events')->insert([
                'company_id'=>$company->id,'job_labour_entry_id'=>null,
                'worker_user_id'=>$worker,'actor_user_id'=>$actor,
                'event_type'=>'timer_'.$action,
                'metadata'=>json_encode(['timer_id'=>$row->id,
                    'job_id'=>$row->service_job_id],JSON_THROW_ON_ERROR),
                'created_at'=>$now,
            ]);
            return $answer;
        });
        AuditLogger::record('mobile.labour.timer_'.$action,$request->user(),
            null,$company,['worker_id'=>$worker],$request);
        return $result;
    }

    public function active(?int $companyId=null): array
    {
        $q=DB::table('job_labour_timers as t')
            ->join('users as u','u.id','=','t.worker_user_id')
            ->join('service_jobs as j','j.id','=','t.service_job_id')
            ->whereColumn('t.company_id','j.company_id')
            ->whereIn('t.status',['running','paused'])
            ->select('t.*','u.name as worker_name','j.title as job_title');
        if($companyId!==null)$q->where('t.company_id',$companyId);
        return $q->orderByDesc('t.updated_at')->limit(200)->get()
            ->map(fn($row)=>array_merge($this->snapshot($row),[
                'company_id'=>(int)$row->company_id,
                'worker_name'=>$row->worker_name,'job'=>$row->job_title,
            ]))->all();
    }
}
