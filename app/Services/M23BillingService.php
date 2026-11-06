<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * M23 single source of truth for tenant entitlements.
 *
 * Migrations are additive and enforcement is OFF until a separate,
 * authorized rollout. All document issuance paths can record usage safely,
 * even when Stripe has not been configured.
 */
final class M23BillingService
{
    public const FEATURES = [
        'unlimitedProjects', 'aiDocuments', 'eSignature', 'invoices',
        'labour', 'equipment', 'reminders', 'emailSupport',
    ];
    public const ISSUE_TYPES = ['quote','invoice','report','general_document'];

    public function settings(): object
    {
        return DB::table('m23_billing_settings')->where('id',1)->firstOrFail();
    }

    public function subscription(int $companyId): ?object
    {
        return DB::table('m23_company_subscriptions')
            ->where('company_id',$companyId)->first();
    }

    public function grant(int $companyId): ?object
    {
        return DB::table('m23_subscription_grants')
            ->where('company_id',$companyId)
            ->where('starts_at','<=',now())
            ->where('expires_at','>',now())
            ->orderByDesc('expires_at')->first();
    }

    public function hasVerifiedPlan(?object $subscription): bool
    {
        // A locally-entered provider ID or an obsolete period is not a
        // verified subscription. Stage 2 will populate these fields only
        // after signed provider events and authoritative reconciliation.
        return $subscription !== null
            && in_array($subscription->status,['active','trialing'],true)
            && !empty($subscription->stripe_subscription_id)
            && !empty($subscription->stripe_customer_id)
            && $subscription->provider_synced_at !== null
            && $subscription->period_starts_at !== null
            && $subscription->period_ends_at !== null
            && now()->greaterThanOrEqualTo($subscription->period_starts_at)
            && now()->lessThan($subscription->period_ends_at)
            && ($subscription->status !== 'trialing'
                || $subscription->trial_ends_at === null
                || now()->lessThan($subscription->trial_ends_at));
    }

    public function used(int $companyId): int
    {
        return DB::table('m23_document_usage')->where('company_id',$companyId)->count();
    }

    public function allowance(int $companyId): array
    {
        $settings=$this->settings();
        $used=$this->used($companyId);
        $subscription=$this->subscription($companyId);
        $grant=$this->grant($companyId);
        $verified=$this->hasVerifiedPlan($subscription);
        $limit=(int)$settings->free_document_limit;
        $paidLimit=null;
        $paidUsed=null;
        $paidEligible=$verified;
        if($verified){
            // Every plan quota is scoped to the VERIFIED subscription cycle;
            // free-document usage is a separate lifetime entitlement.
            $plan=$subscription->plan_id
                ? DB::table('m23_plans')->where('id',$subscription->plan_id)->first()
                : null;
            $paidEligible=$plan !== null;
            if($plan && $plan->document_limit !== null){
                $paidLimit=(int)$plan->document_limit;
                $paidUsed=DB::table('m23_document_usage')
                    ->where('company_id',$companyId)
                    ->where('issued_at','>=',$subscription->period_starts_at)
                    ->where('issued_at','<',$subscription->period_ends_at)
                    ->count();
                $paidEligible=$paidUsed<$paidLimit;
            }
        }
        $hasGrant=$grant !== null;
        $withinQuota=$verified ? $paidEligible : ($used<$limit);
        $eligible=!(bool)$settings->enforcement_enabled || $hasGrant || $withinQuota;
        return [
            'limit'=>$limit,
            'used'=>$used,
            'remaining'=>max(0,$limit-$used),
            'paid_period_limit'=>$paidLimit,
            'paid_period_used'=>$paidUsed,
            'paid_period_remaining'=>$paidLimit === null || $paidUsed === null
                ? null : max(0,$paidLimit-$paidUsed),
            'paid_plan_quota_exhausted'=>$verified && !$paidEligible,
            'enforcement_enabled'=>(bool)$settings->enforcement_enabled,
            'stripe_enabled'=>app(M23StripeGateway::class)->enabled(),
            'has_verified_subscription'=>$verified,
            'has_active_grant'=>$hasGrant,
            'eligible'=>$eligible,
            'subscription_required'=>!$eligible,
        ];
    }

    public function eligibility(Company $company): array
    {
        return $this->allowance((int)$company->id);
    }

