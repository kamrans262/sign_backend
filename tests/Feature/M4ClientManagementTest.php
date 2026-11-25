<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M4ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function account(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'is_active' => true,
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

    private function asTenant(User $user, Company $company): static
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return $this;
    }

    private function draft(string $name = 'Marc Dupont'): array
    {
        return [
            'full_name' => $name,
            'company_name' => 'Dupont SA',
            'address' => 'Lausanne',
            'country_iso_code' => 'CH',
            'dialing_code' => '+41',
            'phone_number' => '211234567',
            'email' => 'marc@example.test',
            'notes' => 'Prefers email.',
        ];
    }

    public function test_client_create_search_read_update_and_archive_keep_all_existing_fields(): void
    {
        $owner = $this->account('owner@example.test');
        $company = $this->company($owner, 'Workshop');
        $this->asTenant($owner, $company);
        $saved = $this->postJson('/api/v1/clients', $this->draft())
            ->assertCreated()
            ->assertJsonPath('data.client.full_name', 'Marc Dupont')
            ->assertJsonPath('data.client.company_name', 'Dupont SA')
            ->assertJsonPath('data.client.notes', 'Prefers email.');
        $clientId = (int) $saved->json('data.client.id');

        $this->assertDatabaseHas('clients', [
            'company_id' => $company->id, 'full_name' => 'Marc Dupont',
            'source' => 'manual', 'email' => 'marc@example.test',
        ]);
        $this->getJson('/api/v1/clients?q=Marc')
            ->assertOk()->assertJsonCount(1, 'data.clients')
            ->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/clients?q=Unknown')
            ->assertOk()->assertJsonCount(0, 'data.clients');
        $this->getJson('/api/v1/clients/'.$clientId)
            ->assertOk()->assertJsonPath('data.client.id', $clientId)
            ->assertJsonCount(0, 'data.jobs')
            ->assertJsonCount(0, 'data.documents');
        $this->patchJson('/api/v1/clients/'.$clientId, [
            'full_name' => 'Marc Revised', 'notes' => 'New note',
        ])->assertOk()->assertJsonPath('data.client.full_name', 'Marc Revised');

        $this->deleteJson('/api/v1/clients/'.$clientId)->assertOk();
        $this->assertSoftDeleted('clients', ['id' => $clientId]);
        $this->getJson('/api/v1/clients/'.$clientId)->assertNotFound();
        $this->getJson('/api/v1/clients')->assertOk()
            ->assertJsonCount(0, 'data.clients');
    }

    public function test_client_tenant_isolation_stops_cross_company_guesses_and_switch_reloads(): void
    {
        $a = $this->account('owner-a@example.test');
        $b = $this->account('owner-b@example.test');
        $aCo = $this->company($a, 'Company A');
        $bCo = $this->company($b, 'Company B');
        $this->asTenant($a, $aCo);
        $client = $this->postJson('/api/v1/clients', $this->draft('Alice'))
            ->assertCreated()->json('data.client.id');

        $this->asTenant($b, $bCo);
        $this->getJson('/api/v1/clients')->assertOk()->assertJsonCount(0, 'data.clients');
        $this->getJson('/api/v1/clients/'.$client)->assertNotFound();
        $this->patchJson('/api/v1/clients/'.$client, ['full_name' => 'Stolen'])
            ->assertNotFound();
        $this->deleteJson('/api/v1/clients/'.$client)->assertNotFound();
        $this->postJson('/api/v1/clients', array_merge($this->draft('Bob'), [
            'company_id' => $aCo->id,
        ]))->assertCreated()->assertJsonPath('data.client.company_id', $bCo->id);
        $this->assertDatabaseHas('clients', ['id' => $client, 'company_id' => $aCo->id]);

        $this->asTenant($a, $bCo);
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->withoutHeader('X-Company-Id');
        $this->getJson('/api/v1/clients')->assertUnprocessable();
    }

    public function test_client_read_and_write_use_database_permission_grants(): void
    {
        $owner = $this->account('owner@example.test');
        $reader = $this->account('reader@example.test');
        $writer = $this->account('writer@example.test');
        $company = $this->company($owner, 'Roles');
        $company->users()->attach($reader->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['clients.read']),
        ]);
        $company->users()->attach($writer->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['clients.write']),
        ]);

        $this->asTenant($reader, $company);
        $this->getJson('/api/v1/clients')->assertOk();
        $this->postJson('/api/v1/clients', $this->draft())->assertForbidden();

        $this->asTenant($writer, $company);
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->postJson('/api/v1/clients', $this->draft('Writer'))->assertCreated();
        $company->users()->updateExistingPivot($writer->id, ['status' => 'inactive']);
        $this->postJson('/api/v1/clients', $this->draft('Again'))->assertForbidden();
        $company->users()->updateExistingPivot($writer->id, ['status' => 'active']);
        $company->update(['status' => 'suspended']);
        $this->postJson('/api/v1/clients', $this->draft('Still'))->assertForbidden();
    }

    public function test_import_only_saves_explicit_reviewed_batch_for_current_tenant(): void
    {
        $owner = $this->account('owner@example.test');
        $company = $this->company($owner, 'Importer');
        $this->asTenant($owner, $company);
        $one = $this->draft('One');
        $two = $this->draft('Two');
        $two['email'] = 'two@example.test';
        $response = $this->postJson('/api/v1/clients/import', ['clients' => [$one, $two]])
            ->assertCreated()->assertJsonPath('data.imported_count', 2);
        $this->assertCount(2, $response->json('data.clients'));
        $this->assertDatabaseCount('clients', 2);
        $this->assertDatabaseHas('clients', [
            'company_id' => $company->id, 'full_name' => 'One',
            'source' => 'phone_contact',
        ]);
        $this->postJson('/api/v1/clients/import', ['clients' => []])
            ->assertUnprocessable();
        $this->postJson('/api/v1/clients/import', ['clients' => [
            $this->draft('Valid'), ['full_name' => ''],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('clients', 2);
    }

    public function test_inactive_user_unverified_header_and_invalid_client_payloads_are_rejected(): void
    {
        $owner = $this->account('owner@example.test');
        $company = $this->company($owner, 'Validation');
        $this->asTenant($owner, $company);
        $this->postJson('/api/v1/clients', [
            'full_name' => ' ', 'email' => 'bad-email',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('clients', 0);

        $owner->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/clients')->assertForbidden();
    }

    public function test_admin_client_management_uses_same_records_and_cannot_transfer_tenant(): void
    {
        $owner = $this->account('owner@example.test');
        $company = $this->company($owner, 'Company A');
        $other = $this->company($this->account('other@example.test'), 'Company B');
        $admin = $this->account('platform@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);
        $this->get('/admin/clients')->assertOk()
            ->assertSee('Client Management')
            ->assertSee('Company Management')
            ->assertSee('Users');
        $this->post('/admin/clients', array_merge($this->draft('Admin Created'), [
            'company_id' => $company->id,
        ]))->assertRedirect();
        $client = Client::firstOrFail();
        $this->get('/admin/clients/'.$client->id.'/edit')->assertOk()
            ->assertSee('Admin Created');
        $this->put('/admin/clients/'.$client->id, array_merge($this->draft('Moved'), [
            'company_id' => $other->id,
        ]))->assertSessionHasErrors('company_id');
        $this->assertDatabaseHas('clients', [
            'id' => $client->id, 'company_id' => $company->id,
            'full_name' => 'Admin Created',
        ]);
        $this->delete('/admin/clients/'.$client->id)->assertRedirect();
        $this->assertSoftDeleted('clients', ['id' => $client->id]);
        $this->post('/admin/clients/'.$client->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('clients', [
            'id' => $client->id, 'deleted_at' => null,
        ]);
    }

    public function test_ordinary_user_cannot_access_admin_client_management(): void
    {
        $user = $this->account('not-admin@example.test');
        $company = $this->company($user, 'Company');
        $this->actingAs($user);
        $this->get('/admin/clients')->assertForbidden();
        $this->post('/admin/clients', array_merge($this->draft(), [
            'company_id' => $company->id,
        ]))->assertForbidden();
    }
}
