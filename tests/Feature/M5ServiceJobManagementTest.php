<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M5ServiceJobManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): User
    {
        return User::factory()->create([
            'email' => $email, 'email_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function company(User $owner, string $name): Company
    {
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => $name,
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, [
            'role' => 'owner', 'status' => 'active',
        ]);
        return $company;
    }

    private function client(Company $company): Client
    {
        return Client::create([
            'company_id' => $company->id,
            'full_name' => 'Marc Dupont', 'company_name' => 'Dupont SA',
            'address' => 'Lausanne', 'source' => 'manual',
        ]);
    }

    private function draft(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'description' => 'Install three windows',
            'address' => 'Rue du Centre 12, Lausanne',
            'start_date' => '2026-12-01',
            'due_date' => '2026-12-15',
            'budget_chf' => 4500.50,
            'team_member_ids' => [],
        ];
    }

    private function asTenant(User $user, Company $company): static
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return $this;
    }

    public function test_real_job_creation_list_update_archive_restore_and_api_financial_integrity(): void
    {
        $owner = $this->account('owner@example.test');
        $company = $this->company($owner, 'Workshop');
        $client = $this->client($company);
        $this->asTenant($owner, $company);
        $created = $this->postJson('/api/v1/jobs', $this->draft($client))
            ->assertCreated()->assertJsonPath('data.job.company_id', $company->id)
            ->assertJsonPath('data.job.client_id', $client->id)
            ->assertJsonPath('data.job.description', 'Install three windows')
            ->assertJsonPath('data.job.category', 'active');
        $id = (int) $created->json('data.job.id');
        $this->assertDatabaseHas('service_jobs', [
            'id' => $id, 'company_id' => $company->id,
            'reference_number' => 'JOB-W-001',
            'stage' => 'planning',
        ]);
        // Laravel's queue must remain an unrelated table.
        $this->assertDatabaseCount('jobs', 0);
        $this->getJson('/api/v1/jobs?category=active')->assertOk()
            ->assertJsonCount(1, 'data.jobs')
            ->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/jobs?category=archived')->assertOk()
            ->assertJsonCount(0, 'data.jobs');
        $this->getJson('/api/v1/jobs/'.$id)->assertOk()
            ->assertJsonPath('data.financials.quote_amount_chf', 0)
            ->assertJsonPath('data.section_counts.quotes', 0)
            ->assertJsonCount(0, 'data.sections.quotes')
            ->assertJsonCount(0, 'data.sections.invoices');
        $this->patchJson('/api/v1/jobs/'.$id, [
            'progress_percentage' => 50,
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/jobs/'.$id, [
            'stage' => 'completed',
        ])->assertOk()->assertJsonPath('data.job.category', 'completed')
            ->assertJsonPath('data.job.progress_percentage', 100);
        $this->getJson('/api/v1/jobs?category=completed')->assertOk()
            ->assertJsonCount(1, 'data.jobs');
        $this->deleteJson('/api/v1/jobs/'.$id)->assertOk();
        $this->assertDatabaseHas('service_jobs', ['id' => $id, 'category' => 'archived']);
        $this->getJson('/api/v1/jobs?category=archived')->assertOk()->assertJsonCount(1, 'data.jobs');
        $this->patchJson('/api/v1/jobs/'.$id, ['description' => 'Invalid'])->assertStatus(409);
        $this->postJson('/api/v1/jobs/'.$id.'/restore')->assertOk()
            ->assertJsonPath('data.job.category', 'completed');
    }

    public function test_foreign_company_records_are_not_readable_or_mutable_and_spoofed_company_id_is_ignored(): void
    {
        $one = $this->account('one@example.test');
        $two = $this->account('two@example.test');
        $a = $this->company($one, 'A');
        $b = $this->company($two, 'B');
        $clientA = $this->client($a);
        $clientB = $this->client($b);
        $this->asTenant($one, $a);
        $jobId = $this->postJson('/api/v1/jobs', $this->draft($clientA))
            ->assertCreated()->json('data.job.id');
        $this->asTenant($two, $b);
        $this->getJson('/api/v1/jobs/'.$jobId)->assertNotFound();
        $this->patchJson('/api/v1/jobs/'.$jobId, ['description' => 'Stolen'])->assertNotFound();
        $this->deleteJson('/api/v1/jobs/'.$jobId)->assertNotFound();
        $this->postJson('/api/v1/jobs', $this->draft($clientA))->assertUnprocessable();
        $this->postJson('/api/v1/jobs', array_merge($this->draft($clientB), [
            'company_id' => $a->id,
        ]))->assertCreated()->assertJsonPath('data.job.company_id', $b->id);
        $this->asTenant($one, $b);
        $this->getJson('/api/v1/jobs')->assertForbidden();
        $this->withoutHeader('X-Company-Id');
        $this->getJson('/api/v1/jobs')->assertUnprocessable();
    }

    public function test_read_write_permissions_revoke_immediately_and_team_must_be_active_same_company(): void
    {
        $owner = $this->account('owner@example.test');
        $reader = $this->account('reader@example.test');
        $writer = $this->account('writer@example.test');
        $foreign = $this->account('foreign@example.test');
        $company = $this->company($owner, 'A');
        $other = $this->company($foreign, 'B');
        $client = $this->client($company);
        $company->users()->attach($reader->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['jobs.read']),
        ]);
        $company->users()->attach($writer->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['jobs.write']),
        ]);
        $this->asTenant($reader, $company);
        $this->getJson('/api/v1/jobs')->assertOk();
        $this->postJson('/api/v1/jobs', $this->draft($client))->assertForbidden();

        $this->asTenant($writer, $company);
        $this->getJson('/api/v1/jobs')->assertForbidden();
        $this->postJson('/api/v1/jobs', array_merge($this->draft($client), [
            'team_member_ids' => [$foreign->id],
        ]))->assertUnprocessable();
        $this->postJson('/api/v1/jobs', array_merge($this->draft($client), [
            'team_member_ids' => [$owner->id],
        ]))->assertCreated()->assertJsonCount(1, 'data.job.team_member_ids');
        $company->users()->updateExistingPivot($writer->id, ['status' => 'inactive']);
        $this->postJson('/api/v1/jobs', $this->draft($client))->assertForbidden();
        $company->update(['status' => 'suspended']);
        $this->asTenant($owner, $company);
        $this->getJson('/api/v1/jobs')->assertForbidden();
    }

    public function test_client_history_exposes_only_real_jobs_and_only_to_job_readers(): void
    {
        $owner = $this->account('owner@example.test');
        $reader = $this->account('client-reader@example.test');
        $company = $this->company($owner, 'A');
        $company->users()->attach($reader->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['clients.read']),
        ]);
        $client = $this->client($company);
        $this->asTenant($owner, $company);
        $this->postJson('/api/v1/jobs', $this->draft($client))->assertCreated();
        $this->getJson('/api/v1/clients/'.$client->id)->assertOk()
            ->assertJsonPath('data.client.job_count', 1)
            ->assertJsonCount(1, 'data.jobs');
        $this->getJson('/api/v1/clients')->assertOk()
            ->assertJsonPath('data.clients.0.job_count', 1);
        $this->asTenant($reader, $company);
        $this->getJson('/api/v1/clients/'.$client->id)->assertOk()
            ->assertJsonPath('data.client.job_count', 0)
            ->assertJsonCount(0, 'data.jobs');
        $this->getJson('/api/v1/jobs')->assertForbidden();
    }

    public function test_invalid_dates_budget_and_foreign_client_fail_without_side_effects(): void
    {
        $owner = $this->account('owner@example.test');
        $company = $this->company($owner, 'A');
        $client = $this->client($company);
        $this->asTenant($owner, $company);
        $this->postJson('/api/v1/jobs', array_merge($this->draft($client), [
            'due_date' => '2026-11-01',
        ]))->assertUnprocessable();
        $this->postJson('/api/v1/jobs', array_merge($this->draft($client), [
            'budget_chf' => -2,
        ]))->assertUnprocessable();
        $this->postJson('/api/v1/jobs', array_merge($this->draft($client), [
            'description' => '   ',
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('service_jobs', 0);
    }

    public function test_admin_jobs_management_has_company_check_and_is_not_available_to_mobile_users(): void
    {
        $owner = $this->account('owner@example.test');
        $otherOwner = $this->account('other@example.test');
        $a = $this->company($owner, 'A');
        $b = $this->company($otherOwner, 'B');
        $client = $this->client($a);
        $admin = $this->account('admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);
        $this->get('/admin/jobs')->assertOk()->assertSee('Job Management');
        $post = array_merge($this->draft($client), [
            'company_id' => $a->id, 'title' => 'Install windows',
            'stage' => 'planning', 'progress_percentage' => 0,
        ]);
        $this->post('/admin/jobs', $post)->assertRedirect();
        $job = ServiceJob::firstOrFail();
        $this->get('/admin/jobs/'.$job->id.'/edit')->assertOk()->assertSee('Install windows');
        $this->put('/admin/jobs/'.$job->id, array_merge($post, [
            'company_id' => $b->id,
        ]))->assertSessionHasErrors('company_id');
        $this->assertDatabaseHas('service_jobs', ['id' => $job->id, 'company_id' => $a->id]);
        $this->delete('/admin/jobs/'.$job->id)->assertRedirect();
        $this->assertDatabaseHas('service_jobs', ['id' => $job->id, 'category' => 'archived']);
        $this->post('/admin/jobs/'.$job->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('service_jobs', ['id' => $job->id, 'category' => 'active']);

        $this->actingAs($owner);
        $this->get('/admin/jobs')->assertForbidden();
    }

    public function test_job_references_restart_per_company_and_are_shared_with_admin_creation(): void
    {
        $owner = $this->account('number-owner@example.test');
        $first = $this->company($owner, 'Sign2Digital');
        $second = $this->company($owner, 'Silver Tools');
        $firstClient = $this->client($first);
        $secondClient = $this->client($second);

        $this->asTenant($owner, $first);
        $this->postJson('/api/v1/jobs', $this->draft($firstClient))
            ->assertCreated()->assertJsonPath('data.job.reference_number', 'JOB-S-001');
        $this->postJson('/api/v1/jobs', $this->draft($firstClient))
            ->assertCreated()->assertJsonPath('data.job.reference_number', 'JOB-S-002');

        $this->asTenant($owner, $second);
        $this->postJson('/api/v1/jobs', $this->draft($secondClient))
            ->assertCreated()->assertJsonPath('data.job.reference_number', 'JOB-S-001');

        $this->assertDatabaseHas('companies', ['id' => $first->id, 'job_sequence' => 2]);
        $this->assertDatabaseHas('companies', ['id' => $second->id, 'job_sequence' => 1]);
        $this->assertDatabaseCount('service_jobs', 3);
        $this->getJson('/api/v1/jobs?category=active')->assertOk()
            ->assertJsonCount(1, 'data.jobs');
    }
}
