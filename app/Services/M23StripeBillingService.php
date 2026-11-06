<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Company-owned checkout, Stripe Billing Portal and verified reconciliation.
 * All provider/network I/O is behind M23StripeGateway::guard(). Never accept
 * an amount, customer ID, account entitlement or payment status from Flutter.
 */
final class M23StripeBillingService
{
    public function __construct(
        private readonly M23StripeGateway $stripe,
        private readonly M23BillingService $billing
    ) {}

    public function owner(Company $company,User $actor): void
    {
        abort_unless((int)$company->owner_user_id===(int)$actor->id,403,
            'Only the company owner can manage subscription billing.');
    }

    private function plan(int $planId): object
    {
        return DB::table('m23_plans')->where('id',$planId)
            ->where('status','published')->firstOrFail();
    }

    private function checkedPrice(object $plan): void
    {
        abort_unless(is_string($plan->stripe_price_id)
            && $plan->stripe_price_id!=='',422,
            'The selected plan does not have an approved Stripe Price ID.');
        $remote=$this->stripe->price($plan->stripe_price_id);
        $interval=$plan->period==='yearly'?'year':'month';
        abort_unless(($remote['id']??null)===$plan->stripe_price_id
            && ($remote['active']??false)===true
            && ($remote['type']??null)==='recurring'
            && ($remote['currency']??null)===strtolower((string)$plan->currency)
            && (int)($remote['unit_amount']??-1)===(int)$plan->amount_minor
            && ($remote['recurring']['interval']??null)===$interval
            && (int)($remote['recurring']['interval_count']??0)===1,409,
            'Stripe Price does not match this Admin-approved plan.');
    }

    private function returnUrl(string $name): string
    {
        $url=(string)config('m23_billing.'.$name,'');
        $p=parse_url($url);
        abort_unless(is_array($p) && ($p['scheme']??'')==='https'
            && !empty($p['host']) && empty($p['user']) && empty($p['pass'])
            && empty($p['fragment']),503,
            'Secure Stripe return URL has not been configured.');
        return $url;
    }

    private function companyRecord(Company $company): ?object
    {
        return DB::table('m23_company_subscriptions')
            ->where('company_id',$company->id)->first();
    }

    private function customer(Company $company): string
    {
        $existing=$this->companyRecord($company);
        if($existing && $existing->stripe_customer_id)return $existing->stripe_customer_id;
        $customer=$this->stripe->request('POST','/customers',[
            'name'=>$company->name,
            'email'=>$company->email ?: $company->owner?->email,
            'metadata[company_id]'=>(string)$company->id,
        ],'m23-customer-'.$company->id);
        abort_unless(is_string($customer['id']??null)
            && preg_match('/^cus_[A-Za-z0-9]+$/',$customer['id']),503,
            'Unable to initialize a verified Stripe customer.');
        DB::table('m23_company_subscriptions')->updateOrInsert(
            ['company_id'=>$company->id],
            ['stripe_customer_id'=>$customer['id'],'status'=>'free',
                'created_at'=>$existing?->created_at??now(),'updated_at'=>now()]
        );
        return $customer['id'];
    }

