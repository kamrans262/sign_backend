<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class M23StripeTestSetupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url','https://forestgreen-pigeon-673192.hostingersite.com');
        config()->set('m23_billing.enabled',true);
        config()->set('m23_billing.live_mode',false);
        config()->set('m23_billing.secret_key','sk_test_dummy_secret_for_ci');
    }

    public function test_default_command_is_read_only(): void
    {
        Http::preventStrayRequests();
        $this->artisan('billing:m23-setup-test')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertFalse((bool)DB::table('m23_billing_settings')
            ->where('id',1)->value('stripe_enabled'));
        $this->assertNull(DB::table('m23_plans')
            ->where('code','business')->where('period','monthly')
            ->value('stripe_price_id'));
    }

    public function test_test_setup_provisions_recurring_prices_and_enables_only_test_checkout(): void
    {
        Http::fake(function($request) {
            $path=parse_url($request->url(),PHP_URL_PATH);
            if($request->method()==='POST' && $path==='/v1/products'){
                return Http::response(['object'=>'product','id'=>'prod_testqa']);
            }
            if($request->method()==='POST' && $path==='/v1/prices'){
                $period=$request['recurring[interval]']==='year'?'yearly':'monthly';
                return Http::response([
                    'object'=>'price',
                    'id'=>$period==='yearly'?'price_testyearly':'price_testmonthly',
                    'product'=>'prod_testqa','active'=>true,'type'=>'recurring',
                    'currency'=>'chf','unit_amount'=>(int)$request['unit_amount'],
                    'recurring'=>[
                        'interval'=>$request['recurring[interval]'],
                        'interval_count'=>1,
                    ],
                ]);
            }
            if($request->method()==='GET' && str_contains($path,'/v1/prices/')){
                $period=str_contains($path,'yearly')?'yearly':'monthly';
                return Http::response([
                    'object'=>'price',
                    'id'=>$period==='yearly'?'price_testyearly':'price_testmonthly',
                    'product'=>'prod_testqa','active'=>true,'type'=>'recurring',
                    'currency'=>'chf',
                    'unit_amount'=>$period==='yearly'?29000:2900,
                    'recurring'=>[
                        'interval'=>$period==='yearly'?'year':'month',
                        'interval_count'=>1,
                    ],
                ]);
            }
            return Http::response(['error'=>'unexpected test API call'],503);
        });
        $this->artisan('billing:m23-setup-test',['--apply'=>true])
            ->assertSuccessful();
        $this->assertSame('price_testmonthly',DB::table('m23_plans')
            ->where('code','business')->where('period','monthly')
            ->value('stripe_price_id'));
        $this->assertSame('price_testyearly',DB::table('m23_plans')
            ->where('code','business')->where('period','yearly')
            ->value('stripe_price_id'));
        $this->assertTrue((bool)DB::table('m23_billing_settings')
            ->where('id',1)->value('stripe_enabled'));
        $this->assertFalse((bool)DB::table('m23_billing_settings')
            ->where('id',1)->value('enforcement_enabled'));
        $this->artisan('billing:m23-setup-test',['--apply'=>true])
            ->assertSuccessful();
        $this->assertSame(2,DB::table('m23_plans')->where('code','business')
            ->count());
        $this->assertSame(2,DB::table('m23_plan_revisions')
            ->whereIn('plan_id',DB::table('m23_plans')
                ->where('code','business')->pluck('id'))->where('revision',2)
            ->count());
    }

    public function test_never_allows_live_mode_or_unverified_key(): void
    {
        Http::preventStrayRequests();
        config()->set('m23_billing.live_mode',true);
        $this->artisan('billing:m23-setup-test',['--apply'=>true])
            ->assertFailed();
        Http::assertNothingSent();
        $this->assertFalse((bool)DB::table('m23_billing_settings')
            ->where('id',1)->value('stripe_enabled'));
    }
}
