<?php

namespace Tests\Feature;

use App\Mail\EquipmentLoanReminderMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\EquipmentLoanReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M19EquipmentLoanStage2Test extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(): array
    {
        Carbon::setTestNow('2026-11-05 12:00:00 UTC');
        $actor=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create([
            'owner_user_id'=>$actor->id,'name'=>'Lender Ltd',
            'country_iso_code'=>'CH','status'=>'active',
        ]);
        $company->users()->attach($actor->id,['role'=>'owner','status'=>'active']);
        Sanctum::actingAs($actor);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $asset=$this->postJson('/api/v1/equipment',[
            'code'=>'EQ-M19-2','name'=>'Mini Excavator',
            'category'=>'Excavation','brand'=>'Kubota','model'=>'U27',
            'purchase_date'=>'2026-03-01','status'=>'available',
        ])->assertCreated()->json('data.asset');
        $loan=$this->postJson('/api/v1/equipment-loans',[
            'equipment_asset_id'=>$asset['id'],
            'borrower_type'=>'external','borrower_name'=>'M19 Recipient',
            'borrower_email'=>'recipient@example.test',
            'loan_date'=>'2026-10-01',
            'expected_return_date'=>'2026-10-10',
            'request_key'=>'stage2-create-loan-key',
        ])->assertCreated()->json('data.loan');
        return [$actor,$company,$asset,$loan];
    }

    private function mailReady(): void
    {
        config([
            'mail.default'=>'smtp',
            'mail.from.address'=>'noreply@example.test',
            'equipment.automatic_enabled'=>false,
        ]);
        Mail::fake();
    }

    private function policy(int $loan,int $version=1,
        bool $enabled=true,bool $confirmed=true,int $freq=7): array
    {
        return $this->putJson('/api/v1/equipment-loans/'.$loan.'/reminders/policy',[
            'lock_version'=>$version,
            'automatic_enabled'=>$enabled,
            'recipient_confirmed'=>$confirmed,
            'frequency_days'=>$freq,
        ])->assertOk()->json('data');
    }

    private function send(int $loan,int $version=1,
        string $key='m19-send-key-01')
    {
        return $this->postJson('/api/v1/equipment-loans/'.$loan.'/reminders/send',[
            'lock_version'=>$version,'confirm'=>true,
            'recipient_confirmed'=>true,'request_key'=>$key,
        ]);
    }

    public function test_default_is_off_and_unsafe_transports_cannot_submit_email(): void
    {
        [,,$asset,$loan]=$this->tenant();
        config(['mail.default'=>'log','mail.from.address'=>'noreply@example.test']);
        Mail::fake();
        $preview=$this->getJson('/api/v1/equipment-loans/'.$loan['id'].
            '/reminders/preview')->assertOk()->json('data');
        $this->assertFalse($preview['transport_ready']);
        $this->assertFalse($preview['automatic_enabled']);
        $this->assertFalse($preview['send_available']);
        $this->send($loan['id'])->assertStatus(503);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('equipment_loan_reminders',0);
        $this->assertDatabaseCount('equipment_loan_delivery_attempts',0);
    }

    public function test_manual_send_requires_confirmation_and_is_strictly_idempotent(): void
    {
        [$actor,$company,$asset,$loan]=$this->tenant();
        $this->mailReady();
        $id=$loan['id'];
        $this->postJson('/api/v1/equipment-loans/'.$id.'/reminders/send',[
            'lock_version'=>1,'confirm'=>true,
            'request_key'=>'invalid-no-consent',
        ])->assertStatus(422);
        Mail::assertNothingSent();
        $first=$this->send($id)->assertOk()->json('data.reminder');
        $this->assertSame('submitted',$first['status']);
        $this->assertSame(1,$first['attempt_count']);
        $this->assertStringContainsString('Inbox delivery is not confirmed',
            $first['delivery_note']);
        $second=$this->send($id)->assertOk()->json('data.reminder');
        $this->assertSame($first['id'],$second['id']);
        Mail::assertSent(EquipmentLoanReminderMail::class,1);
        Mail::assertSent(EquipmentLoanReminderMail::class,
            function(EquipmentLoanReminderMail $mail):bool{
                return $mail->borrowerName==='M19 Recipient'
                    && $mail->expectedReturnDate==='2026-10-10'
                    && $mail->hasTo('recipient@example.test');
            });
        $this->assertDatabaseCount('equipment_loan_reminders',1);
        $this->assertDatabaseCount('equipment_loan_delivery_attempts',1);
        $history=$this->getJson('/api/v1/equipment-loans/'.$id.'/reminders')
            ->assertOk()->json('data.reminders');
        $this->assertCount(1,$history);
        $this->assertSame('submitted',$history[0]['status']);
        $this->assertDatabaseHas('equipment_loan_events',[
            'company_id'=>$company->id,'equipment_loan_id'=>$id,
            'event_type'=>'reminder_submitted',
        ]);
    }

    public function test_mailer_exception_is_unknown_and_retry_does_not_resend(): void
    {
        [,,$asset,$loan]=$this->tenant();
        config([
            'mail.default'=>'smtp',
            'mail.from.address'=>'noreply@example.test',
        ]);
        Mail::shouldReceive('to')->once()->andThrow(
            new \RuntimeException('Simulated accepted-then-timeout'));
        $result=$this->send($loan['id'])->assertOk()->json('data.reminder');
        $this->assertSame('unknown',$result['status']);
        $this->assertSame(1,$result['attempt_count']);
        $retry=$this->send($loan['id'])->assertOk()->json('data.reminder');
        $this->assertSame($result['id'],$retry['id']);
        $this->assertDatabaseCount('equipment_loan_delivery_attempts',1);
        $this->assertDatabaseHas('equipment_loan_delivery_attempts',[
            'equipment_loan_reminder_id'=>$result['id'],
            'status'=>'unknown','error_code'=>'transport_outcome_uncertain',
        ]);
    }

    public function test_user_opt_in_is_explicit_and_global_sending_is_off_by_default(): void
    {
        [,,$asset,$loan]=$this->tenant();
        $this->mailReady();
        $id=$loan['id'];
        $this->putJson('/api/v1/equipment-loans/'.$id.'/reminders/policy',[
            'lock_version'=>1,'automatic_enabled'=>true,
            'recipient_confirmed'=>false,'frequency_days'=>7,
        ])->assertStatus(422);
        $preview=$this->policy($id,1,true,true,7);
        $this->assertTrue($preview['automatic_enabled']);
        $this->assertFalse($preview['automatic_global_enabled']);
        $this->assertSame(2,$preview['loan_version']);
        $result=app(EquipmentLoanReminderService::class)
            ->processAutomatic(20,true);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0,$result['submitted']);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('equipment_loan_reminders',0);
    }

    public function test_overdue_automatic_email_sends_once_per_period_and_can_opt_out(): void
    {
        [,,$asset,$loan]=$this->tenant();
        $this->mailReady();
        $this->policy($loan['id'],1,true,true,7);
        config(['equipment.automatic_enabled'=>true]);
        $reminders=app(EquipmentLoanReminderService::class);
        $dry=$reminders->processAutomatic(20,false);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(1,$dry['eligible_count']);
        $this->assertSame(1,$reminders->processAutomatic(20,true)['submitted']);
        $this->assertSame(0,$reminders->processAutomatic(20,true)['submitted']);
        Mail::assertSent(EquipmentLoanReminderMail::class,1);
        $this->assertDatabaseCount('equipment_loan_reminders',1);
        $preview=$this->getJson('/api/v1/equipment-loans/'.$loan['id'].
            '/reminders/preview')->assertOk()->json('data');
        $this->assertTrue($preview['automatic_enabled']);
        $this->policy($loan['id'],2,false,false);
        $this->assertSame(0,count($reminders->automaticCandidates()));
        $this->assertSame(0,$reminders->processAutomatic(20,true)['submitted']);
        Mail::assertSent(EquipmentLoanReminderMail::class,1);
    }

    public function test_extend_and_return_invalidate_pending_reminders_without_erasing_sent(): void
    {
        [,,$asset,$loan]=$this->tenant();
        $this->mailReady();
        $id=$loan['id'];
        $sent=$this->send($id)->assertOk()->json('data.reminder');
        $this->insertPending($loan,'manual-pending-1');
        $extended=$this->postJson('/api/v1/equipment-loans/'.$id.'/extend',[
            'lock_version'=>1,'confirm'=>true,
            'expected_return_date'=>'2026-12-20',
        ])->assertOk()->json('data.loan');
        $this->assertSame('active',$extended['status']);
        $this->assertDatabaseHas('equipment_loan_reminders',[
            'request_key'=>'manual-pending-1','status'=>'cancelled',
        ]);
        $this->assertDatabaseHas('equipment_loan_reminders',[
            'id'=>$sent['id'],'status'=>'submitted',
        ]);
        $this->insertPending($extended,'manual-pending-2');
        $this->postJson('/api/v1/equipment-loans/'.$id.'/return',[
            'lock_version'=>2,'confirm'=>true,
            'actual_return_date'=>'2026-11-05',
        ])->assertOk();
        $this->assertDatabaseHas('equipment_loan_reminders',[
            'request_key'=>'manual-pending-2','status'=>'cancelled',
        ]);
        $this->send($id,3,'new-after-return')->assertStatus(409);
        Mail::assertSent(EquipmentLoanReminderMail::class,1);
    }

    private function insertPending(array $loan,string $key): void
    {
        DB::table('equipment_loan_reminders')->insert([
            'company_id'=>$loan['company_id'],
            'equipment_loan_id'=>$loan['id'],
            'requested_by'=>null,'kind'=>'manual',
            'due_date'=>$loan['expected_return_date'],
            'loan_version'=>$loan['lock_version'],
            'recipient_email'=>'recipient@example.test',
            'request_key'=>$key,'request_fingerprint'=>str_repeat('a',64),
            'status'=>'pending','attempt_count'=>0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function test_changing_recipient_revokes_automatic_permission(): void
    {
        [,,$asset,$loan]=$this->tenant();
        $this->mailReady();
        $this->policy($loan['id'],1,true,true);
        $this->patchJson('/api/v1/equipment-loans/'.$loan['id'],[
            'lock_version'=>2,
            'borrower_email'=>'different@example.test',
        ])->assertOk();
        $this->assertDatabaseHas('equipment_loan_reminder_settings',[
            'equipment_loan_id'=>$loan['id'],
            'automatic_enabled'=>0,'recipient_confirmed'=>0,
        ]);
        config(['equipment.automatic_enabled'=>true]);
        $this->assertSame(0,count(app(EquipmentLoanReminderService::class)
            ->automaticCandidates()));
    }

    public function test_other_company_cannot_view_policy_or_send_or_replay(): void
    {
        [$actor,$company,$asset,$loan]=$this->tenant();
        $this->mailReady();
        $other=Company::create([
            'owner_user_id'=>$actor->id,
            'name'=>'Second Company','country_iso_code'=>'CH',
            'status'=>'active',
        ]);
        $other->users()->attach($actor->id,['role'=>'owner','status'=>'active']);
        $this->withHeader('X-Company-Id',(string)$other->id);
        $this->getJson('/api/v1/equipment-loans/'.$loan['id'].
            '/reminders/preview')->assertNotFound();
        $this->send($loan['id'])->assertNotFound();
        $this->putJson('/api/v1/equipment-loans/'.$loan['id'].
            '/reminders/policy',[
                'lock_version'=>1,'automatic_enabled'=>true,
                'recipient_confirmed'=>true,'frequency_days'=>7,
            ])->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_timezone_boundary_uses_swiss_business_day(): void
    {
        [,,$asset,$loan]=$this->tenant();
        // Loan due 2026-10-10. Test today's Swiss boundary independently.
        DB::table('equipment_loans')->where('id',$loan['id'])
            ->update(['expected_return_date'=>'2026-11-05']);
        Carbon::setTestNow('2026-11-05 22:30:00 UTC');
        $this->getJson('/api/v1/equipment-loans?status=overdue')
            ->assertOk()->assertJsonCount(0,'data.records');
        Carbon::setTestNow('2026-11-05 23:30:00 UTC');
        $this->getJson('/api/v1/equipment-loans?status=overdue')
            ->assertOk()->assertJsonCount(1,'data.records');
    }

    public function test_admin_exposes_reminder_policy_and_audited_history(): void
    {
        [,,$asset,$loan]=$this->tenant();
        $platform=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($platform);
        $this->get('/admin/equipment-loans/'.$loan['id'].'/edit')
            ->assertOk()->assertSee('Send Reminder Email')
            ->assertSee('Reminder Delivery History');
        $this->put('/admin/equipment-loans/'.$loan['id'].'/reminders/policy',[
            'lock_version'=>1,
            'automatic_enabled'=>true,'recipient_confirmed'=>true,
            'frequency_days'=>7,
        ])->assertRedirect();
        $this->assertDatabaseHas('equipment_loan_reminder_settings',[
            'equipment_loan_id'=>$loan['id'],'automatic_enabled'=>1,
        ]);
        $this->get('/admin/equipment-loans/'.$loan['id'].'/edit')
            ->assertOk()->assertSee('automatic');
    }
}