    /**
     * Invoke only inside the same DB transaction as a REAL first issuance.
     * The company lock serializes writers across document types. One source
     * document is charged at most once, even across revision/PDF retries.
     */
    public function recordIssue(int $companyId,string $type,int $sourceId,?int $actorId): void
    {
        if(!in_array($type,self::ISSUE_TYPES,true) || $sourceId<1){
            throw ValidationException::withMessages(['document'=>'Invalid document usage source.']);
        }
        // The enclosing issuance transaction is mandatory; nested transactions
        // are supported. Without an outer transaction records are still atomic.
        DB::transaction(function() use($companyId,$type,$sourceId,$actorId):void {
            DB::table('companies')->where('id',$companyId)->lockForUpdate()->firstOrFail();
            $exists=DB::table('m23_document_usage')
                ->where('company_id',$companyId)->where('source_type',$type)
                ->where('source_id',$sourceId)->where('issue_event','first_issuance')->exists();
            if($exists)return;
            $policy=$this->allowance($companyId);
            if($policy['subscription_required']){
                throw new HttpResponseException(response()->json([
                    'message'=>'Subscription required to issue another document.',
                    'code'=>'subscription_required',
                    'data'=>['allowance'=>$policy],
                ],402,['Cache-Control'=>'private, no-store']));
            }
            DB::table('m23_document_usage')->insert([
                'company_id'=>$companyId,'source_type'=>$type,
                'source_id'=>$sourceId,'issue_event'=>'first_issuance',
                'actor_user_id'=>$actorId,
                'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
        },3);
    }

    public function plans(): array
    {
        return DB::table('m23_plans')->where('status','published')
            ->orderBy('code')->orderByRaw("case when period = 'monthly' then 0 else 1 end")
            ->get()->map(fn($p)=>$this->plan($p))->all();
    }

    public function plan(object $plan): array
    {
        $features=json_decode((string)$plan->features,true);
        if(!is_array($features))$features=[];
        $labels=[
            'unlimitedProjects'=>'Unlimited projects',
            'aiDocuments'=>'AI document generation',
            'eSignature'=>'eSignature & work acceptance',
            'invoices'=>'Invoices & deposits',
            'labour'=>'Labour & time tracking',
            'equipment'=>'Equipment management',
            'reminders'=>'Reminder & advanced search',
            'emailSupport'=>'Email support',
        ];
        $precision=2; // CHF currently the sole configured currency.
        $amount=number_format(((int)$plan->amount_minor)/(10**$precision),2,'.',"'");
        return [
            'id'=>(int)$plan->id,'code'=>(string)$plan->code,
            'name'=>(string)$plan->name,'period'=>(string)$plan->period,
            'currency'=>(string)$plan->currency,'amount_minor'=>(int)$plan->amount_minor,
            'price_label'=>$plan->currency.' '.$amount.
                ($plan->period==='yearly'?' / year':' / month'),
            'description'=>(string)($plan->description??''),
            'features'=>array_values(array_intersect(self::FEATURES,$features)),
            'feature_labels'=>array_values(array_map(
                fn($f)=>$labels[$f],array_intersect(self::FEATURES,$features))),
            'document_limit'=>$plan->document_limit===null?null:(int)$plan->document_limit,
            'can_checkout'=>app(M23StripeGateway::class)->enabled()
                && !empty($plan->stripe_price_id),
            'revision'=>(int)$plan->revision,
        ];
    }

    public function overview(Company $company): array
    {
        $companyId=(int)$company->id;
        $policy=$this->allowance($companyId);
        $subscription=$this->subscription($companyId);
        $verified=$this->hasVerifiedPlan($subscription);
        $row=$subscription && $subscription->plan_id
            ? DB::table('m23_plans')->where('id',$subscription->plan_id)->first()
            : null;
        $plan=$verified && $row ? $this->plan($row) : null;
        $granted=$this->grant($companyId);
        $clients=DB::table('clients')->where('company_id',$companyId)->count();
        $equipment=Schema::hasTable('equipment_assets')
            ? DB::table('equipment_assets')->where('company_id',$companyId)->count() : null;
        return [
            'company_id'=>$companyId,
            'plan_name'=>$plan['name']??($granted?'Administrative access grant':'Free allowance'),
            'status'=>$verified?(string)$subscription->status:($granted?'granted':'free'),
            'price_label'=>$plan['price_label']??null,
            'billing_description'=>$plan && $subscription?(
                'Current period ends '.($subscription->period_ends_at??'not provided')
            ):'No paid subscription is connected.',
            'features'=>$plan['features']??null,
            'usage'=>[
                'documents'=>$policy['used'],
                'clients'=>$clients,
                'equipment'=>$equipment,
            ],
            'allowance'=>$policy,
            'current_plan'=>$plan,
            'trial_ends_at'=>$subscription?->trial_ends_at,
            'period_ends_at'=>$subscription?->period_ends_at,
            'can_manage_billing'=>$verified && app(M23StripeGateway::class)->enabled(),
            'can_checkout'=>!$verified && app(M23StripeGateway::class)->enabled(),
            'demo_only'=>false,
        ];
    }

    public function usage(Company $company): array
    {
        $id=(int)$company->id;
        $allowance=$this->allowance($id);
        return [
            'company_id'=>$id, 'allowance'=>$allowance,
            'by_type'=>DB::table('m23_document_usage')->where('company_id',$id)
                ->select('source_type')->selectRaw('count(*) as total')
                ->groupBy('source_type')->pluck('total','source_type')->all(),
            'recent'=>DB::table('m23_document_usage')->where('company_id',$id)
                ->orderByDesc('id')->limit(50)
                ->get(['source_type','source_id','issued_at'])->all(),
        ];
    }

    public function history(Company $company): array
    {
        return [
            'invoices'=>DB::table('m23_billing_invoices')
                ->where('company_id',$company->id)->orderByDesc('id')->limit(100)
                ->get(['id','status','currency','amount_due_minor','amount_paid_minor',
                    'issued_at','paid_at'])->all(),
            'events'=>DB::table('m23_subscription_events')
                ->where('company_id',$company->id)->orderByDesc('id')->limit(100)
                ->get(['event_type','created_at'])->all(),
            'provider_connected'=>app(M23StripeGateway::class)->enabled(),
        ];
    }
}
