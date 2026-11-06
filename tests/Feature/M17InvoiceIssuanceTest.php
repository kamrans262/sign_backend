<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M17InvoiceIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private function readyInvoice(): array
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['owner_user_id'=>$owner->id,'name'=>'Invoice Issue Works AG',
            'country_iso_code'=>'CH','status'=>'active','iban'=>'CH9300762011623852957']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Marie Dubois','email'=>'marie@example.test',
            'source'=>'manual','address'=>'Rue de Genève 1']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Fitting new doors',
            'start_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'team_member_ids'=>[$owner->id],
        ])->assertCreated()->json('data.job.id');
        $id=(int)$this->postJson('/api/v1/invoices',[
            'job_id'=>$job,'invoice_type'=>'final',
            'invoice_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'vat_basis_points'=>810,'iban'=>'CH9300762011623852957',
            'items'=>[['description'=>'Door fitting','quantity'=>2,'unit_price_minor'=>12000]],
        ])->assertCreated()->json('data.invoice.id');
        $this->postJson('/api/v1/invoices/'.$id.'/approve',
            ['confirm'=>true,'lock_version'=>1])->assertOk();
        return [$owner,$company,$id];
    }

    public function test_issue_is_server_authoritative_revision_bound_and_idempotent(): void
    {
        Storage::fake('local');
        [$owner,$company,$id]=$this->readyInvoice();
        $url='/api/v1/invoices/'.$id;
        $this->getJson($url.'/document')->assertNotFound();
        $first=$this->postJson($url.'/issue',['confirm'=>true])
            ->assertOk()->assertJsonPath('data.document.revision',1)
            ->json('data.document');
        $this->postJson($url.'/issue',['confirm'=>true])
            ->assertOk()->assertJsonPath('data.document.sha256',$first['sha256']);
        $this->assertSame(1,DB::table('service_invoice_issues')
            ->where('service_invoice_id',$id)->count());
        $this->assertDatabaseHas('m23_document_usage',[
            'company_id'=>$company->id,'source_type'=>'invoice','source_id'=>$id,
        ]);
        $this->assertSame(1,DB::table('m23_document_usage')->where('company_id',$company->id)->count());
        $body=$this->get($url.'/pdf')->assertOk()
            ->assertHeader('Content-Type','application/pdf')->baseResponse->getContent();
        $this->assertStringStartsWith('%PDF-',$body);
        $this->assertSame($first['sha256'],hash('sha256',$body));
        $this->get($url.'/pdf/download')->assertOk()
            ->assertHeader('Cache-Control','max-age=0, no-store, private');
        $this->postJson($url.'/void',[
            'confirm'=>true,'lock_version'=>2,'reason'=>'Please void this issue',
        ])->assertStatus(409);
        $this->patchJson($url,['lock_version'=>2])->assertStatus(422);
    }

    public function test_customer_links_are_unpredictable_expiring_and_hash_only(): void
    {
        Storage::fake('local');
        [$owner,$company,$id]=$this->readyInvoice();
        $url='/api/v1/invoices/'.$id;
        $this->postJson($url.'/issue',['confirm'=>true])->assertOk();
        $link=$this->postJson($url.'/customer-link')->assertOk()
            ->assertJsonPath('data.delivered',false)->json('data.secure_link');
        $path=(string)parse_url($link,PHP_URL_PATH);
        $token=basename($path);
        $this->assertSame(64,strlen($token));
        $this->assertDatabaseHas('service_invoice_customer_links',[
            'token_sha256'=>hash('sha256',$token),'company_id'=>$company->id]);
        $this->assertDatabaseMissing('service_invoice_customer_links',[
            'token_sha256'=>$token]);
        $this->get($path)->assertOk()->assertSee('Invoice');
        $this->get($path.'/pdf')->assertOk()
            ->assertHeader('Content-Type','application/pdf');
        $this->get('/invoice-view/'.str_repeat('1',64))->assertNotFound();
        $this->travel(8)->days();
        $this->get($path)->assertNotFound();
        $this->get($path.'/pdf')->assertNotFound();
    }

    public function test_issued_pdf_fails_closed_on_tampering_and_is_tenant_scoped(): void
    {
        Storage::fake('local');
        [$owner,$company,$id]=$this->readyInvoice();
        $this->postJson('/api/v1/invoices/'.$id.'/issue',['confirm'=>true])->assertOk();
        $other=Company::create(['owner_user_id'=>$owner->id,'name'=>'Other Company',
            'country_iso_code'=>'CH','status'=>'active']);
        $other->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $this->withHeader('X-Company-Id',(string)$other->id);
        $this->get('/api/v1/invoices/'.$id.'/pdf')->assertNotFound();
        $this->withHeader('X-Company-Id',(string)$company->id);
        $issue=DB::table('service_invoice_issues')->where('service_invoice_id',$id)->firstOrFail();
        Storage::disk('local')->put($issue->private_path,'corrupted');
        $this->get('/api/v1/invoices/'.$id.'/pdf')->assertStatus(409);
    }

    public function test_delivery_requires_real_transport_and_never_fabricates_success(): void
    {
        // This test exercises retries deliberately; route throttling is tested
        // separately and must not mask the idempotency assertions.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Storage::fake('local');
        [$owner,$company,$id]=$this->readyInvoice();
        $url='/api/v1/invoices/'.$id;
        $this->postJson($url.'/issue',['confirm'=>true])->assertOk();
        config()->set('mail.default','log');
        $params=['recipient_email'=>'marie@example.test','mode'=>'pdf',
            'request_key'=>'m17-stage2-send-unique-one'];
        $this->postJson($url.'/send',$params)->assertStatus(503);
        $this->assertSame(0,DB::table('service_invoice_deliveries')->count());
        config()->set('mail.default','smtp');
        Mail::fake();
        $this->postJson($url.'/send',$params)->assertOk()
            ->assertJsonPath('data.delivered',true)
            ->assertJsonPath('data.pdf_attached',true);
        $this->postJson($url.'/send',$params)->assertOk()
            ->assertJsonPath('data.replayed',true);
        $this->assertSame(1,DB::table('service_invoice_deliveries')
            ->where('status','sent')->count());
        $this->postJson($url.'/send',array_merge($params,[
            'recipient_email'=>'wrong@example.test']))->assertStatus(409);
        $this->getJson($url.'/reconciliation')->assertOk()
            ->assertJsonPath('data.total_minor',25944)
            ->assertJsonPath('data.outstanding_minor',25944);
    }


    public function test_frozen_invoice_snapshot_fails_closed_if_audited_fields_change(): void
    {
        Storage::fake('local');
        [$owner,$company,$id]=$this->readyInvoice();
        $url='/api/v1/invoices/'.$id;
        $this->postJson($url.'/issue',['confirm'=>true])->assertOk();
        $issue=DB::table('service_invoice_issues')->where('service_invoice_id',$id)
            ->firstOrFail();
        $snapshot=json_decode($issue->snapshot,true,512,JSON_THROW_ON_ERROR);
        $snapshot['total_minor']=1;
        DB::table('service_invoice_issues')->where('id',$issue->id)->update([
            'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),
        ]);
        $this->get($url.'/pdf')->assertStatus(409);
        $this->postJson($url.'/customer-link')->assertStatus(409);
    }

    public function test_manual_payment_changes_live_ledger_but_not_issued_pdf(): void
    {
        Storage::fake('local');
        [$owner,$company,$id]=$this->readyInvoice();
        $url='/api/v1/invoices/'.$id;
        $hash=$this->postJson($url.'/issue',['confirm'=>true])
            ->assertOk()->json('data.document.sha256');
        $this->postJson($url.'/payments',[
            'confirm'=>true,'amount'=>'20.00','method'=>'bank_transfer',
            'payment_date'=>'2026-11-05','reference_number'=>'Paid externally',
        ])->assertCreated();
        $this->getJson($url.'/reconciliation')->assertOk()
            ->assertJsonPath('data.outstanding_minor',23944)
            ->assertJsonPath('data.snapshot_outstanding_minor',25944)
            ->assertJsonPath('data.issued_pdf_sha256',$hash);
        $this->assertSame($hash,hash('sha256',$this->get($url.'/pdf')
            ->assertOk()->baseResponse->getContent()));
    }
}
