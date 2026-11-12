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

    public function test_custom_payment_method_is_saved_without_replacing_notes(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Custom Method');
        $request=array_merge($this->fields($job),[
            'payment_method'=>'other',
            'payment_method_custom'=>'Mobile cheque pickup',
        ]);
        $id=(int)$this->postJson('/api/v1/deposits',$request)
            ->assertCreated()
            ->assertJsonPath('data.deposit.payment_method','other')
            ->assertJsonPath('data.deposit.payment_method_custom','Mobile cheque pickup')
            ->json('data.deposit.id');
        $this->getJson('/api/v1/deposits/'.$id)->assertOk()
            ->assertJsonPath('data.deposit.payment_method_custom','Mobile cheque pickup')
            ->assertJsonPath('data.deposit.notes','Manual bank collection');
        $this->patchJson('/api/v1/deposits/'.$id,array_merge($request,[
            'lock_version'=>1,'payment_method_custom'=>'Cash app transfer',
        ]))->assertOk()
            ->assertJsonPath('data.deposit.payment_method_custom','Cash app transfer');
        $this->assertDatabaseHas('job_deposits',[
            'id'=>$id,'payment_method'=>'other',
            'payment_method_custom'=>'Cash app transfer',
        ]);
        $this->postJson('/api/v1/deposits',array_merge($this->fields($job),[
            'payment_method_custom'=>'Invalid alongside bank transfer',
        ]))->assertUnprocessable();
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
        $quoteKey=['request_key'=>'quote-request-20261105-001'];
        $made=$this->postJson('/api/v1/deposits/from-quote/'.$quote,$quoteKey)
            ->assertCreated()->assertJsonPath('data.deposit.amount_minor',20000)
            ->json('data.deposit.id');
        $this->postJson('/api/v1/deposits/from-quote/'.$quote,$quoteKey)
            ->assertCreated()->assertJsonPath('data.deposit.id',$made);
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


    public function test_idempotent_retry_never_duplicates_a_pending_deposit_or_event(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Retry');
        $payload=array_merge($this->fields($job,'89.23'),[
            'request_key'=>'mobile-local-deposit-8040',
        ]);
        $first=$this->postJson('/api/v1/deposits',$payload)
            ->assertCreated()->assertJsonPath('data.deposit.amount_minor',8923)
            ->json('data.deposit.id');
        $second=$this->postJson('/api/v1/deposits',$payload)
            ->assertCreated()->json('data.deposit.id');
        $this->assertSame($first,$second);
        $this->assertDatabaseCount('job_deposits',1);
        $this->assertSame(1,DB::table('job_deposit_events')
            ->where('job_deposit_id',$first)->where('event_type','created')->count());
        $this->postJson('/api/v1/deposits',array_merge($payload,[
            'amount'=>'90.01',
        ]))->assertStatus(409);
        $this->postJson('/api/v1/deposits',array_merge($payload,[
            'request_key'=>'not valid key',
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('job_deposits',1);
    }

    public function test_idempotency_key_is_company_scoped_and_does_not_leak_old_tenant(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Key Company A');
        $key='shared-device-request-1001';
        $first=(int)$this->postJson('/api/v1/deposits',array_merge(
            $this->fields($job),['request_key'=>$key]))
            ->assertCreated()->json('data.deposit.id');
        [$other,$otherCompany,$otherClient,$otherJob]=$this->tenant('M16 Key Company B');
        $second=(int)$this->postJson('/api/v1/deposits',array_merge(
            $this->fields($otherJob),['request_key'=>$key]))
            ->assertCreated()->json('data.deposit.id');
        $this->assertNotSame($first,$second);
        $this->assertDatabaseCount('job_deposits',2);
        $this->getJson('/api/v1/deposits/'.$first)->assertNotFound();
        $this->getJson('/api/v1/deposits/'.$second)
            ->assertOk()->assertJsonPath('data.deposit.company_id',$otherCompany->id);
    }

    public function test_retried_receipt_upload_uses_one_private_original_and_event(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant('M16 Receipt Retry');
        $id=(int)$this->postJson('/api/v1/deposits',array_merge(
            $this->fields($job),['request_key'=>'receipt-request-2001']))
            ->assertCreated()->json('data.deposit.id');
        $original=UploadedFile::fake()->image('confirmation.png',80,80);
        $bytes=file_get_contents($original->getRealPath());
        $first=(int)$this->post('/api/v1/deposits/'.$id.'/receipts',[
            'file'=>$original,
        ],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $second=(int)$this->post('/api/v1/deposits/'.$id.'/receipts',[
            'file'=>UploadedFile::fake()->createWithContent('same.png',$bytes),
        ],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $this->assertSame($first,$second);
        $this->assertSame(1,DB::table('job_deposit_receipts')->count());
        $this->assertSame(1,DB::table('job_deposit_events')
            ->where('event_type','receipt_added')->count());
        $this->assertCount(1,Storage::disk('local')->allFiles(
            'deposits/'.$company->id.'/'.$id));
        $this->postJson('/api/v1/deposits/'.$id.'/receive',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk();
        $this->post('/api/v1/deposits/'.$id.'/receipts',[
            'file'=>UploadedFile::fake()->createWithContent('same.png',$bytes),
        ],['Accept'=>'application/json'])->assertStatus(409);
        $this->assertDatabaseCount('job_deposit_receipts',1);
    }

    public function test_admin_export_includes_more_than_one_page_and_escapes_formulas(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 CSV Export');
        $rows=[];
        for($i=0;$i<121;$i++)$rows[]=[
            'company_id'=>$company->id,'service_job_id'=>$job,
            'client_name_snapshot'=>'Jean Dupont',
            'amount_minor'=>100,'currency'=>'CHF','deposit_date'=>'2026-11-05',
            'payment_method'=>'bank_transfer',
            'reference_number'=>$i===120?' =2+2':'WIRE-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),
            'status'=>'pending','lock_version'=>1,
            'created_at'=>now(),'updated_at'=>now(),
        ];
        DB::table('job_deposits')->insert($rows);
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $csv=$this->get('/admin/deposits/export?company_id='.$company->id)
            ->assertOk()->streamedContent();
        $this->assertSame(122,count(preg_split('/\r?\n/',trim($csv))));
        $this->assertStringContainsString("' =2+2",$csv);
        $this->assertStringContainsString('WIRE-000',$csv);
        $this->assertStringContainsString('WIRE-119',$csv);
    }

    public function test_refund_state_cannot_overstate_received_job_balances(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Financial Replay');
        $key='mobile-replay-20261105';
        $p=array_merge($this->fields($job,'29.99'),['request_key'=>$key]);
        $id=(int)$this->postJson('/api/v1/deposits',$p)->assertCreated()
            ->json('data.deposit.id');
        $this->postJson('/api/v1/deposits/'.$id.'/receive',[
            'confirm'=>true,'lock_version'=>1,
        ])->assertOk();
        // HTTP retry after money is confirmed must not create another request.
        $this->postJson('/api/v1/deposits',$p)
            ->assertCreated()->assertJsonPath('data.deposit.status','received');
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary')
            ->assertJsonPath('data.net_received_minor',2999);
        $this->postJson('/api/v1/deposits/'.$id.'/refund',[
            'confirm'=>true,'lock_version'=>2,
            'reason'=>'Bank reference RF-2026-143 refund completed.',
        ])->assertOk();
        $this->postJson('/api/v1/deposits',$p)
            ->assertCreated()->assertJsonPath('data.deposit.status','refunded');
        $this->getJson('/api/v1/jobs/'.$job.'/deposit-summary')->assertOk()
            ->assertJsonPath('data.net_received_minor',0)
            ->assertJsonPath('data.refunded_minor',2999);
        $this->assertDatabaseCount('job_deposits',1);
    }


    public function test_admin_manual_form_uses_retry_key_and_never_creates_twice(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('M16 Admin Retry');
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->get('/admin/deposits/create')->assertOk()
            ->assertSee('name="request_key"',false);
        $payload=array_merge($this->fields($job,'43.20'),[
            'company_id'=>$company->id,'request_key'=>'admin-form-key-20261105',
        ]);
        $this->post('/admin/deposits',$payload)->assertRedirect();
        $this->post('/admin/deposits',$payload)->assertRedirect();
        $this->assertDatabaseCount('job_deposits',1);
        $this->assertDatabaseHas('job_deposits',[
            'company_id'=>$company->id,'amount_minor'=>4320,
            'request_key'=>'admin-form-key-20261105',
        ]);
        $this->post('/admin/deposits',array_merge($payload,[
            'amount'=>'45.00',
        ]))->assertStatus(409);
        $this->assertDatabaseCount('job_deposits',1);
    }

}