    public function checkout(Company $company,User $actor,int $planId,string $requestId): array
    {
        $this->owner($company,$actor);
        $this->stripe->guard();
        $plan=$this->plan($planId);
        $this->checkedPrice($plan);
        return DB::transaction(function()use($company,$actor,$plan,$requestId):array {
            DB::table('companies')->where('id',$company->id)
                ->lockForUpdate()->firstOrFail();
            $sub=$this->companyRecord($company);
            abort_if($sub && in_array($sub->status,['active','trialing','past_due','unpaid'],true)
                && $sub->stripe_subscription_id,409,
                'A subscription already exists. Use Change Plan or Billing Portal.');
            $prior=DB::table('m23_checkout_intents')->where('company_id',$company->id)
                ->where('request_id',$requestId)->first();
            if($prior){
                abort_unless((int)$prior->plan_id===(int)$plan->id,409,
                    'Checkout retry token was already used for another plan.');
                if($prior->stripe_checkout_session_id &&
                    $prior->status==='pending' &&
                    $prior->expires_at && now()->lessThan($prior->expires_at)){
                    $verified=$this->stripe->checkout($prior->stripe_checkout_session_id);
                    abort_unless(($verified['id']??null)===$prior->stripe_checkout_session_id
                        && ($verified['customer']??null)===$sub?->stripe_customer_id
                        && ($verified['mode']??null)==='subscription',409,
                        'Checkout session identity mismatch.');
                    return ['intent_id'=>$requestId,
                        'checkout_url'=>$this->stripe->safeHostedUrl($verified['url']??null),
                        'status'=>'pending','charged'=>false];
                }
                abort(409,'This checkout request expired. Begin a new checkout.');
            }
            $customer=$this->customer($company);
            $id=DB::table('m23_checkout_intents')->insertGetId([
                'request_id'=>$requestId,'company_id'=>$company->id,
                'plan_id'=>$plan->id,'status'=>'pending',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $result=$this->stripe->request('POST','/checkout/sessions',[
                'mode'=>'subscription','customer'=>$customer,
                'line_items[0][price]'=>$plan->stripe_price_id,
                'line_items[0][quantity]'=>1,
                'client_reference_id'=>(string)$company->id,
                'metadata[company_id]'=>(string)$company->id,
                'metadata[intent_id]'=>$requestId,
                'subscription_data[metadata][company_id]'=>(string)$company->id,
                'success_url'=>$this->returnUrl('checkout_success_url'),
                'cancel_url'=>$this->returnUrl('checkout_cancel_url'),
            ],'m23-checkout-'.$company->id.'-'.$requestId);
            abort_unless(is_string($result['id']??null)
                && preg_match('/^cs_[A-Za-z0-9_]+$/',$result['id'])
                && ($result['customer']??null)===$customer
                && ($result['mode']??null)==='subscription',503,
                'Stripe did not confirm a matching Checkout Session.');
            $url=$this->stripe->safeHostedUrl($result['url']??null);
            $expires=isset($result['expires_at']) && is_numeric($result['expires_at'])
                ? CarbonImmutable::createFromTimestampUTC((int)$result['expires_at'])
                : now()->addMinutes(30);
            DB::table('m23_checkout_intents')->where('id',$id)->update([
                'stripe_checkout_session_id'=>$result['id'],
                'expires_at'=>$expires,'updated_at'=>now(),
            ]);
            $this->event($company->id,'checkout_created',[
                'intent_id'=>$requestId,'plan_id'=>$plan->id],$actor->id);
            return ['intent_id'=>$requestId,'checkout_url'=>$url,
                'status'=>'pending','charged'=>false];
        },2);
    }

    public function checkoutStatus(Company $company,string $requestId): array
    {
        $this->stripe->guard();
        $intent=DB::table('m23_checkout_intents')->where('company_id',$company->id)
            ->where('request_id',$requestId)->firstOrFail();
        if(!$intent->stripe_checkout_session_id)
            return ['status'=>'pending','verified'=>false];
        $session=$this->stripe->checkout($intent->stripe_checkout_session_id);
        abort_unless(($session['id']??null)===$intent->stripe_checkout_session_id
            && ($session['customer']??null)===$this->companyRecord($company)?->stripe_customer_id
            && ($session['client_reference_id']??null)===(string)$company->id
            && ($session['mode']??null)==='subscription',409,
            'Stripe checkout does not belong to this company.');
        if(($session['status']??null)!=='complete' ||
            !in_array($session['payment_status']??'',[
                'paid','no_payment_required'],true)){
            return ['status'=>($session['status']??'pending'),
                'verified'=>false];
        }
        $subscriptionId=$session['subscription']??null;
        abort_unless(is_string($subscriptionId),409,
            'Checkout has no subscription to verify.');
        $this->reconcile($company,$subscriptionId,$intent->plan_id);
        DB::table('m23_checkout_intents')->where('id',$intent->id)->update([
            'status'=>'confirmed','confirmed_at'=>now(),'updated_at'=>now(),
        ]);
        return ['status'=>$this->companyRecord($company)?->status??'pending',
            'verified'=>$this->billing->hasVerifiedPlan($this->companyRecord($company))];
    }

    public function portal(Company $company,User $actor): array
    {
        $this->owner($company,$actor);
        $this->stripe->guard();
        $sub=$this->companyRecord($company);
        abort_unless($sub && $sub->stripe_customer_id
            && $sub->stripe_subscription_id,409,
            'A verified subscription is required to manage billing.');
        $session=$this->stripe->request('POST','/billing_portal/sessions',[
            'customer'=>$sub->stripe_customer_id,
            'return_url'=>$this->returnUrl('portal_return_url'),
        ]);
        abort_unless(($session['customer']??null)===$sub->stripe_customer_id,503,
            'Billing Portal returned an unexpected customer.');
        return ['portal_url'=>$this->stripe->safeHostedUrl($session['url']??null,'portal')];
    }

    public function cancel(Company $company,User $actor,bool $resume=false): array
    {
        $this->owner($company,$actor);
        $this->stripe->guard();
        $sub=$this->companyRecord($company);
        abort_unless($sub && $sub->stripe_subscription_id,409,
            'No subscription exists for this company.');
        $this->stripe->request('POST','/subscriptions/'.$sub->stripe_subscription_id,[
            'cancel_at_period_end'=>$resume?'false':'true',
        ],'m23-'.($resume?'resume':'cancel').'-'.$company->id.'-'.$sub->version);
        $this->reconcile($company,$sub->stripe_subscription_id);
        $this->event($company->id,$resume?'cancel_resumed':'cancel_requested',[],
            $actor->id);
        return ['status'=>$this->companyRecord($company)->status,
            'cancel_at_period_end'=>(bool)$this->companyRecord($company)->cancel_at_period_end];
    }

    public function previewChange(Company $company,User $actor,int $planId): array
    {
        $this->owner($company,$actor);
        $this->stripe->guard();
        $plan=$this->plan($planId);
        $this->checkedPrice($plan);
        $sub=$this->companyRecord($company);
        abort_unless($sub && $sub->stripe_subscription_id
            && $sub->stripe_subscription_item_id
            && $sub->stripe_customer_id,409,'Current billing details are unavailable.');
        $data=$this->stripe->request('GET','/invoices/create_preview',[
            'customer'=>$sub->stripe_customer_id,
            'subscription'=>$sub->stripe_subscription_id,
            'subscription_details[items][0][id]'=>$sub->stripe_subscription_item_id,
            'subscription_details[items][0][price]'=>$plan->stripe_price_id,
            'subscription_details[proration_behavior]'=>'none',
        ]);
        return ['new_plan_id'=>$plan->id,'currency'=>$data['currency']??null,
            'amount_due_minor'=>$data['amount_due']??null,
            'proration_behavior'=>'none','confirmed'=>false];
    }

    public function changePlan(Company $company,User $actor,int $planId,string $requestId): array
    {
        $this->owner($company,$actor);
        $this->stripe->guard();
        $plan=$this->plan($planId);
        $this->checkedPrice($plan);
        $sub=$this->companyRecord($company);
        abort_unless($sub && $sub->stripe_subscription_id
            && $sub->stripe_subscription_item_id,409,
            'A verified current Stripe subscription is required.');
        $this->stripe->request('POST','/subscriptions/'.$sub->stripe_subscription_id,[
            'items[0][id]'=>$sub->stripe_subscription_item_id,
            'items[0][price]'=>$plan->stripe_price_id,
            'proration_behavior'=>'none',
            'payment_behavior'=>'pending_if_incomplete',
        ],'m23-change-'.$company->id.'-'.$requestId);
        $this->reconcile($company,$sub->stripe_subscription_id);
        $this->event($company->id,'plan_change_requested',
            ['plan_id'=>$planId,'proration'=>'none'],$actor->id);
        return ['status'=>$this->companyRecord($company)->status,
            'plan_id'=>$this->companyRecord($company)->plan_id];
    }

    /**
     * Always fetch canonical Stripe state. Never grant paid access from
     * session redirects or unordered webhook snapshots.
     */
    public function reconcile(Company $company,string $subscriptionId,?int $checkoutPlanId=null): void
    {
        $remote=$this->stripe->subscription($subscriptionId);
        $sub=$this->companyRecord($company);
        abort_unless($sub && $sub->stripe_customer_id
            && ($remote['id']??null)===$subscriptionId
            && ($remote['customer']??null)===$sub->stripe_customer_id
            && (bool)($remote['livemode']??!config('m23_billing.live_mode'))
                ===(bool)config('m23_billing.live_mode'),409,
            'Provider subscription does not match the billing company or mode.');
        abort_unless(!$sub->stripe_subscription_id ||
            $sub->stripe_subscription_id===$subscriptionId,409,
            'A different subscription is already assigned to this company.');
        $items=$remote['items']['data']??[];
        abort_unless(is_array($items) && count($items)===1
            && is_array($items[0]),409,
            'Unsupported Stripe subscription item structure.');
        $item=$items[0];
        $priceId=is_array($item['price']??null)
            ? ($item['price']['id']??null) : ($item['price']??null);
        $plan=DB::table('m23_plans')->where('stripe_price_id',$priceId)->first();
        abort_unless($plan && (!$checkoutPlanId || (int)$plan->id===$checkoutPlanId),
            409,'Subscription Price is not an approved company plan.');
        $begin=$item['current_period_start']??$remote['current_period_start']??null;
        $end=$item['current_period_end']??$remote['current_period_end']??null;
        abort_unless(is_numeric($begin) && is_numeric($end) &&
            (int)$end>(int)$begin && $end<time()+400*86400,409,
            'Provider billing cycle could not be verified.');
        $allowed=['trialing','active','past_due','unpaid','paused',
            'incomplete','incomplete_expired','canceled'];
        $status=(string)($remote['status']??'');
        abort_unless(in_array($status,$allowed,true),409,
            'Unrecognized provider subscription status.');
        DB::transaction(function()use($company,$sub,$remote,$plan,$item,
            $begin,$end,$status,$subscriptionId):void {
            DB::table('companies')->where('id',$company->id)
                ->lockForUpdate()->firstOrFail();
            DB::table('m23_company_subscriptions')
                ->where('company_id',$company->id)->update([
                    'plan_id'=>$plan->id,'status'=>$status,
                    'stripe_subscription_id'=>$subscriptionId,
                    'stripe_subscription_item_id'=>$item['id']??null,
                    'period_starts_at'=>CarbonImmutable::createFromTimestampUTC((int)$begin),
                    'period_ends_at'=>CarbonImmutable::createFromTimestampUTC((int)$end),
                    'trial_ends_at'=>isset($remote['trial_end']) && $remote['trial_end']
                        ? CarbonImmutable::createFromTimestampUTC((int)$remote['trial_end']) : null,
                    'cancel_at_period_end'=>(bool)($remote['cancel_at_period_end']??false),
                    'cancel_at'=>isset($remote['cancel_at']) && $remote['cancel_at']
                        ? CarbonImmutable::createFromTimestampUTC((int)$remote['cancel_at']) : null,
                    'canceled_at'=>isset($remote['canceled_at']) && $remote['canceled_at']
                        ? CarbonImmutable::createFromTimestampUTC((int)$remote['canceled_at']) : null,
                    'provider_synced_at'=>now(),
                    'version'=>(int)$sub->version+1,'updated_at'=>now(),
                ]);
        });
    }

    public function recordInvoice(array $invoice): void
    {
        $id=$invoice['id']??null;
        $customer=$invoice['customer']??null;
        abort_unless(is_string($id) && preg_match('/^in_[A-Za-z0-9]+$/',$id)
            && is_string($customer),409,'Invalid Stripe invoice identity.');
        $companySub=DB::table('m23_company_subscriptions')
            ->where('stripe_customer_id',$customer)->first();
        abort_unless($companySub && $companySub->stripe_subscription_id,409,
            'Invoice belongs to no verified company subscription.');
        $subscription=$invoice['subscription']??
            ($invoice['parent']['subscription_details']['subscription']??null);
        abort_unless($subscription===$companySub->stripe_subscription_id,409,
            'Invoice subscription mapping is invalid.');
        $currency=(string)($invoice['currency']??'');
        $due=$invoice['amount_due']??null;
        $paid=$invoice['amount_paid']??null;
        abort_unless($currency==='chf' && is_int($due) && $due>=0 &&
            is_int($paid) && $paid>=0 && $paid<=$due,409,
            'Invalid billing invoice currency or amount.');
        DB::table('m23_billing_invoices')->updateOrInsert(
            ['stripe_invoice_id'=>$id],[
                'company_id'=>$companySub->company_id,
                'status'=>(string)($invoice['status']??'open'),
                'currency'=>strtoupper($currency),
                'amount_due_minor'=>$due,'amount_paid_minor'=>$paid,
                'issued_at'=>isset($invoice['created'])
                    ? CarbonImmutable::createFromTimestampUTC((int)$invoice['created'])
                    : null,
                'paid_at'=>isset($invoice['status_transitions']['paid_at'])
                    && $invoice['status_transitions']['paid_at']
                    ? CarbonImmutable::createFromTimestampUTC(
                        (int)$invoice['status_transitions']['paid_at']) : null,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
    }

    public function webhook(array $event): array
    {
        $id=$event['id'];
        $type=$event['type'];
        $object=$event['data']['object'];
        $existing=DB::table('m23_provider_events')
            ->where('provider_event_id',$id)->first();
        if($existing && $existing->status==='processed')
            return ['received'=>true,'duplicate'=>true];
        DB::table('m23_provider_events')->updateOrInsert([
            'provider_event_id'=>$id],[
                'event_type'=>$type,'status'=>'pending','error_code'=>null,
                'received_at'=>now(),
                'attempts'=>(int)($existing?->attempts??0)+1,
                'provider_created_at'=>isset($event['created'])
                    ? CarbonImmutable::createFromTimestampUTC((int)$event['created']) : null,
                'created_at'=>$existing?->created_at??now(),'updated_at'=>now(),
            ]);
        try {
            $company=null;
            if($type==='checkout.session.completed'){
                $sessionId=$object['id']??'';
                $intent=DB::table('m23_checkout_intents')
                    ->where('stripe_checkout_session_id',$sessionId)->first();
                abort_unless($intent,409,'Checkout session has no matching tenant intent.');
                $company=Company::findOrFail($intent->company_id);
                $this->checkoutStatus($company,$intent->request_id);
            }elseif(str_starts_with($type,'customer.subscription.')){
                $customer=$object['customer']??'';
                $sub=DB::table('m23_company_subscriptions')
                    ->where('stripe_customer_id',$customer)->first();
                abort_unless($sub,409,'Unknown customer subscription.');
                $company=Company::findOrFail($sub->company_id);
                $idOfSubscription=$object['id']??null;
                abort_unless(is_string($idOfSubscription),409,
                    'Missing subscription reference.');
                $this->reconcile($company,$idOfSubscription);
            }elseif(in_array($type,['invoice.paid','invoice.payment_failed',
                'invoice.payment_succeeded','invoice.finalized'],true)){
                $invoice=$this->stripe->invoice((string)($object['id']??''));
                $this->recordInvoice($invoice);
            }
            DB::table('m23_provider_events')->where('provider_event_id',$id)
                ->update(['status'=>'processed','processed_at'=>now(),
                    'error_code'=>null,'updated_at'=>now()]);
            return ['received'=>true,'duplicate'=>false];
        }catch(\Throwable $e){
            DB::table('m23_provider_events')->where('provider_event_id',$id)
                ->update(['status'=>'failed','error_code'=>'reconciliation_failed',
                    'updated_at'=>now()]);
            throw $e; // Stripe retries; no false 2xx acknowledgement.
        }
    }

    private function event(int $companyId,string $type,array $metadata=[],?int $actorId=null): void
    {
        DB::table('m23_subscription_events')->insert([
            'company_id'=>$companyId,'actor_user_id'=>$actorId,
            'event_type'=>$type,
            'metadata'=>json_encode($metadata,JSON_THROW_ON_ERROR),
            'created_at'=>now(),
        ]);
    }
}
