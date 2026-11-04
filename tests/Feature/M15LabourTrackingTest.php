<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\LabourService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M15LabourTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(string $name='Time & Labour'): array
    {
        $owner=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company=Company::create(['owner_user_id'=>$owner->id,
            'name'=>$name,'country_iso_code'=>'CH','status'=>'active']);
        $company->users()->attach($owner->id,['role'=>'owner','status'=>'active']);
        $client=Client::create(['company_id'=>$company->id,
            'full_name'=>'Labour Customer','email'=>'m15@example.test','source'=>'manual']);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $job=(int)$this->postJson('/api/v1/jobs',[
            'client_id'=>$client->id,'description'=>'Site renovation',
            'start_date'=>'2026-11-05','due_date'=>'2026-11-30',
            'team_member_ids'=>[$owner->id],
        ])->assertCreated()->json('data.job.id');
        return [$owner,$company,$client,$job];
    }

    private function entry(int $worker,int $job,
        string $rate='60.00',int $start=480,int $end=570): array
    {
        return [
            'job_id'=>$job,'worker_id'=>$worker,'kind'=>'labour',
            'worker_role'=>'Carpenter','task'=>'Window assembly',
            'work_date'=>'2026-11-05',
            'start_minutes'=>$start,'end_minutes'=>$end,
            'hourly_rate'=>$rate,
        ];
    }

    public function test_manual_entry_exact_cost_approval_and_immutable_history(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant();
        $id=(int)$this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job,'12.34'))
            ->assertCreated()->assertJsonPath('data.entry.duration_minutes',90)
            ->assertJsonPath('data.entry.hourly_rate_minor',1234)
            ->assertJsonPath('data.entry.cost_minor',1851)
            ->assertJsonPath('data.entry.status','pending')
            ->json('data.entry.id');
        $this->getJson('/api/v1/jobs/'.$job.'/labour-summary')->assertOk()
            ->assertJsonPath('data.pending_minutes',90)
            ->assertJsonPath('data.approved_minutes',0);
        $this->patchJson('/api/v1/labour/entries/'.$id,array_merge(
            $this->entry($owner->id,$job,'20.00'),['lock_version'=>1],
        ))->assertOk()->assertJsonPath('data.entry.cost_minor',3000)
            ->assertJsonPath('data.entry.lock_version',2);
        $this->patchJson('/api/v1/labour/entries/'.$id,array_merge(
            $this->entry($owner->id,$job,'20.00'),['lock_version'=>1],
        ))->assertStatus(409);
        $this->postJson('/api/v1/labour/entries/'.$id.'/approve',[
            'confirm'=>true,
        ])->assertOk()->assertJsonPath('data.entry.status','approved');
        $this->patchJson('/api/v1/labour/entries/'.$id,array_merge(
            $this->entry($owner->id,$job,'1.00'),['lock_version'=>3],
        ))->assertStatus(409);
        $this->getJson('/api/v1/jobs/'.$job.'/cost-summary')->assertOk()
            ->assertJsonPath('data.approved_minutes',90)
            ->assertJsonPath('data.approved_labour_cost_minor',3000)
            ->assertJsonPath('data.overall_actual_cost_minor',3000);
        $this->getJson('/api/v1/labour/entries/'.$id)->assertOk()
            ->assertJsonCount(3,'data.history');
        $this->postJson('/api/v1/labour/entries/'.$id.'/void',[
            'reason'=>'Approved measurement corrected by manager.',
        ])->assertOk()->assertJsonPath('data.entry.status','void');
        $this->getJson('/api/v1/jobs/'.$job.'/labour-summary')->assertOk()
            ->assertJsonPath('data.approved_minutes',0);
        $this->assertDatabaseHas('job_labour_entries',[
            'id'=>$id,'status'=>'void','void_reason'=>'Approved measurement corrected by manager.',
        ]);
    }

    public function test_overlap_and_cross_tenant_worker_validation(): void
    {
        [$owner,$company,$client,$job]=$this->tenant();
        $id=(int)$this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job))->assertCreated()->json('data.entry.id');
        $this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job,'60.00',490,550))->assertStatus(409);
        $this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job,'60.00',570,610))->assertCreated();
        $this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job,'60.00',500,500))->assertUnprocessable();
        $alien=User::factory()->create(['is_active'=>true]);
        $this->postJson('/api/v1/labour/entries',
            $this->entry($alien->id,$job))->assertUnprocessable();
        [$other,$otherCompany]=$this->tenant('Foreign M15 Tenant');
        $this->getJson('/api/v1/labour/entries/'.$id)->assertNotFound();
        $this->getJson('/api/v1/jobs/'.$job.'/labour-summary')->assertNotFound();
        $this->patchJson('/api/v1/labour/entries/'.$id,array_merge(
            $this->entry($other->id,$job),['lock_version'=>1],
        ))->assertNotFound();
    }

    public function test_idempotent_server_timer_pause_resume_stop_creates_one_timesheet(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('Timers Tenant');
        Carbon::setTestNow('2026-11-05 08:00:00 UTC');
        $start=['request_key'=>(string)Str::uuid(),'job_id'=>$job,
            'hourly_rate'=>'120.00','task'=>'Drywall','worker_role'=>'Installer'];
        $this->postJson('/api/v1/labour/timer/start',$start)->assertOk()
            ->assertJsonPath('data.timer.status','running');
        $this->postJson('/api/v1/labour/timer/start',$start)->assertOk()
            ->assertJsonPath('data.timer.status','running');
        Carbon::setTestNow('2026-11-05 08:30:00 UTC');
        $this->postJson('/api/v1/labour/timer/pause',[
            'request_key'=>(string)Str::uuid(),
        ])->assertOk()->assertJsonPath('data.timer.elapsed_seconds',1800);
        Carbon::setTestNow('2026-11-05 09:00:00 UTC');
        $this->postJson('/api/v1/labour/timer/resume',[
            'request_key'=>(string)Str::uuid(),
        ])->assertOk()->assertJsonPath('data.timer.status','running');
        Carbon::setTestNow('2026-11-05 09:15:00 UTC');
        $stop=['request_key'=>(string)Str::uuid()];
        $id=(int)$this->postJson('/api/v1/labour/timer/stop',$stop)
            ->assertOk()->assertJsonPath('data.timer.status','stopped')
            ->assertJsonPath('data.entry.duration_minutes',45)
            ->assertJsonPath('data.entry.cost_minor',9000)
            ->json('data.entry.id');
        $this->postJson('/api/v1/labour/timer/stop',$stop)->assertOk()
            ->assertJsonPath('data.entry.id',$id);
        $this->postJson('/api/v1/labour/timer/stop',[
            'request_key'=>(string)Str::uuid(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('job_labour_entries',1);
        $this->assertDatabaseCount('job_labour_timers',1);
        $this->getJson('/api/v1/labour/timer/current')->assertOk()
            ->assertJsonPath('data.timer.status','stopped');
    }

    public function test_private_evidence_integrity_and_admin_guards(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant('Private Labour');
        $entry=(int)$this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job))->assertCreated()->json('data.entry.id');
        $reply=$this->post('/api/v1/labour/entries/'.$entry.'/evidence',[
            'file'=>UploadedFile::fake()->image('attendance.png',250,100),
        ],['Accept'=>'application/json'])->assertCreated();
        $proof=(int)$reply->json('data.id');
        $this->get('/api/v1/labour/entries/'.$entry.'/evidence/'.$proof)
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->get('/admin/time-labour')->assertForbidden();
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $this->get('/admin/time-labour')->assertOk()
            ->assertSeeText('Time & Labour Tracking');
        $this->get('/admin/time-labour/'.$entry.'/edit')->assertOk()
            ->assertSee('Window assembly')->assertSee('Verified Original');
        $this->post('/admin/time-labour/'.$entry.'/approve',['confirm'=>1])
            ->assertRedirect();
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->post('/api/v1/labour/entries/'.$entry.'/evidence',[
            'file'=>UploadedFile::fake()->image('other.png',100,100),
        ],['Accept'=>'application/json'])->assertStatus(409);
        $saved=DB::table('job_labour_evidence')->find($proof);
        Storage::disk('local')->put($saved->private_path,'tampered content');
        $this->get('/api/v1/labour/entries/'.$entry.'/evidence/'.$proof)
            ->assertStatus(409);
    }

    public function test_missing_rate_does_not_invent_zero_cost_and_excludes_m14_labour_invoice(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('Cost Summary Tenant');
        $entry=(int)$this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job,''))->assertCreated()
            ->assertJsonPath('data.entry.hourly_rate_minor',null)
            ->json('data.entry.id');
        $this->postJson('/api/v1/labour/entries/'.$entry.'/approve',['confirm'=>1])
            ->assertOk();
        $this->postJson('/api/v1/expenses',[
            'job_id'=>$job,'category'=>'material','supplier'=>'Tools Market',
            'description'=>'Supplies','amount'=>'10.00','expense_date'=>'2026-11-05',
        ])->assertCreated();
        $labourExpense=(int)$this->postJson('/api/v1/expenses',[
            'job_id'=>$job,'category'=>'labour','supplier'=>'Contractor',
            'description'=>'Third party labour','amount'=>'50.00',
            'expense_date'=>'2026-11-05',
        ])->assertCreated()->json('data.expense.id');
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin);
        $this->post('/admin/expenses/'.$labourExpense.'/approve',['confirm'=>1])
            ->assertRedirect();
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->getJson('/api/v1/jobs/'.$job.'/cost-summary')->assertOk()
            ->assertJsonPath('data.approved_missing_rate_count',1)
            ->assertJsonPath('data.overall_actual_cost_minor',null)
            ->assertJsonPath('data.unreconciled_external_labour_expense_minor',5000)
            ->assertJsonPath('data.approved_purchase_minor',0);
    }    public function test_regular_worker_cannot_read_or_change_other_worker_time_or_proof(): void
    {
        Storage::fake('local');
        [$owner,$company,$client,$job]=$this->tenant('Worker Privacy');
        $ownerRecord=(int)$this->postJson('/api/v1/labour/entries',
            $this->entry($owner->id,$job))->assertCreated()->json('data.entry.id');
        $proof=(int)$this->post('/api/v1/labour/entries/'.$ownerRecord.'/evidence',[
            'file'=>UploadedFile::fake()->image('proof.png',240,100),
        ],['Accept'=>'application/json'])->assertCreated()->json('data.id');
        $worker=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
        $company->users()->attach($worker->id,[
            'role'=>'member','status'=>'active',
            'permissions'=>json_encode(['jobs.read','jobs.write']),
        ]);
        DB::table('service_job_user')->insert([
            'service_job_id'=>$job,'user_id'=>$worker->id,
        ]);
        Sanctum::actingAs($worker);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->getJson('/api/v1/labour/entries')->assertOk()
            ->assertJsonCount(0,'data.records');
        $this->getJson('/api/v1/labour/entries/'.$ownerRecord)->assertNotFound();
        $this->get('/api/v1/labour/entries/'.$ownerRecord.'/evidence/'.$proof)
            ->assertNotFound();
        $this->post('/api/v1/labour/entries/'.$ownerRecord.'/evidence',[
            'file'=>UploadedFile::fake()->image('intrusion.png',120,100),
        ],['Accept'=>'application/json'])->assertForbidden();
        $this->deleteJson('/api/v1/labour/entries/'.$ownerRecord.
            '/evidence/'.$proof)->assertForbidden();
        $own=(int)$this->postJson('/api/v1/labour/entries',
            $this->entry($worker->id,$job,'35.00'))
            ->assertCreated()->json('data.entry.id');
        $this->getJson('/api/v1/labour/entries')->assertOk()
            ->assertJsonCount(1,'data.records');
        $this->getJson('/api/v1/labour/entries/'.$own)->assertOk();
        $this->postJson('/api/v1/labour/entries/'.$own.'/approve',[
            'confirm'=>true,
        ])->assertForbidden();
    }


    public function test_worker_picker_only_returns_active_assignees_in_this_company(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('Assigned Workers');
        $notAssigned=User::factory()->create(['is_active'=>true]);
        $company->users()->attach($notAssigned->id,[
            'role'=>'member','status'=>'active',
            'permissions'=>json_encode(['jobs.read','jobs.write']),
        ]);
        $inactive=User::factory()->create(['is_active'=>true]);
        $company->users()->attach($inactive->id,[
            'role'=>'member','status'=>'inactive',
            'permissions'=>json_encode(['jobs.read','jobs.write']),
        ]);
        DB::table('service_job_user')->insert([
            'service_job_id'=>$job,'user_id'=>$inactive->id,
        ]);
        $this->getJson('/api/v1/jobs/'.$job.'/labour-workers')->assertOk()
            ->assertJsonCount(1,'data.workers')
            ->assertJsonPath('data.workers.0.id',$owner->id);
    }

    public function test_stale_timer_needs_explicit_admin_recovery_and_creates_no_fake_hours(): void
    {
        [$owner,$company,$client,$job]=$this->tenant('Timer Recovery');
        Carbon::setTestNow('2026-11-05 08:00:00 UTC');
        $this->postJson('/api/v1/labour/timer/start',[
            'request_key'=>(string)Str::uuid(),
            'job_id'=>$job,'hourly_rate'=>'100.00',
        ])->assertOk();
        Carbon::setTestNow('2026-11-06 10:00:00 UTC');
        $this->postJson('/api/v1/labour/timer/stop',[
            'request_key'=>(string)Str::uuid(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('job_labour_entries',0);
        $admin=User::factory()->create([
            'is_active'=>true,'is_platform_admin'=>true,
        ]);
        $this->actingAs($admin);
        $payload=['confirm'=>1,'reason'=>'Worker forgot to stop timer; manually reconcile.',
            'request_key'=>(string)Str::uuid()];
        $url='/admin/time-labour/timer/'.$company->id.'/'.$owner->id.'/recover';
        $this->post($url,$payload)->assertRedirect();
        $this->post($url,$payload)->assertRedirect();
        $this->assertDatabaseHas('job_labour_timers',[
            'company_id'=>$company->id,
            'worker_user_id'=>$owner->id,'status'=>'stopped',
        ]);
        $event=DB::table('job_labour_events')->where('company_id',$company->id)
            ->where('event_type','timer_recovered')->sole();
        $meta=json_decode($event->metadata,true,512,JSON_THROW_ON_ERROR);
        $this->assertTrue($meta['manual_reconciliation_required']);
        $this->assertSame(26*3600,$meta['recorded_seconds']);
        $this->assertDatabaseCount('job_labour_entries',0);
        $this->get('/admin/time-labour')->assertOk()
            ->assertSeeText('Timer Recovery Audit')
            ->assertSeeText('Worker forgot to stop timer');
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id',(string)$company->id);
        $this->postJson('/api/v1/labour/timer/start',[
            'request_key'=>(string)Str::uuid(),'job_id'=>$job,
        ])->assertOk()->assertJsonPath('data.timer.status','running');
        $this->assertDatabaseCount('job_labour_entries',0);
    }

    public function test_dst_missing_time_is_rejected_and_autumn_work_duration_is_absolute(): void
    {
        try {
            LabourService::workSpan('2026-03-29',150,210);
            $this->fail('Non-existent Europe/Zurich 02:30 was accepted.');
        } catch (\Illuminate\Validation\ValidationException $expected) {
            $this->assertArrayHasKey('work_date',$expected->errors());
        }
        [$start,$end,$minutes]=LabourService::workSpan('2026-10-25',90,210);
        $this->assertSame(180,$minutes);
        $this->assertSame('2026-10-24 23:30:00',$start);
        $this->assertSame('2026-10-25 02:30:00',$end);
    }

}
