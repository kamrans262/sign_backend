<?php

namespace App\Services;

use App\Mail\EquipmentLoanReminderMail;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

final class EquipmentLoanReminderService
{
    public function __construct(private readonly EquipmentLoanService $loans) {}

    private function setting(int $companyId,int $loanId): ?object
    {
        return DB::table('equipment_loan_reminder_settings')
            ->where('company_id',$companyId)->where('equipment_loan_id',$loanId)
            ->first();
    }

    private function transportReady(): bool
    {
        return in_array((string) config('mail.default'),
            ['smtp','ses','mailgun','postmark','resend'],true)
            && filter_var((string) config('mail.from.address'),
                FILTER_VALIDATE_EMAIL)!==false;
    }

    private function event(int $company,int $loan,?int $actor,string $type,
        array $meta=[]): void
    {
        DB::table('equipment_loan_events')->insert([
            'company_id'=>$company,'equipment_loan_id'=>$loan,
            'actor_user_id'=>$actor,'event_type'=>$type,
            'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }

    public function history(Company $company,int $loanId): array
    {
        $this->loans->loan($company->id,$loanId);
        return DB::table('equipment_loan_reminders')
            ->where('company_id',$company->id)
            ->where('equipment_loan_id',$loanId)
            ->orderByDesc('id')->limit(100)->get()
            ->map(fn($r)=>[
                'id'=>(int)$r->id,'kind'=>$r->kind,
                'due_date'=>$r->due_date,
                'loan_version'=>(int)$r->loan_version,
                'status'=>$r->status,'requested_by'=>$r->requested_by,
                'attempt_count'=>(int)$r->attempt_count,
                'created_at'=>$r->created_at,
                'submitted_at'=>$r->submitted_at,
                'cancelled_at'=>$r->cancelled_at,
                'delivery_note'=>$r->status==='submitted'
                    ?'Submitted to outbound email transport; inbox delivery not confirmed.'
                    :($r->status==='unknown'
                        ?'Transport result uncertain. Investigate before any resend.'
                        :($r->status==='failed'
                            ?'Not submitted. Verify mail configuration.'
                            :($r->status==='cancelled'
                                ?'Cancelled before dispatch.':'Pending.'))),
            ])->all();
    }

    public function preview(Company $company,int $loanId): array
    {
        $loan=$this->loans->loan($company->id,$loanId);
        $setting=$this->setting($company->id,$loanId);
        $ready=$this->transportReady();
        $hasEmail=filter_var((string) $loan->borrower_email,
            FILTER_VALIDATE_EMAIL)!==false;
        return [
            'loan_id'=>(int)$loan->id,
            'loan_version'=>(int)$loan->lock_version,
            'status'=>$this->loans->loanPayload(
                $loan,$this->loans->companyDate($company))['status'],
            'borrower_name'=>$loan->borrower_name,
            'borrower_email'=>$loan->borrower_email,
            'equipment_name'=>DB::table('equipment_assets')
                ->where('id',$loan->equipment_asset_id)
                ->where('company_id',$company->id)->value('name'),
            'expected_return_date'=>$loan->expected_return_date,
            'transport_ready'=>$ready,
            'send_available'=>$loan->status==='active'&&$hasEmail&&$ready,
            'automatic_enabled'=>(bool)($setting?->automatic_enabled??false),
            'recipient_confirmed'=>(bool)($setting?->recipient_confirmed??false),
            'frequency_days'=>(int)($setting?->frequency_days??7),
            'automatic_global_enabled'=>(bool)config('equipment.automatic_enabled',false),
            'not_available_reason'=>!$hasEmail
                ?'Add a valid borrower email to the loan first.'
                :(!$ready
                    ?'Outbound email is not configured. No reminder was sent.'
                    :($loan->status!=='active'
                        ?'Returned loans cannot send reminders.':null)),
            'delivery_history'=>$this->history($company,$loanId),
        ];
    }

    public function policy(Request $request,Company $company,int $loanId): array
    {
        $data=$request->validate([
            'lock_version'=>['required','integer','min:1'],
            'automatic_enabled'=>['required','boolean'],
            'recipient_confirmed'=>['required','boolean'],
            'frequency_days'=>['required','integer','between:1,30'],
        ]);
        DB::transaction(function()use($request,$company,$loanId,$data):void {
            DB::table('companies')->where('id',$company->id)
                ->lockForUpdate()->firstOrFail();
            $loan=DB::table('equipment_loans')->where('id',$loanId)
                ->where('company_id',$company->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($loan->status==='active',409,
                'Returned equipment cannot have an automatic reminder policy.');
            abort_unless((int)$loan->lock_version===(int)$data['lock_version'],
                409,'Loan changed. Refresh before updating reminders.');
            if($data['automatic_enabled']){
                abort_unless($data['recipient_confirmed'],422,
                    'Confirm the recipient before enabling automatic email.');
                abort_unless(filter_var((string)$loan->borrower_email,
                    FILTER_VALIDATE_EMAIL)!==false,422,
                    'Borrower email is required for automatic reminders.');
            }
            $values=[
                'company_id'=>$company->id,'equipment_loan_id'=>$loanId,
                'automatic_enabled'=>(bool)$data['automatic_enabled'],
                'recipient_confirmed'=>(bool)$data['recipient_confirmed'],
                'frequency_days'=>(int)$data['frequency_days'],
                'updated_by'=>$request->user()?->id,
                'updated_at'=>now(),
            ];
            $current=$this->setting($company->id,$loanId);
            if($current){
                DB::table('equipment_loan_reminder_settings')
                    ->where('id',$current->id)->update($values);
            }else{
                $values['created_at']=now();
                DB::table('equipment_loan_reminder_settings')->insert($values);
            }
            DB::table('equipment_loans')->where('id',$loanId)
                ->update(['lock_version'=>$loan->lock_version+1,
                    'updated_at'=>now(),'updated_by'=>$request->user()?->id]);
            $this->cancelPending($company->id,$loanId,
                $request->user()?->id,'policy_changed');
            $this->event($company->id,$loanId,$request->user()?->id,
                'reminder_policy_updated',[
                    'automatic_enabled'=>(bool)$data['automatic_enabled'],
                    'recipient_confirmed'=>(bool)$data['recipient_confirmed'],
                    'frequency_days'=>(int)$data['frequency_days'],
                ]);
        },3);
        return $this->preview($company,$loanId);
    }

    /**
     * Call under an already locked company+loan transaction.
     * Pending only is cancellable; submitted mail is historical evidence.
     * Unknown results are never retried automatically.
     */
    public function cancelPending(int $companyId,int $loanId,
        ?int $actor,string $reason): int
    {
        $ids=DB::table('equipment_loan_reminders')
            ->where('company_id',$companyId)
            ->where('equipment_loan_id',$loanId)
            ->where('status','pending')
            ->pluck('id')->all();
        if($ids===[])return 0;
        DB::table('equipment_loan_reminders')->whereIn('id',$ids)
            ->update(['status'=>'cancelled','cancelled_at'=>now(),
                'updated_at'=>now()]);
        $this->event($companyId,$loanId,$actor,
            'reminders_cancelled',['count'=>count($ids),'reason'=>$reason]);
        return count($ids);
    }

    /** Email contact changes invalidate any earlier automatic authorization. */
    public function revokeConsentForContactChange(int $companyId,int $loanId,
        ?int $actor): void
    {
        DB::table('equipment_loan_reminder_settings')
            ->where('company_id',$companyId)
            ->where('equipment_loan_id',$loanId)
            ->update(['recipient_confirmed'=>false,
                'automatic_enabled'=>false,'updated_by'=>$actor,
                'updated_at'=>now()]);
        $this->cancelPending($companyId,$loanId,$actor,'borrower_contact_changed');
        $this->event($companyId,$loanId,$actor,
            'reminder_recipient_consent_revoked');
    }

    public function sendManual(Request $request,Company $company,int $id): array
    {
        $data=$request->validate([
            'lock_version'=>['required','integer','min:1'],
            'confirm'=>['required','accepted'],
            'recipient_confirmed'=>['required','accepted'],
            'request_key'=>['required','string','between:8,100',
                'regex:/^[A-Za-z0-9._-]+$/D'],
        ]);
        return $this->dispatch($company,$id,'manual',$data['request_key'],
            (int)$data['lock_version'],$request->user()?->id);
    }

    /**
     * Synchronous send under the loan row lock: a return, extension or email
     * change cannot complete while an email is being submitted. A provider
     * exception yields UNKNOWN rather than an unsafe automatic resend.
     * The outbound email contains no bearer links, internal IDs or secrets.
     */
    private function dispatch(Company $company,int $id,string $kind,
        string $key,int $version,?int $actor): array
    {
        return DB::transaction(function()use($company,$id,$kind,$key,$version,$actor):array {
            DB::table('companies')->where('id',$company->id)
                ->lockForUpdate()->firstOrFail();
            $loan=DB::table('equipment_loans')
                ->where('company_id',$company->id)->where('id',$id)
                ->lockForUpdate()->firstOrFail();
            $email=mb_strtolower(trim((string)$loan->borrower_email));
            $fingerprint=hash('sha256',json_encode([
                $id,$kind,$loan->expected_return_date,$version,$email,
            ],JSON_THROW_ON_ERROR));
            $previous=DB::table('equipment_loan_reminders')
                ->where('company_id',$company->id)
                ->where('request_key',$key)->first();
            if($previous){
                abort_unless(hash_equals((string)$previous->request_fingerprint,
                    $fingerprint),409,'Reminder retry key belongs to another request.');
                return $this->single($previous);
            }
            abort_unless($loan->status==='active',409,'Equipment has been returned.');
            abort_unless((int)$loan->lock_version===$version,409,
                'Loan changed; refresh reminder preview.');
            abort_unless(filter_var($email,FILTER_VALIDATE_EMAIL)!==false,422,
                'Borrower email is missing or invalid.');
            abort_unless($this->transportReady(),503,
                'A real outbound mail transport is required; no email sent.');
            if($kind==='automatic'){
                $settings=$this->setting($company->id,$id);
                abort_unless($settings && $settings->automatic_enabled
                    && $settings->recipient_confirmed
                    && config('equipment.automatic_enabled',false),409,
                    'Automatic borrower reminders are not enabled.');
                abort_unless($loan->expected_return_date<
                    $this->loans->companyDate($company),409,
                    'Loan is not overdue in its company timezone.');
            }
            $reminderId=DB::table('equipment_loan_reminders')->insertGetId([
                'company_id'=>$company->id,'equipment_loan_id'=>$id,
                'kind'=>$kind,'due_date'=>$loan->expected_return_date,
                'loan_version'=>$version,'recipient_email'=>$email,
                'request_key'=>$key,'request_fingerprint'=>$fingerprint,
                'status'=>'pending','attempt_count'=>0,'requested_by'=>$actor,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($company->id,$id,$actor,'reminder_requested',[
                'reminder_id'=>$reminderId,'kind'=>$kind,
                'due_date'=>$loan->expected_return_date,
            ]);
            $attemptId=DB::table('equipment_loan_delivery_attempts')->insertGetId([
                'company_id'=>$company->id,'equipment_loan_reminder_id'=>$reminderId,
                'attempt_number'=>1,'status'=>'submitting',
                'transport'=>(string)config('mail.default'),
                'started_at'=>now(),
            ]);
            DB::table('equipment_loan_reminders')->where('id',$reminderId)
                ->update(['status'=>'submitting','attempt_count'=>1,'updated_at'=>now()]);
            $status='submitted';
            $errorCode=null;
            try{
                $asset=DB::table('equipment_assets')
                    ->where('company_id',$company->id)
                    ->where('id',$loan->equipment_asset_id)->firstOrFail();
                Mail::to($email)->send(new EquipmentLoanReminderMail(
                    (string)$company->name,
                    (string)$loan->borrower_name,
                    (string)$asset->name,
                    (string)$asset->code,
                    (string)$loan->expected_return_date
                ));
            }catch(\Throwable $e){
                // SMTP timeouts can occur after acceptance. DO NOT auto-retry.
                $status='unknown';
                $errorCode='transport_outcome_uncertain';
                report($e);
            }
            DB::table('equipment_loan_delivery_attempts')->where('id',$attemptId)
                ->update(['status'=>$status,'error_code'=>$errorCode,
                    'finished_at'=>now()]);
            DB::table('equipment_loan_reminders')->where('id',$reminderId)
                ->update(['status'=>$status,'last_error_code'=>$errorCode,
                    'submitted_at'=>$status==='submitted'?now():null,
                    'updated_at'=>now()]);
            $this->event($company->id,$id,$actor,
                $status==='submitted'?'reminder_submitted':'reminder_unknown',[
                    'reminder_id'=>$reminderId,'kind'=>$kind,
                    'status'=>$status,
                ]);
            return $this->single(DB::table('equipment_loan_reminders')
                ->where('id',$reminderId)->firstOrFail());
        },3);
    }

    private function single(object $record): array
    {
        return [
            'id'=>(int)$record->id,
            'kind'=>$record->kind,
            'status'=>$record->status,
            'loan_version'=>(int)$record->loan_version,
            'due_date'=>$record->due_date,
            'attempt_count'=>(int)$record->attempt_count,
            'submitted_at'=>$record->submitted_at,
            'delivery_note'=>$record->status==='submitted'
                ?'Submitted to outbound transport. Inbox delivery is not confirmed.'
                :($record->status==='unknown'
                    ?'Outcome uncertain; investigate before attempting another send.'
                    :'No mail submission confirmed.'),
        ];
    }

    public function automaticCandidates(int $max=40): array
    {
        $rows=DB::table('equipment_loans as l')
            ->join('equipment_loan_reminder_settings as s',function($join):void {
                $join->on('s.equipment_loan_id','=','l.id')
                    ->on('s.company_id','=','l.company_id');
            })->join('companies as c','c.id','=','l.company_id')
            ->where('l.status','active')->where('c.status','active')
            ->where('s.automatic_enabled',true)
            ->where('s.recipient_confirmed',true)
            ->whereNotNull('l.borrower_email')->orderBy('l.id')
            ->limit(1000)->get(['l.id','l.company_id','l.expected_return_date',
                'l.lock_version','s.frequency_days','c.country_iso_code',
                'c.name','c.status']);
        $candidates=[];
        foreach($rows as $row){
            $company=new Company();
            $company->id=$row->company_id;
            $company->name=$row->name;
            $company->country_iso_code=$row->country_iso_code;
            $company->status=$row->status;
            $today=$this->loans->companyDate($company);
            if($row->expected_return_date >= $today)continue;
            $days=(int)Carbon::parse($row->expected_return_date)
                ->diffInDays(Carbon::parse($today));
            $frequency=max(1,(int)$row->frequency_days);
            $period=intdiv(max(0,$days-1),$frequency);
            $key='auto-'.$row->id.'-'.$row->expected_return_date.
                '-v'.$row->lock_version.'-p'.$period;
            if(DB::table('equipment_loan_reminders')
                ->where('company_id',$company->id)->where('request_key',$key)
                ->exists())continue;
            $candidates[]=['company_id'=>(int)$company->id,
                'loan_id'=>(int)$row->id,'version'=>(int)$row->lock_version,
                'request_key'=>$key,'date'=>$today,
                'period'=>$period];
            if(count($candidates)>=$max)break;
        }
        return $candidates;
    }

    public function processAutomatic(int $max=40,bool $send=false): array
    {
        $max=min(100,max(1,$max));
        $candidates=$this->automaticCandidates($max);
        $dispatched=0;$skipped=0;$uncertain=0;
        $enabled=(bool)config('equipment.automatic_enabled',false);
        if(!$send || !$enabled){
            return ['dry_run'=>true,'enabled'=>$enabled,
                'eligible_count'=>count($candidates),
                'submitted'=>0,'unknown'=>0,'skipped'=>0];
        }
        foreach($candidates as $candidate){
            $company=Company::query()->where('status','active')
                ->find($candidate['company_id']);
            if(!$company){$skipped++;continue;}
            try{
                $result=$this->dispatch($company,$candidate['loan_id'],
                    'automatic',$candidate['request_key'],$candidate['version'],null);
                if($result['status']==='submitted')$dispatched++;
                else $uncertain++;
            }catch(\Throwable $error){
                $skipped++;
                report($error);
            }
        }
        return ['dry_run'=>false,'enabled'=>true,
            'eligible_count'=>count($candidates),
            'submitted'=>$dispatched,'unknown'=>$uncertain,
            'skipped'=>$skipped];
    }
}
