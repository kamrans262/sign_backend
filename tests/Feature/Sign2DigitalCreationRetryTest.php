<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class Sign2DigitalCreationRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function tenant(string $name): array
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => $name,
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, [
            'role' => 'owner', 'status' => 'active',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return [$owner, $company];
    }

    public function test_client_job_expense_retry_returns_original_and_rejects_payload_reuse(): void
    {
        [$owner, $company] = $this->tenant('Replay company');
        $client = [
            'full_name' => 'Alice Example',
            'email' => 'alice@example.test',
            'request_key' => str_repeat('a', 32),
        ];
        $first = $this->postJson('/api/v1/clients', $client)->assertCreated();
        $id = $first->json('data.client.id');
        $this->postJson('/api/v1/clients', $client)->assertOk()
            ->assertJsonPath('data.client.id', $id);
        $this->assertDatabaseCount('clients', 1);
        $this->postJson('/api/v1/clients', array_merge($client, [
            'full_name' => 'Changed',
        ]))->assertStatus(409);

        $job = [
            'client_id' => $id,
            'title' => 'Explicit job title',
            'description' => 'Replace kitchen tiles',
            'start_date' => '2026-11-15',
            'due_date' => '2026-11-30',
            'team_member_ids' => [],
            'request_key' => str_repeat('b', 32),
        ];
        $created = $this->postJson('/api/v1/jobs', $job)->assertCreated()
            ->assertJsonPath('data.job.title', 'Explicit job title');
        $jobId = $created->json('data.job.id');
        $this->postJson('/api/v1/jobs', $job)->assertOk()
            ->assertJsonPath('data.job.id', $jobId);
        $this->assertDatabaseCount('service_jobs', 1);
        $this->postJson('/api/v1/jobs', array_merge($job, [
            'title' => 'Changed',
        ]))->assertStatus(409);

        $expense = [
            'job_id' => $jobId,
            'category' => 'material',
            'supplier' => 'Workshop Supplies',
            'description' => 'Wood screws',
            'expense_date' => '2026-11-15',
            'amount' => '12.50',
            'tags' => [],
            'request_key' => str_repeat('c', 32),
        ];
        $saved = $this->postJson('/api/v1/expenses', $expense)->assertCreated();
        $expenseId = $saved->json('data.expense.id');
        $this->postJson('/api/v1/expenses', $expense)->assertCreated()
            ->assertJsonPath('data.expense.id', $expenseId);
        $this->assertDatabaseCount('job_expenses', 1);
        $this->assertDatabaseCount('job_expense_events', 1);
        $this->postJson('/api/v1/expenses', array_merge($expense, [
            'amount' => '99.00',
        ]))->assertStatus(409);
    }

    public function test_retry_key_is_scoped_to_active_company(): void
    {
        [$owner, $one] = $this->tenant('Company one');
        $payload = [
            'full_name' => 'Alice', 'request_key' => str_repeat('x', 32),
        ];
        $this->postJson('/api/v1/clients', $payload)->assertCreated();
        [$otherOwner, $two] = $this->tenant('Company two');
        $this->postJson('/api/v1/clients', $payload)->assertCreated();
        $this->assertDatabaseCount('clients', 2);
        $this->assertSame(1, Client::query()
            ->where('company_id', $one->id)->count());
        $this->assertSame(1, Client::query()
            ->where('company_id', $two->id)->count());
    }
}
