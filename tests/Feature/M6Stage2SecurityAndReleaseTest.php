<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M6Stage2SecurityAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        $user=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create([
            'name'=>'Stage2 Company', 'owner_user_id'=>$user->id,
            'status'=>'active','country_iso_code'=>'CH',
            'email'=>'accounts@stage2.example',
        ]);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create([
            'company_id'=>$company->id, 'full_name'=>'Test Customer',
            'source'=>'manual',
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        return [$user,$company,$client];
    }

    private function draft(Client $client): array
    {
        return [
            'client_id'=>$client->id,
            'client_name'=>'Test Customer',
            'quote_date'=>'2026-11-03', 'currency'=>'CHF',
            'vat_basis_points'=>810,'deposit_basis_points'=>3000,
            'terms'=>'Customer must review and accept separately.',
            'items'=>[[
                'title'=>'Repair work','quantity'=>2,
                'unit_price_minor'=>8000,'dimensions'=>'2m x 3m',
            ]],
        ];
    }

    private function approvedQuote(Client $client): int
    {
        $id=$this->postJson('/api/v1/quotes',$this->draft($client))
            ->assertCreated()->json('data.quote.id');
        $this->postJson('/api/v1/quotes/'.$id.'/approve',['confirmed'=>true])
            ->assertOk();
        return (int)$id;
    }

    public function test_signed_customer_links_expire_are_tamper_proof_and_revoked_on_archive_even_after_restore(): void
    {
        [$owner,$company,$client]=$this->tenant();
        $id=$this->approvedQuote($client);
        $signed=$this->postJson('/api/v1/quotes/'.$id.'/share',[
            'method'=>'secure_link',
        ])->assertOk()->assertJsonPath('data.delivered',false)->json('data.secure_link');

        $this->assertIsString($signed);
        $this->get($signed)->assertOk()->assertSee('Stage2 Company')
            ->assertSee('Repair work');
        $this->getJson($signed)->assertOk()->assertJsonPath('data.quote.status','approved');
        $this->get('/quote-view/'.$id)->assertForbidden();

        $tampered=preg_replace('/([?&]v=)1/', '$1' . '100', $signed);
        $this->assertNotEquals($signed,$tampered);
        $this->get($tampered)->assertForbidden();

        $now=now()->copy();
        Carbon::setTestNow($now->copy()->addDays(8));
        try {
            $this->get($signed)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }

        $this->deleteJson('/api/v1/quotes/'.$id)->assertOk();
        $this->get($signed)->assertNotFound();
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->post('/admin/quotes/'.$id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$id,'status'=>'approved','link_version'=>2,
        ]);
        $this->get($signed)->assertNotFound();
        // After an archive+restore cycle, a new authorized generation is necessary.
        Sanctum::actingAs($owner);
        $new=$this->postJson('/api/v1/quotes/'.$id.'/share',[
            'method'=>'secure_link',
        ])->assertOk()->json('data.secure_link');
        $this->assertNotEquals($signed,$new);
        $this->get($new)->assertOk();
        $this->assertDatabaseCount('service_quote_revisions',2);
    }

    public function test_email_unconfigured_refuses_delivery_and_never_fakes_sent(): void
    {
        [,,$client]=$this->tenant();
        $id=$this->approvedQuote($client);
        Mail::fake();
        config(['mail.default'=>'log']);
        $this->postJson('/api/v1/quotes/'.$id.'/share',[
            'method'=>'email','recipient_email'=>'customer@example.test',
        ])->assertStatus(503);
        $this->assertDatabaseHas('service_quotes',['id'=>$id,'status'=>'approved']);
        Mail::assertNothingSent();
    }

    public function test_payment_payload_restricts_keys_and_server_rejects_untrusted_amounts_and_cross_client_jobs(): void
    {
        [,,$client]=$this->tenant();
        $payload=$this->draft($client);
        $payload['payment_details']=[
            'iban'=>'CH93 0076 2011 6238 5295 7',
            'accountHolder'=>'Stage2 Company',
        ];
        $response=$this->postJson('/api/v1/quotes',array_merge($payload,[
            'status'=>'sent','total_minor'=>1,'approved_at'=>'2020-01-01',
            'company_id'=>999999,'link_version'=>123,
        ]))->assertCreated()
          ->assertJsonPath('data.quote.status','draft')
          ->assertJsonPath('data.quote.total_minor',17296);
        $id=$response->json('data.quote.id');
        $this->assertDatabaseHas('service_quotes',[
            'id'=>$id,'link_version'=>1,'company_id'=>$client->company_id,
        ]);

        $invalid=$payload;
        $invalid['payment_details']['client_secrets']='not allowed';
        $this->postJson('/api/v1/quotes',$invalid)->assertUnprocessable();

        $invalid=$payload;
        $invalid['items'][0]['unit_price_minor']=1000000000001;
        $this->postJson('/api/v1/quotes',$invalid)->assertUnprocessable();
    }

    public function test_admin_full_approved_details_and_payment_fields_and_money_overflow_validation(): void
    {
        [, $company,$client]=$this->tenant();
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $request=[
            'company_id'=>$company->id,'client_id'=>$client->id,
            'client_name'=>'Test Customer','quote_date'=>'2026-11-03',
            'currency'=>'CHF','vat_basis_points'=>0,'deposit_basis_points'=>0,
            'terms'=>'30 days',
            'payment_details'=>['iban'=>'CH93 0076 2011 6238 5295 7',
                'accountHolder'=>'Stage2 Company','city'=>'Zurich'],
            'items'=>[['title'=>'Inspection','quantity'=>1,'unit_price'=>'0.00']],
        ];
        $this->post('/admin/quotes',$request)->assertRedirect();
        $quote=ServiceQuote::firstOrFail();
        $this->assertSame(0,(int)$quote->total_minor);
        $this->post('/admin/quotes/'.$quote->id.'/approve')->assertRedirect();
        $this->assertDatabaseHas('service_quotes',['id'=>$quote->id,'status'=>'approved']);
        $this->get('/admin/quotes/'.$quote->id.'/edit')->assertOk()
            ->assertSee('Approved Quote Details')->assertSee('Inspection')
            ->assertSee('CH93 0076 2011 6238 5295 7');
        $bad=$request;
        $bad['items'][0]['unit_price']='9999999999999999999999999999999999.99';
        $this->post('/admin/quotes',$bad)->assertSessionHasErrors();
        $this->assertDatabaseCount('service_quotes',1);
    }

    public function test_ai_unsupported_upload_rejected_without_external_network_and_key_stays_server_only():void
    {
        [,,$client]=$this->tenant();
        config(['services.openai.key'=>'test-private-secret-never-return']);
        Http::preventStrayRequests();
        $bad=UploadedFile::fake()->create('notes.txt',3,'text/plain');
        $this->postJson('/api/v1/quotes/prepare/image',['file'=>$bad])
            ->assertUnprocessable()
            ->assertDontSee('test-private-secret-never-return');
        $this->postJson('/api/v1/quotes/prepare/text',['text'=>''])
            ->assertUnprocessable()->assertDontSee('test-private-secret-never-return');
        $this->postJson('/api/v1/quotes',$this->draft($client))
            ->assertCreated()->assertDontSee('test-private-secret-never-return');
        Http::assertNothingSent();
    }
}
