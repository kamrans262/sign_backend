<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class M23StripeLiveSetupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://forestgreen-pigeon-673192.hostingersite.com');
        config()->set('m23_billing.enabled', true);
        config()->set('m23_billing.live_mode', true);
        config()->set('m23_billing.secret_key', 'sk_live_ci_only_not_a_real_secret');
    }

    private function fakeLiveApi(): void
    {
        Http::fake(function($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && $path === '/v1/account') {
                return Http::response([
                    'id'=>'acct_liveqa','charges_enabled'=>true,
                ]);
            }
            if ($request->method() === 'POST' && $path === '/v1/products') {
                return Http::response([
                    'object'=>'product','id'=>'prod_liveqa','livemode'=>true,
                ]);
            }
            if ($request->method() === 'POST' && $path === '/v1/prices') {
                $period=$request['recurring[interval]']==='year'?'yearly':'monthly';
                return Http::response([
                    'object'=>'price','livemode'=>true,
                    'id'=>$period==='yearly'?'price_liveyearly':'price_livemonthly',
                    'product'=>'prod_liveqa','active'=>true,'type'=>'recurring',
                    'currency'=>'chf','unit_amount'=>(int)$request['unit_amount'],
                    'recurring'=>[
                        'interval'=>$request['recurring[interval]'],
                        'interval_count'=>1,
                    ],
                ]);
            }
            if ($request->method() === 'GET' && str_contains($path, '/v1/prices/')) {
                $id=basename($path);
                if (!str_starts_with($id,'price_live')) return Http::response([
                    'error'=>['message'=>'No such price'],
                ],404);
                $period=str_contains($id,'yearly')?'yearly':'monthly';
                return Http::response([
                    'object'=>'price','livemode'=>true,
                    'id'=>$id,'product'=>'prod_liveqa',
                    'active'=>true,'type'=>'recurring','currency'=>'chf',
                    'unit_amount'=>$period==='yearly'?29000:2900,
                    'recurring'=>[
                        'interval'=>$period==='yearly'?'year':'month',
                        'interval_count'=>1,
                    ],
                ]);
            }
            return Http::response(['error'=>'unexpected Stripe LIVE API request'],503);
        });
    }

    private function setTestPrices(): void
    {
        DB::table('m23_plans')->where('code','business')
            ->where('period','monthly')
            ->update(['stripe_price_id'=>'price_testoldmonthly']);
        DB::table('m23_plans')->where('code','business')
            ->where('period','yearly')
            ->update(['stripe_price_id'=>'price_testoldyearly']);
        DB::table('m23_billing_settings')->where('id',1)
            ->update(['stripe_enabled'=>true]);
    }

    public function test_preview_does_not_call_stripe_or_change_existing_test_prices(): void
    {
        $this->setTestPrices();
        Http::preventStrayRequests();
        $this->artisan('billing:m23-setup-live')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('price_testoldmonthly',DB::table('m23_plans')
            ->where('code','business')->where('period','monthly')
            ->value('stripe_price_id'));
    }

    public function test_live_activation_replaces_only_test_price_links_and_preserves_revisions(): void
    {
        $this->setTestPrices();
        $this->fakeLiveApi();
        $this->artisan('billing:m23-setup-live',['--apply'=>true])
            ->assertSuccessful();
        $this->assertSame('price_livemonthly',DB::table('m23_plans')
            ->where('code','business')->where('period','monthly')
            ->value('stripe_price_id'));
        $this->assertSame('price_liveyearly',DB::table('m23_plans')
            ->where('code','business')->where('period','yearly')
            ->value('stripe_price_id'));
        $this->assertTrue((bool) DB::table('m23_billing_settings')
            ->where('id',1)->value('stripe_enabled'));
        $this->assertFalse((bool) DB::table('m23_billing_settings')
            ->where('id',1)->value('enforcement_enabled'));
        $this->assertSame(2,DB::table('m23_plan_revisions')
            ->whereIn('plan_id',DB::table('m23_plans')
                ->where('code','business')->pluck('id'))
            ->where('revision',2)->count());
        $this->artisan('billing:m23-setup-live',['--apply'=>true])
            ->assertSuccessful();
        $this->assertSame(2,DB::table('m23_plan_revisions')
            ->whereIn('plan_id',DB::table('m23_plans')
                ->where('code','business')->pluck('id'))
            ->where('revision',2)->count());
    }

    public function test_no_live_setup_with_test_key_or_disabled_live_mode(): void
    {
        $this->setTestPrices();
        Http::preventStrayRequests();
        config()->set('m23_billing.secret_key','sk_test_only');
        $this->artisan('billing:m23-setup-live',['--apply'=>true])
            ->assertFailed();
        config()->set('m23_billing.secret_key','sk_live_ci_only_not_a_real_secret');
        config()->set('m23_billing.live_mode',false);
        $this->artisan('billing:m23-setup-live',['--apply'=>true])
            ->assertFailed();
        Http::assertNothingSent();
        $this->assertSame('price_testoldmonthly',DB::table('m23_plans')
            ->where('code','business')->where('period','monthly')
            ->value('stripe_price_id'));
    }

    public function test_existing_stripe_checkout_prevents_mode_switch_without_erasing_it(): void
    {
        $this->setTestPrices();
        $user=User::factory()->create();
        $company=Company::create([
            'owner_user_id'=>$user->id,'name'=>'Historical Test Company',
            'country_iso_code'=>'CH','status'=>'active',
        ]);
        $plan=DB::table('m23_plans')->where('code','business')
            ->where('period','monthly')->firstOrFail();
        DB::table('m23_checkout_intents')->insert([
            'request_id'=>'33333333-3333-4333-8333-333333333333',
            'company_id'=>$company->id,'plan_id'=>$plan->id,
            'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
        ]);
        Http::preventStrayRequests();
        $this->artisan('billing:m23-setup-live',['--apply'=>true])
            ->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('m23_checkout_intents',1);
        $this->assertTrue((bool) DB::table('m23_billing_settings')
            ->where('id',1)->value('stripe_enabled'));
    }

    public function test_live_account_without_charge_permission_stays_disabled(): void
    {
        $this->setTestPrices();
        Http::fake(['https://api.stripe.com/v1/account' =>
            Http::response(['id'=>'acct_liveqa','charges_enabled'=>false])]);
        $this->artisan('billing:m23-setup-live',['--apply'=>true])
            ->assertFailed();
        $this->assertFalse((bool) DB::table('m23_billing_settings')
            ->where('id',1)->value('stripe_enabled'));
    }
}
