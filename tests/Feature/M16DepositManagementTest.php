<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M16DepositManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name='M16 Deposits'): array
    {
        $user=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['owner_user_id'=>$user->id,'name'=>$name,
            'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Jean Dupont','email'=>'m16@example.test','source'=>'manual']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Deposit site work',
            'start_date'=>'2026-11-05','due_date'=>'2026-11-30',
            'team_member_ids'=>[$user->id],
        ])->assertCreated()->json('data.job.id');
        return [$user,$company,$client,$job];
    }

    private function fields(int $job,string $amount='123.45'): array
    {
        return ['job_id'=>$job,'client_name'=>'Jean Dupont',
            'deposit_date'=>'2026-11-05','payment_method'=>'bank_transfer',
            'reference_number'=>'WIRE-123','notes'=>'Manual bank collection',
            'amount'=>$amount];
    }

    public function test_creating_confirming_and_refunding_manual_deposit_preserves_history(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $id=(int)$this->postJson('/api/v1/deposits',$this->fields($job))
            ->assertCreated()->assertJsonPath('data.deposit.amount_minor',12345)
            ->assertJsonPath('data.deposit.status','pending')
            ->json('data.deposit.id');
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary')->assertOk()
            ->assertJsonPath('data.pending_minor',12345)
            ->assertJsonPath('data.received_minor',0);
        $this->postJson('/api/v1/deposits/'.$id.'/receive',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk()->assertJsonPath('data.deposit.status','received');
        $this->postJson('/api/v1/deposits/'.$id.'/receive',[
            'confirm'=>true,
        ])->assertStatus(409);
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary')->assertOk()
            ->assertJsonPath('data.pending_minor',0)
            ->assertJsonPath('data.received_minor',12345);
        $this->patchJson('/api/v1/deposits/'.$id,
            array_merge($this->fields($job),['lock_version'=>2]))
            ->assertStatus(409);
        $this->postJson('/api/v1/deposits/'.$id.'/refund',[
            'confirm'=>true,'lock_version'=>2,
            'reason'=>'Manual bank refund completed under wire 001.',
        ])->assertOk()->assertJsonPath('data.deposit.status','refunded');
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary')->assertOk()
            ->assertJsonPath('data.received_minor',0)
            ->assertJsonPath('data.refunded_minor',12345);
        $this->postJson('/api/v1/deposits/'.$id.'/refund',[
            'confirm'=>true,'reason'=>'Duplicate bank refund refused',
        ])->assertStatus(409);
        $this->getJson('/api/v1/deposits/'.$id)->assertOk()
            ->assertJsonCount(3,'data.history');
        $this->assertDatabaseCount('job_deposits',1);
    }

    public function test_pending_updates_require_version_and_void_retains_audit(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Concurrent');
        $id=(int)$this->postJson('/api/v1/deposits',$this->fields($job))
            ->assertCreated()->json('data.deposit.id');
        $this->patchJson('/api/v1/deposits/'.$id,
            array_merge($this->fields($job,'10.01'),['lock_version'=>1]))
            ->assertOk()->assertJsonPath('data.deposit.amount_minor',1001)
            ->assertJsonPath('data.deposit.lock_version',2);
        $this->patchJson('/api/v1/deposits/'.$id,
            array_merge($this->fields($job,'1.00'),['lock_version'=>1]))
            ->assertStatus(409);
        $this->deleteJson('/api/v1/deposits/'.$id)->assertOk()
            ->assertJsonPath('data.deposit.status','void');
        $this->getJson('/api/v1/deposits')->assertOk()
            ->assertJsonCount(0,'data.records');
        $this->getJson('/api/v1/deposits/'.$id)->assertOk()
            ->assertJsonPath('data.deposit.status','void')
            ->assertJsonCount(3,'data.history');
        $this->assertDatabaseCount('job_deposits',1);
    }

    public function test_approved_quote_capacity_and_partial_deposit_requests(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Quote Due');
        $quote=DB::table('service_quotes')->insertGetId([
            'company_id'=>$company->id,'client_id'=>$client->id,
            'service_job_id'=>$job,'reference_number'=>'Q-M16-001',
            'client_name'=>$client->full_name,'quote_date'=>'2026-11-05',
            'valid_until'=>'2026-12-05','currency'=>'CHF',
            'status'=>'approved','source_method'=>'manual',
            'revision'=>1,'approved_at'=>now(),
            'subtotal_minor'=>100000,'vat_minor'=>0,
            'total_minor'=>100000,'deposit_minor'=>30000,
            'deposit_basis_points'=>3000,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $partial=$this->fields($job,'100.00');
        $partial['quote_id']=$quote;
        $id=(int)$this->postJson('/api/v1/deposits',$partial)
            ->assertCreated()->json('data.deposit.id');
        $this->postJson('/api/v1/deposits/from-quote/'.$quote,[])
            ->assertCreated()->assertJsonPath('data.deposit.amount_minor',20000);
        $this->postJson('/api/v1/deposits/from-quote/'.$quote,[])
            ->assertStatus(409);
        $this->postJson('/api/v1/deposits',array_merge($partial,
            ['amount'=>'1.00']))->assertStatus(409);
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary?quote_id='.$quote)
            ->assertOk()->assertJsonPath('data.expected_quote_deposit_minor',30000)
            ->assertJsonPath('data.pending_minor',30000)
            ->assertJsonPath('data.unrequested_quote_deposit_minor',0)
            ->assertJsonPath('data.invoice_application_status','not_yet_integrated');
        $this->assertDatabaseCount('job_deposits',2);
        $this->assertDatabaseHas('job_deposits',['id'=>$id,
            'service_quote_id'=>$quote,'amount_minor'=>10000]);
    }

    public function test_cross_company_ids_and_incorrect_client_are_rejected(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('Tenant Alpha');
        $id=(int)$this->postJson('/api/v1/deposits',$this->fields($job))
            ->assertCreated()->json('data.deposit.id');
        $this->postJson('/api/v1/deposits',array_merge(
            $this->fields($job),['client_name'=>'Wrong Customer']))
            ->assertUnprocessable();
        [$other,$otherCompany,$otherClient,$otherJob]=$this->tenant('Tenant Beta');
        $this->getJson('/api/v1/deposits/'.$id)->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary')->assertNotFound();
        $this->postJson('/api/v1/deposits',array_merge(
            $this->fields($otherJob),['quote_id'=>null,'client_name'=>'Jean Dupont']))
            ->assertCreated();
        $this->patchJson('/api/v1/deposits/'.$id,
            array_merge($this->fields($otherJob),['lock_version'=>1]))
            ->assertNotFound();
    }

    public function test_private_receipt_integrity_and_admin_guards(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant('M16 Evidence');
        $id=(int)$this->postJson('/api/v1/deposits',$this->fields($job))
            ->assertCreated()->json('data.deposit.id');
        $proof=(int)$this->post('/api/v1/deposits/'.$id.'/receipts',[
            'file'=>UploadedFile::fake()->image('wire.png',240,120),
        ],['Accept'=>'application/json'])->assertCreated()
            ->json('data.id');
        $this->get('/api/v1/deposits/'.$id.'/receipts/'.$proof)
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->get('/admin/deposits')->assertForbidden();
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $this->get('/admin/deposits')->assertOk()->assertSee('Deposit Management');
        $this->get('/admin/deposits/'.$id.'/edit')->assertOk()
            ->assertSee('Verified Original');
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->postJson('/api/v1/deposits/'.$id.'/receive',['confirm'=>true])
            ->assertOk();
        $this->post('/api/v1/deposits/'.$id.'/receipts',[
            'file'=>UploadedFile::fake()->image('late.png',100,100),
        ],['Accept'=>'application/json'])->assertStatus(409);
        $saved=DB::table('job_deposit_receipts')->find($proof);
        Storage::disk('local')->put($saved->private_path,'tampered');
        $this->get('/api/v1/deposits/'.$id.'/receipts/'.$proof)
            ->assertStatus(409);
    }

    public function test_non_manager_cannot_confirm_manual_money(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Roles');
        $id=(int)$this->postJson('/api/v1/deposits',$this->fields($job))
            ->assertCreated()->json('data.deposit.id');
        $member=User::factory()->create(['is_active'=>true,
            'email_verified_at'=>now()]);
        $company->users()->attach($member->id,[
            'role'=>'member','status'=>'active',
            'permissions'=>json_encode(['jobs.read','documents.read','documents.write']),
        ]);
        Sanctum::actingAs($member);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->postJson('/api/v1/deposits/'.$id.'/receive',[
            'confirm'=>true,
        ])->assertForbidden();
        $this->postJson('/api/v1/deposits/'.$id.'/refund',[
            'confirm'=>true,'reason'=>'Wrongly attempted refund',
        ])->assertForbidden();
    }

    public function test_deposit_voice_produces_uncommitted_draft_without_exposing_api_key(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Voice Privacy');
        config()->set('services.openai.key','m16-backend-only-test-key');
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions'=>Http::response([
                'text'=>'Deposit one hundred francs for kitchen work by bank transfer.',
            ],200),
            'https://api.openai.com/v1/chat/completions'=>Http::response([
                'choices'=>[['message'=>['content'=>json_encode([
                    'jobReference'=>'Kitchen work','clientName'=>'Jean Dupont',
                    'amount'=>100,'depositDate'=>'2026-02-30',
                    'paymentMethod'=>'bank_transfer','referenceNumber'=>'BK-89',
                    'notes'=>'Bank transfer recorded',
                    'received'=>true,'saved'=>true,
                ])]]],
            ],200),
        ]);
        // A minimal valid PCM WAV header with no client-side API key.
        $audio='RIFF'.pack('V',36).'WAVEfmt '.pack('V',16).
            pack('v',1).pack('v',1).pack('V',8000).pack('V',8000).
            pack('v',1).pack('v',8).'data'.pack('V',0);
        $this->post('/api/v1/deposits/ai/voice',[
            'file'=>UploadedFile::fake()->createWithContent('deposit.wav',$audio),
        ],['Accept'=>'application/json'])->assertOk()
            ->assertJsonPath('data.suggestion.amount',100)
            ->assertJsonPath('data.suggestion.depositDate',null)
            ->assertJsonPath('data.suggestion.saved',false)
            ->assertJsonPath('data.suggestion.received',false)
            ->assertJsonPath('data.suggestion.refunded',false);
        $this->assertDatabaseCount('job_deposits',0);
        Http::assertSentCount(2);
        Http::assertSent(fn($request)=>
            str_contains($request->url(),'api.openai.com') &&
            $request->hasHeader('Authorization',
                'Bearer m16-backend-only-test-key'));
    }

    public function test_deposit_voice_rejects_invalid_content_before_ai_request(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Voice Validation');
        config()->set('services.openai.key','m16-backend-only-test-key');
        Http::fake();
        $this->post('/api/v1/deposits/ai/voice',[
            'file'=>UploadedFile::fake()->createWithContent(
                'deposit.wav',str_repeat('not real WAV data',4)),
        ],['Accept'=>'application/json'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->assertDatabaseCount('job_deposits',0);
    }

}
