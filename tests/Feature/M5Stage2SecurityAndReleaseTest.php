<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M5Stage2SecurityAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function tenant(User $owner, string $name): Company
    {
        $company = Company::create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'country_iso_code' => 'CH',
            'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        return $company;
    }

    private function client(Company $company, string $name = 'Marc'): Client
    {
        return Client::create([
            'company_id' => $company->id,
            'full_name' => $name,
            'address' => 'Lausanne',
            'source' => 'manual',
        ]);
    }

    private function asCompany(User $user, Company $company): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
    }

    private function payload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'description' => "Roof repair\nReplace tiles",
            'address' => 'Rue du Centre 12',
            'start_date' => today()->addDay()->format('Y-m-d'),
            'due_date' => today()->addDays(5)->format('Y-m-d'),
            'budget_chf' => 1234.50,
            'team_member_ids' => [],
        ];
    }

    public function test_mobile_cannot_override_server_owned_company_reference_status_or_creator(): void
    {
        $owner = $this->person('owner@sample.test');
        $other = $this->tenant($this->person('foreign@sample.test'), 'Foreign');
        $company = $this->tenant($owner, 'First');
        $client = $this->client($company);
        $this->asCompany($owner, $company);
        $r = $this->postJson('/api/v1/jobs', array_merge($this->payload($client), [
            'company_id' => $other->id,
            'created_by' => 9999,
            'updated_by' => 9999,
            'reference_number' => 'FORGED',
            'category' => 'archived',
            'stage' => 'completed',
            'progress_percentage' => 99,
            'completed_at' => '2026-01-01T00:00:00Z',
        ]))->assertCreated();
        $id = (int) $r->json('data.job.id');
        $r->assertJsonPath('data.job.company_id', $company->id)
          ->assertJsonPath('data.job.category', 'active')
          ->assertJsonPath('data.job.stage', 'planning');
        $this->assertDatabaseHas('service_jobs', [
            'id' => $id,
            'company_id' => $company->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'category' => 'active',
            'stage' => 'planning',
            'reference_number' => 'JOB-F-001',
            'progress_percentage' => 0,
        ]);
        $this->patchJson('/api/v1/jobs/'.$id, [
            'company_id' => $other->id,
            'reference_number' => 'FAKE',
            'created_by' => 999,
            'description' => 'Updated description',
        ])->assertOk();
        $this->assertDatabaseHas('service_jobs', [
            'id' => $id,
            'company_id' => $company->id,
            'created_by' => $owner->id,
            'reference_number' => 'JOB-F-001',
            'description' => 'Updated description',
        ]);
    }

    public function test_bad_title_dates_and_archived_clients_fail_with_validation_not_database_error(): void
    {
        $owner = $this->person('owner@sample.test');
        $company = $this->tenant($owner, 'First');
        $client = $this->client($company);
        $archived = $this->client($company, 'Archived');
        $this->asCompany($owner, $company);
        $id = (int) $this->postJson('/api/v1/jobs', $this->payload($client))
            ->assertCreated()->json('data.job.id');
        $this->patchJson('/api/v1/jobs/'.$id, ['title' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->patchJson('/api/v1/jobs/'.$id, ['title' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->patchJson('/api/v1/jobs/'.$id, ['due_date' => today()->format('Y-m-d')])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->patchJson('/api/v1/jobs/'.$id, ['budget_chf' => -3])
            ->assertUnprocessable();
        $archived->delete();
        $this->patchJson('/api/v1/jobs/'.$id, ['client_id' => $archived->id])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->postJson('/api/v1/jobs', $this->payload($archived))
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->assertDatabaseHas('service_jobs', [
            'id' => $id, 'client_id' => $client->id, 'title' => 'Roof repair',
        ]);
        $this->assertDatabaseCount('service_jobs', 1);
    }

    public function test_complete_reopen_archive_restore_keep_history_and_completion_timestamp_stable(): void
    {
        $owner = $this->person('owner@sample.test');
        $company = $this->tenant($owner, 'First');
        $client = $this->client($company);
        $this->asCompany($owner, $company);
        $id = (int) $this->postJson('/api/v1/jobs', $this->payload($client))
            ->assertCreated()->json('data.job.id');
        $this->patchJson('/api/v1/jobs/'.$id, ['stage' => 'completed'])
            ->assertOk()->assertJsonPath('data.job.progress_percentage', 100);
        $job = ServiceJob::findOrFail($id);
        $completedAt = $job->completed_at?->format('Y-m-d H:i:s');
        $this->assertNotNull($completedAt);
        $this->patchJson('/api/v1/jobs/'.$id, [
            'stage' => 'completed', 'address' => 'New site',
        ])->assertOk();
        $this->assertSame($completedAt,
            ServiceJob::findOrFail($id)->completed_at?->format('Y-m-d H:i:s'));
        $this->deleteJson('/api/v1/jobs/'.$id)->assertOk();
        $this->getJson('/api/v1/jobs?category=archived')
            ->assertOk()->assertJsonCount(1, 'data.jobs');
        $this->postJson('/api/v1/jobs/'.$id.'/restore')
            ->assertOk()->assertJsonPath('data.job.category', 'completed');
        $this->patchJson('/api/v1/jobs/'.$id, [
            'stage' => 'in_progress', 'progress_percentage' => 30,
        ])->assertOk()->assertJsonPath('data.job.category', 'active');
        $this->assertNull(ServiceJob::findOrFail($id)->completed_at);
        $this->assertDatabaseHas('service_jobs', ['id' => $id, 'progress_percentage' => 30]);
        $this->assertSame(1, ServiceJob::whereKey($id)->count());
    }

    public function test_server_overdue_filter_search_and_client_history_are_tenant_scoped(): void
    {
        $owner = $this->person('owner@sample.test');
        $alien = $this->person('alien@sample.test');
        $a = $this->tenant($owner, 'First');
        $b = $this->tenant($alien, 'Second');
        $one = $this->client($a, 'Marc');
        $two = $this->client($b, 'Alien');
        $this->asCompany($owner, $a);
        $late = array_merge($this->payload($one), [
            'description' => 'Roof overdue',
            'start_date' => today()->subDays(9)->format('Y-m-d'),
            'due_date' => today()->subDay()->format('Y-m-d'),
        ]);
        $id = $this->postJson('/api/v1/jobs', $late)->assertCreated()->json('data.job.id');
        $this->getJson('/api/v1/jobs/'.$id)->assertOk()
            ->assertJsonPath('data.job.stage', 'overdue');
        $this->getJson('/api/v1/jobs?stage=overdue')->assertOk()
            ->assertJsonCount(1, 'data.jobs');
        $this->getJson('/api/v1/jobs?q=Roof+overdue')->assertOk()
            ->assertJsonCount(1, 'data.jobs');
        $this->getJson('/api/v1/jobs?q=Alien')->assertOk()
            ->assertJsonCount(0, 'data.jobs');
        $this->getJson('/api/v1/jobs?client_id='.$two->id)->assertOk()
            ->assertJsonCount(0, 'data.jobs');
        $this->getJson('/api/v1/clients/'.$one->id)->assertOk()
            ->assertJsonCount(1, 'data.jobs');
        $this->asCompany($alien, $b);
        $this->getJson('/api/v1/jobs/'.$id)->assertNotFound();
        $this->postJson('/api/v1/jobs/'.$id.'/restore')->assertNotFound();
        $this->getJson('/api/v1/jobs')->assertOk()
            ->assertJsonCount(0, 'data.jobs');
    }

    public function test_member_permission_removal_revokes_job_read_and_write_immediately(): void
    {
        $owner = $this->person('owner@sample.test');
        $member = $this->person('member@sample.test');
        $company = $this->tenant($owner, 'First');
        $client = $this->client($company);
        $company->users()->attach($member->id, [
            'role' => 'member',
            'status' => 'active',
            'permissions' => json_encode(['jobs.read', 'jobs.write']),
        ]);
        $this->asCompany($member, $company);
        $id = $this->postJson('/api/v1/jobs', $this->payload($client))
            ->assertCreated()->json('data.job.id');
        $this->getJson('/api/v1/jobs/'.$id)->assertOk();
        $company->users()->updateExistingPivot($member->id, [
            'permissions' => json_encode([]),
        ]);
        $this->getJson('/api/v1/jobs/'.$id)->assertForbidden();
        $this->patchJson('/api/v1/jobs/'.$id, ['description' => 'Forbidden'])
            ->assertForbidden();
        $this->postJson('/api/v1/jobs/'.$id.'/restore')->assertForbidden();
        $this->assertDatabaseHas('service_jobs', ['id' => $id, 'description' => "Roof repair\nReplace tiles"]);
    }

    public function test_m5_schema_is_separate_from_framework_queue_and_operations_are_audited(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('service_jobs'));
        $this->assertTrue(Schema::hasTable('service_job_user'));
        $owner = $this->person('owner@sample.test');
        $company = $this->tenant($owner, 'First');
        $client = $this->client($company);
        $this->asCompany($owner, $company);
        $id = $this->postJson('/api/v1/jobs', $this->payload($client))
            ->assertCreated()->json('data.job.id');
        $this->patchJson('/api/v1/jobs/'.$id, ['address' => 'Another site'])->assertOk();
        $this->deleteJson('/api/v1/jobs/'.$id)->assertOk();
        $this->postJson('/api/v1/jobs/'.$id.'/restore')->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        foreach (['mobile.job.created', 'mobile.job.updated', 'mobile.job.archived',
                  'mobile.job.restored'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action, 'company_id' => $company->id,
                'subject_id' => (string) $id,
            ]);
        }
    }
}
