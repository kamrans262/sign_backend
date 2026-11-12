<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class M23BrowserReturnTest extends TestCase
{
    use RefreshDatabase;

    private const INTENT='33333333-3333-4333-8333-333333333333';

    public function test_public_checkout_handoff_pages_do_not_verify_or_activate_payments(): void
    {
        $this->get('/billing/success?intent='.self::INTENT)
            ->assertOk()->assertSee('Returning to Sign2Digital')
            ->assertSee('sign2digital://billing/success?intent='.self::INTENT,false)
            ->assertHeader('Cache-Control','no-store, private')
            ->assertHeader('Referrer-Policy','no-referrer');
        $this->get('/billing/cancel?intent='.self::INTENT)
            ->assertOk()->assertSee('Checkout canceled')
            ->assertSee('sign2digital://billing/cancel?intent='.self::INTENT,false);
        $this->get('/billing/return')->assertOk()
            ->assertSee('sign2digital://billing/portal',false);
        $this->assertDatabaseCount('m23_company_subscriptions',0);
        $this->assertDatabaseCount('m23_checkout_intents',0);
        $this->assertDatabaseCount('m23_billing_invoices',0);
    }

    public function test_browser_return_rejects_missing_malformed_or_injected_intents(): void
    {
        $this->get('/billing/success')->assertBadRequest();
        $this->get('/billing/cancel?intent=invoice_2')->assertBadRequest();
        $this->get('/billing/success?intent=%3Cscript%3E')->assertBadRequest();
    }

    public function test_checkout_uses_this_https_domain_and_preserves_intent_to_return(): void
    {
        // No real Stripe network requests are permitted in CI.
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create([
            'owner_user_id'=>$owner->id,'name'=>'Checkout Return QA',
            'country_iso_code'=>'CH','status'=>'active',
        ]);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $plan=DB::table('m23_plans')->insertGetId([
            'code'=>'browser_return_test','name'=>'Business','period'=>'monthly',
            'currency'=>'CHF','amount_minor'=>2900,'stripe_price_id'=>'price_testqa',
            'features'=>'[]','status'=>'published',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        config()->set('m23_billing.enabled',true);
        config()->set('m23_billing.live_mode',false);
        config()->set('m23_billing.secret_key','sk_test_ci_secret');
        foreach(['checkout_success_url','checkout_cancel_url','portal_return_url'] as $key){
            config()->set('m23_billing.'.$key,'');
        }
        DB::table('m23_billing_settings')->where('id',1)->update(['stripe_enabled'=>true]);
        $this->actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->withServerVariables([
            'HTTPS'=>'on',
            'HTTP_HOST'=>'forestgreen-pigeon-673192.hostingersite.com',
            'SERVER_NAME'=>'forestgreen-pigeon-673192.hostingersite.com',
        ]);
        Http::fake(function($request){
            $url=$request->url();
            if(str_contains($url,'/prices/price_testqa'))return Http::response([
                'id'=>'price_testqa','type'=>'recurring','active'=>true,
                'currency'=>'chf','unit_amount'=>2900,
                'recurring'=>['interval'=>'month','interval_count'=>1],
            ]);
            if(str_ends_with($url,'/customers'))return Http::response(['id'=>'cus_testqa']);
            if(str_ends_with($url,'/checkout/sessions'))return Http::response([
                'id'=>'cs_testqa','mode'=>'subscription','customer'=>'cus_testqa',
                'url'=>'https://checkout.stripe.com/c/pay/testqa',
                'expires_at'=>time()+1800,
            ]);
            return Http::response(['unexpected'=>true],503);
        });
        $this->postJson('/api/v1/billing/checkout',[
            'plan_id'=>$plan,'request_id'=>self::INTENT,
        ])->assertOk()->assertJsonPath('data.status','pending');
        Http::assertSent(function($request): bool {
            if(!str_ends_with($request->url(),'/checkout/sessions'))return false;
            $base='https://forestgreen-pigeon-673192.hostingersite.com';
            return $request['success_url']===$base.'/billing/success?intent='.self::INTENT
                && $request['cancel_url']===$base.'/billing/cancel?intent='.self::INTENT;
        });
        $this->assertDatabaseHas('m23_checkout_intents',[
            'request_id'=>self::INTENT,'company_id'=>$company->id,'status'=>'pending',
        ]);
        $this->assertDatabaseCount('m23_billing_invoices',0);
    }
}
