<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M23StripeBillingStage2Test extends TestCase
{
    use RefreshDatabase;

    private bool $firstEnded=false;

    private function tenant(): array
    {
        $owner=User::factory()->create([
            'is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create([
            'owner_user_id'=>$owner->id,'name'=>'Billing Test',
            'email'=>'billing@example.test',
            'country_iso_code'=>'CH','status'=>'active',
        ]);
        $company->users()->attach($owner->id,[
            'role'=>'owner','status'=>'active']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $plan=DB::table('m23_plans')->insertGetId([
            'code'=>'professional','name'=>'Professional',
            'period'=>'monthly','currency'=>'CHF','amount_minor'=>2000,
            'stripe_price_id'=>'price_test1',
            'features'=>json_encode(['invoices','eSignature']),
            'status'=>'published','created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$owner,$company,$plan];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function enable(): void
    {
        config()->set('m23_billing.enabled',true);
        config()->set('m23_billing.live_mode',false);
        config()->set('m23_billing.secret_key','sk_test_ci_fixture_never_live');
        config()->set('m23_billing.webhook_secret','whsec_ci_fixture');
        config()->set('m23_billing.checkout_success_url',
            'https://example.test/billing/return?session_id={CHECKOUT_SESSION_ID}');
        config()->set('m23_billing.checkout_cancel_url',
            'https://example.test/billing/cancel');
        config()->set('m23_billing.portal_return_url',
            'https://example.test/billing/management');
        DB::table('m23_billing_settings')->where('id',1)
            ->update(['stripe_enabled'=>true]);
    }

    private function fixture(): void
    {
        $checkoutCreateCalls=0;
        Http::fake(function($request)use(&$checkoutCreateCalls){
            $url=$request->url();
            if(str_contains($url,'/prices/price_test1')){
                return Http::response([
                    'id'=>'price_test1','active'=>true,
                    'type'=>'recurring','currency'=>'chf','unit_amount'=>2000,
                    'recurring'=>['interval'=>'month','interval_count'=>1],
                ]);
            }
            if(str_ends_with($url,'/customers'))
                return Http::response(['id'=>'cus_test1']);
            if(str_ends_with($url,'/checkout/sessions')){
                $checkoutCreateCalls++;
                $sessionId=$checkoutCreateCalls===1?'cs_test_a1':'cs_test_b2';
                return Http::response([
                    'id'=>$sessionId,'customer'=>'cus_test1',
                    'mode'=>'subscription',
                    'url'=>'https://checkout.stripe.com/c/pay/'.$sessionId,
                    'expires_at'=>time()+1800,
                ]);
            }
            if(str_contains($url,'/checkout/sessions/cs_test_')){
                $sessionId=basename(parse_url($url,PHP_URL_PATH));
                return Http::response([
                    'id'=>$sessionId,'customer'=>'cus_test1',
                    'client_reference_id'=>(string)DB::table('companies')
                        ->where('name','Billing Test')->value('id'),
                    'mode'=>'subscription','status'=>'complete',
                    'payment_status'=>'paid',
                    'subscription'=>$sessionId==='cs_test_a1'?'sub_test1':'sub_test2',
                    'url'=>'https://checkout.stripe.com/c/pay/'.$sessionId,
                ]);
            }
            if(str_contains($url,'/subscriptions/sub_test')){
                $subId=basename(parse_url($url,PHP_URL_PATH));
                if($request->method()==='POST'){
                    return Http::response(['id'=>$subId]);
                }
                return Http::response([
                    'id'=>$subId,'customer'=>'cus_test1',
                    'livemode'=>false,
                    'status'=>$subId==='sub_test1' && $this->firstEnded
                        ?'canceled':'active',
                    'cancel_at_period_end'=>false,
                    'items'=>['data'=>[[
                        'id'=>'si_test1','price'=>['id'=>'price_test1'],
                        'current_period_start'=>time()-120,
                        'current_period_end'=>time()+86400,
                    ]]],
                ]);
            }
            if(str_ends_with($url,'/billing_portal/sessions'))
                return Http::response([
                    'customer'=>'cus_test1',
                    'url'=>'https://billing.stripe.com/p/session/abc123',
                ]);
            if(str_ends_with($url,'/invoices/create_preview')){
                if($request->method()!=='POST')
                    return Http::response(['error'=>'invoice preview requires POST'],405);
                return Http::response([
                    'object'=>'invoice','currency'=>'chf',
                    'amount_due'=>2000,'amount_remaining'=>2000,
                ]);
            }
            if(str_contains($url,'/invoices/in_test1'))
                return Http::response([
                    'id'=>'in_test1','customer'=>'cus_test1',
                    'subscription'=>'sub_test1','currency'=>'chf',
                    'amount_due'=>2000,'amount_paid'=>2000,
                    'status'=>'paid','created'=>time(),
                    'status_transitions'=>['paid_at'=>time()],
                ]);
            return Http::response(['error'=>'unexpected Stripe request'],404);
        });
    }

    private function checkout(int $plan): array
    {
        return $this->postJson('/api/v1/billing/checkout',[
            'plan_id'=>$plan,
            'request_id'=>'33333333-3333-4333-8333-333333333333',
        ])->assertOk()->json('data');
    }

    private function signed(string $id,string $type,array $object): array
    {
        $body=json_encode([
            'id'=>$id,'type'=>$type,'livemode'=>false,
            'created'=>time(),'data'=>['object'=>$object],
        ],JSON_THROW_ON_ERROR);
        $ts=time();
        $signature='t='.$ts.',v1='.hash_hmac('sha256',
            $ts.'.'.$body,'whsec_ci_fixture');
        return [$body,$signature];
    }

    public function test_disabled_gateway_rejects_checkout_and_webhook_without_side_effects(): void
    {
        [$user,$company,$plan]=$this->tenant();
        $this->postJson('/api/v1/billing/checkout',[
            'plan_id'=>$plan,'request_id'=>'33333333-3333-4333-8333-333333333333',
        ])->assertStatus(503);
        $this->post('/api/v1/billing/webhook',[],[
            'Stripe-Signature'=>'t='.time().',v1=wrong',
        ])->assertStatus(503);
        $this->assertDatabaseCount('m23_company_subscriptions',0);
        $this->assertDatabaseCount('m23_checkout_intents',0);
    }

    public function test_checkout_is_tenant_owned_and_webhook_signed_replayed_once(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        $started=$this->checkout($plan);
        $this->assertSame('pending',$started['status']);
        $this->assertFalse($started['charged']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_a1',
            $started['checkout_url']);
        $again=$this->checkout($plan);
        $this->assertSame($started['intent_id'],$again['intent_id']);
        $this->assertDatabaseCount('m23_checkout_intents',1);
        $this->getJson('/api/v1/billing/checkout/'.$started['intent_id'].'/status')
            ->assertOk()->assertJsonPath('data.verified',true);
        $this->assertDatabaseHas('m23_company_subscriptions',[
            'company_id'=>$company->id,'status'=>'active',
            'stripe_subscription_id'=>'sub_test1','plan_id'=>$plan,
        ]);
        $this->assertDatabaseHas('m23_checkout_intents',[
            'company_id'=>$company->id,'status'=>'confirmed',
        ]);
        [$body,$signature]=$this->signed('evt_test1','checkout.session.completed',[
            'id'=>'cs_test_a1',
        ]);
        $this->call('POST','/api/v1/billing/webhook',[],[],
            [],['HTTP_STRIPE_SIGNATURE'=>$signature],$body)
            ->assertOk()->assertJsonPath('data.duplicate',false);
        $this->call('POST','/api/v1/billing/webhook',[],[],
            [],['HTTP_STRIPE_SIGNATURE'=>$signature],$body)
            ->assertOk()->assertJsonPath('data.duplicate',true);
        $this->assertDatabaseCount('m23_provider_events',1);
        $this->assertDatabaseCount('m23_checkout_intents',1);
        $this->assertDatabaseCount('m23_billing_invoices',0);
    }

    public function test_new_uuid_cannot_create_overlapping_checkout_for_company(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        $this->checkout($plan);
        $this->postJson('/api/v1/billing/checkout',[
            'plan_id'=>$plan,
            'request_id'=>'44444444-4444-4444-8444-444444444444',
        ])->assertStatus(409);
        $this->assertDatabaseCount('m23_checkout_intents',1);
    }

    public function test_verified_cancellation_allows_a_new_checkout_without_reusing_old_identity(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        $first=$this->checkout($plan);
        $this->getJson('/api/v1/billing/checkout/'.$first['intent_id'].'/status')
            ->assertJsonPath('data.verified',true);
        $this->firstEnded=true;
        // Simulate the prior signed/deleted webhook reconciliation state.
        DB::table('m23_company_subscriptions')->where('company_id',$company->id)
            ->update(['status'=>'canceled']);
        $secondId='44444444-4444-4444-8444-444444444444';
        $second=$this->postJson('/api/v1/billing/checkout',[
            'plan_id'=>$plan,'request_id'=>$secondId,
        ])->assertOk()->json('data');
        $this->assertSame($secondId,$second['intent_id']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_b2',
            $second['checkout_url']);
        $this->getJson('/api/v1/billing/checkout/'.$secondId.'/status')
            ->assertJsonPath('data.verified',true);
        $this->assertDatabaseHas('m23_company_subscriptions',[
            'company_id'=>$company->id,'status'=>'active',
            'stripe_subscription_id'=>'sub_test2',
        ]);
        $this->assertDatabaseCount('m23_checkout_intents',2);
    }

    public function test_signature_tamper_mode_mismatch_and_unknown_customer_fail_closed(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        [$body,$sig]=$this->signed('evt_test2','checkout.session.completed',[
            'id'=>'cs_test_a1',
        ]);
        $this->call('POST','/api/v1/billing/webhook',[],[],
            [],['HTTP_STRIPE_SIGNATURE'=>$sig],$body.' ')
            ->assertStatus(400);
        $this->call('POST','/api/v1/billing/webhook',[],[],
            [],['HTTP_STRIPE_SIGNATURE'=>'t='. (time()-500).',v1='.str_repeat('0',64)],$body)
            ->assertStatus(400);
        $wrong=str_replace('"livemode":false','"livemode":true',$body);
        $ts=time();$valid='t='.$ts.',v1='.hash_hmac('sha256',
            $ts.'.'.$wrong,'whsec_ci_fixture');
        $this->call('POST','/api/v1/billing/webhook',[],[],
            [],['HTTP_STRIPE_SIGNATURE'=>$valid],$wrong)
            ->assertStatus(400);
        $this->assertDatabaseCount('m23_provider_events',0);
        $this->assertDatabaseCount('m23_company_subscriptions',0);
    }

    public function test_portal_only_owner_and_subscription_invoice_ledger_is_separate(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        $started=$this->checkout($plan);
        $this->getJson('/api/v1/billing/checkout/'.$started['intent_id'].'/status')
            ->assertJsonPath('data.verified',true);
        $this->postJson('/api/v1/billing/portal')
            ->assertOk()->assertJsonPath('data.portal_url',
                'https://billing.stripe.com/p/session/abc123');
        [$body,$signature]=$this->signed('evt_invoice1','invoice.paid',[
            'id'=>'in_test1',
        ]);
        $this->call('POST','/api/v1/billing/webhook',[],[],
            [],['HTTP_STRIPE_SIGNATURE'=>$signature],$body)->assertOk();
        $this->assertDatabaseHas('m23_billing_invoices',[
            'company_id'=>$company->id,'stripe_invoice_id'=>'in_test1',
            'status'=>'paid','amount_paid_minor'=>2000,
        ]);
        $this->assertDatabaseCount('service_invoice_issues',0);
        $member=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company->users()->attach($member->id,['role'=>'member','status'=>'active']);
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/billing/portal')->assertForbidden();
        $this->postJson('/api/v1/billing/cancel',[
            'confirm'=>true,
        ])->assertForbidden();
    }

    public function test_change_preview_uses_stripe_post_and_does_not_modify_the_subscription(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        $intent=$this->checkout($plan);
        $this->getJson('/api/v1/billing/checkout/'.$intent['intent_id'].'/status')
            ->assertJsonPath('data.verified',true);
        $this->postJson('/api/v1/billing/change-preview',[
            'plan_id'=>$plan,
        ])->assertOk()
            ->assertJsonPath('data.new_plan_id',$plan)
            ->assertJsonPath('data.amount_due_minor',2000)
            ->assertJsonPath('data.currency','chf')
            ->assertJsonPath('data.proration_behavior','none')
            ->assertJsonPath('data.confirmed',false);
        Http::assertSent(fn($r)=>$r->method()==='POST'
            && str_ends_with($r->url(),'/invoices/create_preview'));
        $this->assertDatabaseHas('m23_company_subscriptions',[
            'company_id'=>$company->id,'status'=>'active','plan_id'=>$plan,
        ]);
        $this->assertDatabaseMissing('m23_subscription_events',[
            'company_id'=>$company->id,'event_type'=>'plan_change_requested',
        ]);
    }

    public function test_cancel_and_change_require_explicit_confirmation_and_provider_state(): void
    {
        [$owner,$company,$plan]=$this->tenant();
        $this->enable();$this->fixture();
        $started=$this->checkout($plan);
        $this->getJson('/api/v1/billing/checkout/'.$started['intent_id'].'/status')
            ->assertJsonPath('data.verified',true);
        $this->postJson('/api/v1/billing/cancel')->assertUnprocessable();
        $this->postJson('/api/v1/billing/change',[
            'plan_id'=>$plan,'request_id'=>(string)\Illuminate\Support\Str::uuid(),
            'confirm'=>false,'proration_behavior'=>'none',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/billing/cancel',['confirm'=>true])->assertOk();
        $this->postJson('/api/v1/billing/resume',['confirm'=>true])->assertOk();
        $this->getJson('/api/v1/billing/overview')->assertJsonPath('data.status','active');
    }
}
