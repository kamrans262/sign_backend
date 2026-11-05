<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M17InvoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name='M17 Invoice Tests'): array
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['owner_user_id'=>$owner->id,'name'=>$name,
            'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Marie Dubois','email'=>'invoice@example.test',
            'source'=>'manual','address'=>'Rue de Genève 1']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Invoice repair project',
            'start_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'team_member_ids'=>[$owner->id],
        ])->assertCreated()->json('data.job.id');
        return [$owner,$company,$client,$job];
    }

    private function fields(int $job,?string $key=null): array
    {
        return [
            'job_id'=>$job,'invoice_type'=>'final',
            'invoice_date'=>'2026-11-05','due_date'=>'2026-12-05',
            'vat_basis_points'=>810,
            'invoice_number'=>'INV-2026-TEST-'.$job,
            'iban'=>'CH9300762011623852957',
            'notes'=>'Work billed from completed job',
            'terms'=>'Payment within 30 days.',
            'allocate_deposits'=>true,
            'items'=>[
                ['description'=>'Parts','quantity'=>2,'unit_price_minor'=>10000],
                ['description'=>'Labour','quantity'=>1,'unit_price_minor'=>5000],
            ],
            ...($key===null?[]:['request_key'=>$key]),
        ];
    }

    private function createInvoice(int $job,?string $key=null): int
    {
        return (int)$this->postJson('/api/v1/invoices',$this->fields($job,$key))
            ->assertCreated()->assertJsonPath('data.invoice.total_minor',27025)
            ->assertJsonPath('data.invoice.vat_minor',2025)
            ->assertJsonPath('data.invoice.status','draft')
            ->json('data.invoice.id');
    }

    public function test_manual_invoice_approval_and_partial_payments_never_overpay(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $id=$this->createInvoice($job,'create-invoice-test-one');
        $this->getJson('/api/v1/invoices/'.$id)->assertOk()
            ->assertJsonCount(2,'data.items')
            ->assertJsonCount(1,'data.history');
        $this->postJson('/api/v1/invoices/'.$id.'/payments',[
            'amount'=>'1.00','method'=>'cash','payment_date'=>'2026-11-05',
            'confirm'=>true,
        ])->assertStatus(409);
        $this->postJson('/api/v1/invoices/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk()->assertJsonPath('data.invoice.status','pending')
            ->assertJsonPath('data.invoice.lock_version',2);
        $this->postJson('/api/v1/invoices/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertStatus(409);
        $this->patchJson('/api/v1/invoices/'.$id,array_merge($this->fields($job),
            ['lock_version'=>2]))->assertStatus(409);
        $first=(int)$this->postJson('/api/v1/invoices/'.$id.'/payments',[
            'amount'=>'50.00','method'=>'bank_transfer','payment_date'=>'2026-11-05',
            'reference_number'=>'WIRE-111','confirm'=>true,
            'request_key'=>'payment-m17-first-2026',
        ])->assertCreated()->assertJsonPath('data.invoice.status','partially_paid')
            ->assertJsonPath('data.invoice.amount_due_minor',22025)
            ->json('data.payment_id');
        $this->postJson('/api/v1/invoices/'.$id.'/payments',[
            'amount'=>'50.00','method'=>'bank_transfer','payment_date'=>'2026-11-05',
            'reference_number'=>'WIRE-111','confirm'=>true,
            'request_key'=>'payment-m17-first-2026',
        ])->assertCreated()->assertJsonPath('data.payment_id',$first)
            ->assertJsonPath('data.replayed',true);
        $this->postJson('/api/v1/invoices/'.$id.'/payments',[
            'amount'=>'221.00','method'=>'cash','payment_date'=>'2026-11-05',
            'confirm'=>true,
        ])->assertStatus(409);
        $this->postJson('/api/v1/invoices/'.$id.'/payments',[
            'amount'=>'220.25','method'=>'cash','payment_date'=>'2026-11-05',
            'confirm'=>true,
        ])->assertCreated()->assertJsonPath('data.invoice.status','paid')
            ->assertJsonPath('data.invoice.amount_due_minor',0);
        $this->getJson('/api/v1/invoices/'.$id)->assertOk()
            ->assertJsonCount(2,'data.payments')
            ->assertJsonCount(4,'data.history');
        $this->postJson('/api/v1/invoices/'.$id.'/payments/'.$first.'/void',[
            'confirm'=>true,'reason'=>'Recorded transfer was reversed by bank.',
        ])->assertOk()->assertJsonPath('data.invoice.status','partially_paid')
            ->assertJsonPath('data.invoice.amount_due_minor',5000);
        $this->postJson('/api/v1/invoices/'.$id.'/payments/'.$first.'/void',[
            'confirm'=>true,'reason'=>'Duplicate correction not allowed.',
        ])->assertStatus(409);
        $this->assertDatabaseCount('service_invoices',1);
        $this->assertDatabaseCount('service_invoice_payments',2);
    }

    public function test_idempotent_invoice_create_rejects_payload_changes_and_stale_edits(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Idempotency');
        $key='local-invoice-20261105-001';
        $id=$this->createInvoice($job,$key);
        $this->postJson('/api/v1/invoices',$this->fields($job,$key))
            ->assertCreated()->assertJsonPath('data.invoice.id',$id);
        $change=$this->fields($job,$key);
        $change['items'][0]['unit_price_minor']=11000;
        $this->postJson('/api/v1/invoices',$change)->assertStatus(409);
        $this->patchJson('/api/v1/invoices/'.$id,array_merge($change,[
            'request_key'=>null,'lock_version'=>1,
        ]))->assertOk()->assertJsonPath('data.invoice.total_minor',29187)
            ->assertJsonPath('data.invoice.lock_version',2);
        $this->patchJson('/api/v1/invoices/'.$id,array_merge($change,[
            'lock_version'=>1,
        ]))->assertStatus(409);
        $this->assertDatabaseCount('service_invoices',1);
        $this->assertDatabaseCount('service_invoice_items',2);
    }

    public function test_confirmed_deposits_apply_only_once_to_one_final_invoice(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Deposit Allocations');
        $deposit=(int)$this->postJson('/api/v1/deposits',[
            'job_id'=>$job,'client_name'=>'Marie Dubois','amount'=>'100.00',
            'deposit_date'=>'2026-11-05','payment_method'=>'cash',
        ])->assertCreated()->json('data.deposit.id');
        $this->postJson('/api/v1/deposits/'.$deposit.'/receive',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk();
        $id=$this->createInvoice($job,'invoice-with-deposit-100');
        $this->getJson('/api/v1/invoices/'.$id)->assertOk()
            ->assertJsonPath('data.invoice.deposit_paid_minor',10000)
            ->assertJsonPath('data.invoice.amount_due_minor',17025);
        $other=$this->fields($job,'invoice-second-2026');
        $other['invoice_number']='INV-ANOTHER-'.$job;
        $new=(int)$this->postJson('/api/v1/invoices',$other)
            ->assertCreated()->assertJsonPath('data.invoice.deposit_paid_minor',0)
            ->json('data.invoice.id');
        $this->assertNotSame($id,$new);
        $this->assertDatabaseCount('service_invoice_deposit_allocations',1);
        $this->postJson('/api/v1/invoices/'.$id.'/void',[
            'confirm'=>true,'lock_version'=>1,
            'reason'=>'Invoice was created against the wrong work phase.',
        ])->assertOk()->assertJsonPath('data.invoice.status','void');
        $this->patchJson('/api/v1/invoices/'.$new,
            array_merge($other,['lock_version'=>1]))
            ->assertOk()->assertJsonPath('data.invoice.deposit_paid_minor',10000);
        $this->assertDatabaseCount('service_invoice_deposit_allocations',1);
    }

    public function test_cross_company_invoice_and_payment_ids_are_not_accessible(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Company Alpha');
        $id=$this->createInvoice($job);
        [$other,$companyB,$clientB,$jobB]=$this->tenant('M17 Company Beta');
        $this->getJson('/api/v1/invoices/'.$id)->assertNotFound();
        $this->postJson('/api/v1/invoices/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertNotFound();
        $this->patchJson('/api/v1/invoices/'.$id,
            array_merge($this->fields($jobB),['lock_version'=>1]))
            ->assertNotFound();
        $this->getJson('/api/v1/invoices')->assertOk()
            ->assertJsonCount(0,'data.records');
        $this->postJson('/api/v1/invoices',array_merge($this->fields($jobB),[
            'quote_id'=>999999,
        ]))->assertNotFound();
        $this->assertDatabaseCount('service_invoices',1);
    }

    public function test_invoice_amounts_are_validated_without_floating_point(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Amounts');
        $invalid=$this->fields($job);
        $invalid['items'][0]['quantity']=100001;
        $this->postJson('/api/v1/invoices',$invalid)->assertUnprocessable();
        $invalid=$this->fields($job);
        $invalid['vat_basis_points']=10001;
        $this->postJson('/api/v1/invoices',$invalid)->assertUnprocessable();
        $invalid=$this->fields($job);
        $invalid['items'][0]['unit_price_minor']=99999999999;
        $this->postJson('/api/v1/invoices',$invalid)->assertStatus(422);
        $invalid=$this->fields($job);
        $invalid['due_date']='2026-11-01';
        $this->postJson('/api/v1/invoices',$invalid)->assertUnprocessable();
        $this->assertDatabaseCount('service_invoices',0);
    }

    public function test_invoice_originals_private_hash_verified_and_frozen_after_approval(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant('M17 Attachments');
        $id=$this->createInvoice($job);
        $file=UploadedFile::fake()->image('invoice.jpg',100,100);
        $proof=(int)$this->post('/api/v1/invoices/'.$id.'/attachments',[
            'file'=>$file,
        ],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $this->get('/api/v1/invoices/'.$id.'/attachments/'.$proof)
            ->assertOk()->assertHeader('Content-Type','image/jpeg');
        $this->postJson('/api/v1/invoices/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk();
        $this->deleteJson('/api/v1/invoices/'.$id.'/attachments/'.$proof)
            ->assertStatus(409);
        $proofRow=DB::table('service_invoice_attachments')->find($proof);
        Storage::disk('local')->put($proofRow->private_path,'tampered');
        $this->get('/api/v1/invoices/'.$id.'/attachments/'.$proof)
            ->assertStatus(409);
        $admin=User::factory()->create(['is_platform_admin'=>true,'is_active'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/invoices')->assertOk()->assertSee('Invoice Management');
        $this->get('/admin/invoices/'.$id.'/edit')
            ->assertOk()->assertSee('Verified Original');
    }

    public function test_only_company_managers_can_approve_and_record_payments(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Permission');
        $id=$this->createInvoice($job);
        $worker=User::factory()->create([
            'is_active'=>true,'email_verified_at'=>now(),
        ]);
        $company->users()->attach($worker->id,[
            'role'=>'member','status'=>'active',
            'permissions'=>json_encode(['jobs.read','documents.read','documents.write']),
        ]);
        Sanctum::actingAs($worker);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->postJson('/api/v1/invoices/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertForbidden();
        $this->postJson('/api/v1/invoices/'.$id.'/payments',[
            'amount'=>'12.00','method'=>'cash','payment_date'=>'2026-11-05',
            'confirm'=>true,
        ])->assertForbidden();
    }

    public function test_approved_quote_same_company_and_job_is_required(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Quote Source');
        $quote=DB::table('service_quotes')->insertGetId([
            'company_id'=>$company->id,'client_id'=>$client->id,
            'service_job_id'=>$job,'reference_number'=>'Q-M17-001',
            'client_name'=>$client->full_name,'quote_date'=>'2026-11-05',
            'valid_until'=>'2026-12-05','currency'=>'CHF',
            'status'=>'draft','source_method'=>'manual',
            'revision'=>1,'subtotal_minor'=>25000,'vat_minor'=>2025,
            'total_minor'=>27025,'deposit_minor'=>0,
            'deposit_basis_points'=>0,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $p=$this->fields($job);
        $p['quote_id']=$quote;
        $this->postJson('/api/v1/invoices',$p)->assertUnprocessable();
        DB::table('service_quotes')->where('id',$quote)->update([
            'status'=>'approved','approved_at'=>now(),
        ]);
        $this->postJson('/api/v1/invoices',$p)
            ->assertCreated()->assertJsonPath('data.invoice.quote_id',$quote);
    }

    public function test_job_sources_include_only_approved_priced_quotes_and_unpriced_work_remains_unpriced(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Verified Sources');
        $query='/api/v1/jobs/'.$job.'/invoice-sources';
        $this->getJson($query)->assertOk()->assertJsonPath('data.job_id',$job)
            ->assertJsonCount(0,'data.quotes')
            ->assertJsonCount(0,'data.addenda');
        $quote=DB::table('service_quotes')->insertGetId([
            'company_id'=>$company->id,'client_id'=>$client->id,
            'service_job_id'=>$job,'reference_number'=>'Q-M17-SRC',
            'client_name'=>$client->full_name,
            'quote_date'=>'2026-11-05','currency'=>'CHF',
            'status'=>'draft','source_method'=>'manual','revision'=>1,
            'vat_basis_points'=>810,'subtotal_minor'=>20000,'vat_minor'=>1620,
            'total_minor'=>21620,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('service_quote_items')->insert([
            'service_quote_id'=>$quote,'position'=>1,
            'title'=>'Actual signed work','description'=>'Supply one part',
            'quantity'=>1,'unit_price_minor'=>20000,
        ]);
        $this->getJson($query)->assertJsonCount(0,'data.quotes');
        DB::table('service_quotes')->where('id',$quote)->update([
            'status'=>'approved','approved_at'=>now(),
        ]);
        $this->getJson($query)->assertOk()
            ->assertJsonCount(1,'data.quotes')
            ->assertJsonPath('data.quotes.0.id',$quote)
            ->assertJsonPath('data.quotes.0.items.0.quantity',1)
            ->assertJsonPath('data.quotes.0.items.0.unit_price_minor',20000)
            ->assertJsonPath('data.quotes.0.items.0.priced',true);
    }

    public function test_available_deposit_credit_excludes_already_allocated_and_refunded_money(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Available Deposits');
        $source='/api/v1/jobs/'.$job.'/invoice-deposits';
        $this->getJson($source)->assertOk()
            ->assertJsonPath('data.available_minor',0);
        $deposit=(int)$this->postJson('/api/v1/deposits',[
            'job_id'=>$job,'client_name'=>'Marie Dubois',
            'amount'=>'120.00','deposit_date'=>'2026-11-05',
            'payment_method'=>'bank_transfer',
        ])->assertCreated()->json('data.deposit.id');
        $this->getJson($source)->assertJsonPath('data.available_minor',0);
        $this->postJson('/api/v1/deposits/'.$deposit.'/receive',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk();
        $this->getJson($source)->assertJsonPath('data.available_minor',12000);
        $this->createInvoice($job);
        $this->getJson($source)->assertJsonPath('data.available_minor',0);
    }

    public function test_admin_manual_invoice_creation_and_approval_reuse_server_ledger(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M17 Admin Invoice');
        $admin=User::factory()->create(['is_platform_admin'=>true,'is_active'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/invoices/create')->assertOk()
            ->assertSee('Invoice Items')->assertSee('Signed Addenda');
        $payload=array_merge($this->fields($job),[
            'company_id'=>$company->id,'request_key'=>'admin-invoice-20261105',
            'items'=>[
                ['description'=>'Labour','quantity'=>2,'unit_price_minor'=>12500],
                ['description'=>'','quantity'=>1,'unit_price_minor'=>''],
            ],
        ]);
        $this->post('/admin/invoices',$payload)->assertRedirect();
        $this->post('/admin/invoices',$payload)->assertRedirect();
        $id=(int)DB::table('service_invoices')->where('company_id',$company->id)
            ->value('id');
        $this->assertSame(1,DB::table('service_invoices')->count());
        $this->get('/admin/invoices/'.$id.'/edit')->assertOk()
            ->assertSee('Approve Invoice');
        $this->post('/admin/invoices/'.$id.'/approve',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertRedirect();
        $this->post('/admin/invoices/'.$id.'/payments',[
            'confirm'=>true,'amount'=>'50.00','payment_date'=>'2026-11-05',
            'method'=>'cash','request_key'=>'admin-invoice-payment-5000',
        ])->assertRedirect();
        $this->get('/admin/invoices/'.$id.'/edit')->assertOk()
            ->assertSee('Payment History');
        $this->assertDatabaseCount('service_invoice_payments',1);
    }

}
