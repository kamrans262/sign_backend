<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\M23BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M23SubscriptionStage1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise data/authorization behavior without prior tests' rate-cache.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function actor(bool $admin=false): User
    {
        $u=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>$admin,
            'email_verified_at'=>now(),
        ]);
        Sanctum::actingAs($u);
        return $u;
    }

    private function company(User $u,string $name='Stage 1 Company'): Company
    {
        $c=Company::create([
            'owner_user_id'=>$u->id,'name'=>$name,'country_iso_code'=>'CH',
            'status'=>'active',
        ]);
        $c->users()->attach($u->id,['role'=>'owner','status'=>'active']);
        $this->withHeader('X-Company-Id',(string)$c->id);
        return $c;
    }

    public function test_free_document_default_and_disabled_billing_never_fabricate_paid_state(): void
    {
        $owner=$this->actor();
        $c=$this->company($owner);
        $this->getJson('/api/v1/billing/overview')->assertOk()
            ->assertJsonPath('data.company_id',$c->id)
            ->assertJsonPath('data.status','free')
            ->assertJsonPath('data.allowance.limit',3)
            ->assertJsonPath('data.allowance.remaining',3)
            ->assertJsonPath('data.allowance.enforcement_enabled',false)
            ->assertJsonPath('data.can_checkout',false)
            ->assertJsonPath('data.demo_only',false)
            ->assertJsonPath('data.price_label',null);
        $this->getJson('/api/v1/billing/plans')->assertOk()
            ->assertJsonCount(0,'data.plans');
        $this->getJson('/api/v1/billing/history')->assertOk()
            ->assertJsonCount(0,'data.invoices');
        $this->postJson('/api/v1/billing/checkout',[
            'plan_id'=>999,'amount_minor'=>1,
        ])->assertStatus(503)->assertJsonPath('data.available',false);
        $this->assertDatabaseCount('m23_company_subscriptions',0);
    }

    public function test_company_scope_never_uses_client_supplied_tenant_ids(): void
    {
        $owner=$this->actor();
        $a=$this->company($owner,'First Tenant');
        $b=$this->company($owner,'Second Tenant');
        app(M23BillingService::class)->recordIssue($a->id,'quote',11,$owner->id);
        $this->withHeader('X-Company-Id',(string)$a->id);
        $this->getJson('/api/v1/billing/usage')->assertOk()
            ->assertJsonPath('data.allowance.used',1);
        $this->withHeader('X-Company-Id',(string)$b->id);
        $this->getJson('/api/v1/billing/usage')->assertOk()
            ->assertJsonPath('data.allowance.used',0);
        $outsider=$this->actor();
        $this->withHeader('X-Company-Id',(string)$a->id);
        $this->getJson('/api/v1/billing/overview')->assertForbidden();
        $this->getJson('/api/v1/billing/plans')->assertForbidden();
        $this->withHeader('X-Company-Id','999999999');
        $this->getJson('/api/v1/billing/history')->assertForbidden();
        $this->assertNotEquals($owner->id,$outsider->id);
    }

    public function test_ledger_is_idempotent_and_quota_enforcement_is_default_off(): void
    {
        $owner=$this->actor();
        $company=$this->company($owner);
        $billing=app(M23BillingService::class);
        foreach(range(1,5)as $id){
            $billing->recordIssue($company->id,'quote',$id,$owner->id);
        }
        $billing->recordIssue($company->id,'quote',5,$owner->id);
        $this->assertDatabaseCount('m23_document_usage',5);
        $this->getJson('/api/v1/billing/eligibility')->assertOk()
            ->assertJsonPath('data.used',5)
            ->assertJsonPath('data.remaining',0)
            ->assertJsonPath('data.eligible',true);
        DB::table('m23_billing_settings')->where('id',1)
            ->update(['enforcement_enabled'=>true]);
        // This explicit test-only change is not accessible through M23 Admin.
        try {
            $billing->recordIssue($company->id,'report',900,$owner->id);
            $this->fail('The fourth paid-gated unique issuance must be denied.');
        } catch(HttpResponseException $e) {
            $this->assertSame(402,$e->getResponse()->getStatusCode());
        }
        $billing->recordIssue($company->id,'quote',5,$owner->id);
        $this->assertDatabaseCount('m23_document_usage',5);
        $this->assertDatabaseMissing('m23_document_usage',[
            'company_id'=>$company->id,'source_type'=>'report','source_id'=>900,
        ]);
    }

    public function test_admin_plan_publication_and_no_unverified_paid_access(): void
    {
        $owner=$this->actor();
        $company=$this->company($owner);
        $admin=$this->actor(true);
        $this->actingAs($admin)->get('/admin/billing')->assertOk()
            ->assertSee('Subscriptions & Billing');
        $this->actingAs($admin)->post('/admin/billing/plans',[
            'code'=>'business', 'name'=>'Business', 'period'=>'monthly',
            'currency'=>'CHF', 'amount_minor'=>2000,
            'description'=>'Business essentials',
            'features'=>['invoices','eSignature'],
        ])->assertRedirect();
        $plan=DB::table('m23_plans')->first();
        $this->assertSame('draft',$plan->status);
        $this->withHeader('X-Company-Id',(string)$company->id);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/billing/plans')->assertJsonCount(0,'data.plans');
        $this->actingAs($admin)->post('/admin/billing/plans/'.$plan->id.'/status',[
            'status'=>'published',
        ])->assertRedirect();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/billing/plans')->assertOk()
            ->assertJsonCount(1,'data.plans')
            ->assertJsonPath('data.plans.0.price_label','CHF 20.00 / month')
            ->assertJsonPath('data.plans.0.can_checkout',false);
        $this->getJson('/api/v1/billing/overview')->assertJsonPath('data.status','free');
    }

    public function test_grants_require_admin_reason_expiry_and_are_not_stripe_payments(): void
    {
        $owner=$this->actor();
        $company=$this->company($owner);
        $this->post('/admin/billing/grants',[
            'company_id'=>$company->id,'label'=>'Free forever',
            'reason'=>'not a platform admin','expires_at'=>now()->addDays(3),
        ])->assertForbidden();
        $admin=$this->actor(true);
        $this->actingAs($admin)->post('/admin/billing/grants',[
            'company_id'=>$company->id,'label'=>'QA access',
            'reason'=>'Verified temporary support review',
            'expires_at'=>now()->addDays(7)->toDateTimeString(),
        ])->assertRedirect();
        $grant=DB::table('m23_subscription_grants')->first();
        $this->assertNotNull($grant);
        $this->assertDatabaseCount('m23_company_subscriptions',0);
        $this->withHeader('X-Company-Id',(string)$company->id);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/billing/overview')
            ->assertJsonPath('data.status','granted')
            ->assertJsonPath('data.allowance.has_active_grant',true);
        $this->actingAs($admin)->post('/admin/billing/grants/'.$grant->id.'/revoke')
            ->assertRedirect();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/billing/overview')
            ->assertJsonPath('data.allowance.has_active_grant',false)
            ->assertJsonPath('data.status','free');
    }

    public function test_non_admin_cannot_manage_settings_and_settings_never_enable_live_billing(): void
    {
        $owner=$this->actor();
        $this->company($owner);
        $this->post('/admin/billing/settings',[
            'free_document_limit'=>1,'currency'=>'CHF',
        ])->assertForbidden();
        $admin=$this->actor(true);
        $this->actingAs($admin)->post('/admin/billing/settings',[
            'free_document_limit'=>4,'currency'=>'CHF','trial_days'=>null,
        ])->assertRedirect();
        $this->assertDatabaseHas('m23_billing_settings',[
            'id'=>1,'free_document_limit'=>4,
            'enforcement_enabled'=>false,'stripe_enabled'=>false,
        ]);
        $this->actingAs($admin)->get('/admin/billing/plans/create')->assertOk();
    }
}
