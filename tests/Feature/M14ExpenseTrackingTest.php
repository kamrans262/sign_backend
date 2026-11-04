<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M14ExpenseTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name='M14 Tenant'): array
    {
        $user=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['owner_user_id'=>$user->id,
            'name'=>$name,'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Expense Customer','email'=>'expenses@example.test','source'=>'manual']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Kitchen work',
            'start_date'=>'2026-11-05','due_date'=>'2026-11-30',
            'team_member_ids'=>[],
        ])->assertCreated()->json('data.job.id');
        return [$user,$company,$client,$job];
    }

    private function create(int $job,string $amount='12.30'): int
    {
        return (int)$this->postJson('/api/v1/expenses',[
            'job_id'=>$job,'category'=>'material','supplier'=>'Local Materials',
            'description'=>'Door fitting','amount'=>$amount,
            'expense_date'=>'2026-11-05','tags'=>['hardware'],
        ])->assertCreated()->assertJsonPath('data.expense.status','to_review')
            ->json('data.expense.id');
    }

    public function test_precise_amount_job_totals_company_isolation_and_version_lock(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant();
        $first=$this->create($job,'19.99');
        $second=$this->create($job,'0.01');
        $this->getJson('/api/v1/jobs/'.$job.'/expense-totals')->assertOk()
            ->assertJsonPath('data.total_expenses_minor',2000)
            ->assertJsonPath('data.budget_minor',null);
        $this->getJson('/api/v1/expenses?job_id='.$job)->assertOk()
            ->assertJsonCount(2,'data.records');
        $this->patchJson('/api/v1/expenses/'.$first,[
            'job_id'=>$job,'category'=>'material','expense_date'=>'2026-11-06',
            'amount'=>'20.00','lock_version'=>1,
        ])->assertOk()->assertJsonPath('data.expense.amount_minor',2000)
            ->assertJsonPath('data.expense.lock_version',2);
        $this->patchJson('/api/v1/expenses/'.$first,[
            'job_id'=>$job,'category'=>'material','expense_date'=>'2026-11-06',
            'amount'=>'100.00','lock_version'=>1,
        ])->assertStatus(409);
        $this->postJson('/api/v1/expenses',[
            'job_id'=>$job,'category'=>'fuel','expense_date'=>'2026-11-05',
            'amount'=>'1.999',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/jobs/'.$job.'/expense-totals')->assertOk()
            ->assertJsonPath('data.total_expenses_minor',2001);
        [$other,$otherCompany]=$this->tenant('Different Tenant');
        $this->getJson('/api/v1/expenses/'.$first)->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$job.'/expense-totals')->assertNotFound();
        $this->patchJson('/api/v1/expenses/'.$first,[
            'job_id'=>$job,'category'=>'fuel','expense_date'=>'2026-11-05',
            'amount'=>'4.00','lock_version'=>2,
        ])->assertNotFound();
    }

    public function test_original_receipt_integrity_and_review_freeze(): void
    {
        Storage::fake('local');
        [$user,$company,$client,$job]=$this->tenant('Receipts Tenant');
        $id=$this->create($job);
        $uploaded=$this->post('/api/v1/expenses/'.$id.'/receipts',[
            'file'=>UploadedFile::fake()->image('receipt.png',240,100),
        ],['Accept'=>'application/json'])->assertCreated();
        $receipt=$uploaded->json('data.id');
        $this->get('/api/v1/expenses/'.$id.'/receipts/'.$receipt)
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->getJson('/api/v1/expenses/'.$id)->assertOk()
            ->assertJsonCount(1,'data.receipts')
            ->assertJsonPath('data.expense.amount_minor',1230);
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $this->get('/admin/expenses')->assertOk()->assertSee('Expenses Management');
        $this->get('/admin/expenses/'.$id.'/edit')->assertOk()
            ->assertSee('Local Materials')->assertSee('Verified Original');
        $this->post('/admin/expenses/'.$id.'/approve',['confirm'=>'1'])
            ->assertRedirect();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/expenses/'.$id,[
            'job_id'=>$job,'category'=>'material','expense_date'=>'2026-11-05',
            'amount'=>'1.00','lock_version'=>1,
        ])->assertStatus(409);
        $this->deleteJson('/api/v1/expenses/'.$id.'/receipts/'.$receipt)
            ->assertStatus(409);
        $this->postJson('/api/v1/expenses/'.$id.'/request-reimbursement',[
            'confirm'=>true,
        ])->assertOk()
            ->assertJsonPath('data.expense.status','approved');
        $this->postJson('/api/v1/expenses/'.$id.'/request-reimbursement',[
            'confirm'=>true,
        ])->assertStatus(409);
        $this->actingAs($admin);
        $this->post('/admin/expenses/'.$id.'/reimburse',['confirm'=>'1'])
            ->assertRedirect();
        $this->assertDatabaseHas('job_expenses',['id'=>$id,'status'=>'reimbursed']);
        $this->assertDatabaseCount('job_expense_receipts',1);
        $this->assertDatabaseCount('job_expense_events',5);
    }

    public function test_private_receipt_tampering_is_detected(): void
    {
        Storage::fake('local');
        [$user,$company,$client,$job]=$this->tenant('Integrity Tenant');
        $id=$this->create($job);
        $reply=$this->post('/api/v1/expenses/'.$id.'/receipts',[
            'file'=>UploadedFile::fake()->image('receipt.png',240,100),
        ],['Accept'=>'application/json'])->assertCreated();
        $r=DB::table('job_expense_receipts')->find($reply->json('data.id'));
        Storage::disk('local')->put($r->private_path,'tampered bytes');
        $this->get('/api/v1/expenses/'.$id.'/receipts/'.$r->id)->assertStatus(409);
    }
    public function test_ai_receipt_never_saves_and_rejects_impossible_dates(): void
    {
        Storage::fake('local');
        [$user,$company,$client,$job]=$this->tenant('AI Receipt Tenant');
        config()->set('services.openai.key','m14-test-only-key');
        Http::fake(['api.openai.com/*'=>Http::response([
            'choices'=>[['message'=>['content'=>json_encode([
                'date'=>'2026-02-30','amount'=>39.75,'supplier'=>'Receipt Shop',
                'expenseType'=>'Fuel','description'=>'Invoice 123',
                'tags'=>['vehicle'],'jobReference'=>'JOB-123',
            ],JSON_THROW_ON_ERROR)]],
        ],200)]);

        $this->post('/api/v1/expenses/ai/receipt',[
            'file'=>UploadedFile::fake()->image('purchase.png',320,180),
        ],['Accept'=>'application/json'])
            ->assertOk()->assertJsonPath('data.suggestion.date',null)
            ->assertJsonPath('data.suggestion.amount',39.75)
            ->assertJsonPath('data.suggestion.supplier','Receipt Shop')
            ->assertJsonPath('data.suggestion.expenseType','Fuel')
            ->assertJsonPath('data.suggestion.saved',false)
            ->assertJsonPath('data.suggestion.approved',false);
        Http::assertSent(fn($r)=>str_contains($r->url(),
            'api.openai.com/v1/chat/completions')&&
            $r->hasHeader('Authorization','Bearer m14-test-only-key'));
        $this->assertDatabaseCount('job_expenses',0);
        $this->assertDatabaseCount('job_expense_receipts',0);
    }

    public function test_receipt_removal_tenant_guard_and_audit_retention(): void
    {
        Storage::fake('local');
        [$user,$company,$client,$job]=$this->tenant('Retention Tenant');
        $expense=$this->create($job);
        $receipt=(int)$this->post('/api/v1/expenses/'.$expense.'/receipts',[
            'file'=>UploadedFile::fake()->image('proof.png',240,100),
        ],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $item=DB::table('job_expense_receipts')->find($receipt);
        $this->assertNotNull($item);
        $this->assertTrue(Storage::disk('local')->exists($item->private_path));
        [$other,$otherCompany]=$this->tenant('Foreign Company');
        $this->get('/api/v1/expenses/'.$expense.'/receipts/'.$receipt)
            ->assertNotFound();
        $this->deleteJson('/api/v1/expenses/'.$expense.'/receipts/'.$receipt)
            ->assertNotFound();

        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->deleteJson('/api/v1/expenses/'.$expense.'/receipts/'.$receipt)
            ->assertOk()->assertJsonCount(0,'data.receipts');
        $this->get('/api/v1/expenses/'.$expense.'/receipts/'.$receipt)
            ->assertNotFound();
        $this->assertDatabaseHas('job_expense_receipts',[
            'id'=>$receipt,'company_id'=>$company->id,
            'sha256'=>$item->sha256,
        ]);
        $this->assertTrue(Storage::disk('local')->exists($item->private_path));
        $this->assertDatabaseHas('job_expense_events',[
            'job_expense_id'=>$expense,'event_type'=>'receipt_removed',
        ]);
    }

    public function test_partial_update_preserves_tags_and_non_admin_cannot_approve(): void
    {
        Storage::fake('local');
        [$user,$company,$client,$job]=$this->tenant('Tags Tenant');
        $id=$this->create($job);
        $this->patchJson('/api/v1/expenses/'.$id,[
            'job_id'=>$job,'category'=>'material',
            'expense_date'=>'2026-11-05','amount'=>'13.50',
            'lock_version'=>1,
        ])->assertOk()->assertJsonPath('data.expense.tags',['hardware']);
        $this->get('/admin/expenses/'.$id.'/edit')->assertForbidden();
        $this->post('/admin/expenses/'.$id.'/approve',['confirm'=>'1'])
            ->assertForbidden();
        $this->assertDatabaseHas('job_expenses',[
            'id'=>$id,'status'=>'to_review','lock_version'=>2,
        ]);
    }

}
