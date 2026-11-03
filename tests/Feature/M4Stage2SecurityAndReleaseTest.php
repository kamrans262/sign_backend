<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M4Stage2SecurityAndReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function owner(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function company(User $owner, string $name): Company
    {
        $company = Company::create([
            'owner_user_id' => $owner->id,
            'name' => $name,
            'country_iso_code' => 'CH',
            'status' => 'active',
        ]);
        $company->users()->attach($owner->id, [
            'role' => 'owner',
            'status' => 'active',
        ]);
        return $company;
    }

    private function switchHeader(Company $company): void
    {
        $this->withHeader('X-Company-Id', (string) $company->id);
    }

    public function test_one_member_with_two_companies_sees_only_clients_of_selected_tenant(): void
    {
        $owner = $this->owner();
        $first = $this->company($owner, 'A');
        $second = $this->company($owner, 'B');
        Sanctum::actingAs($owner);

        $this->switchHeader($first);
        $firstClient = $this->postJson('/api/v1/clients', [
            'full_name' => 'Alice First',
            'email' => 'alice@example.test',
        ])->assertCreated()->json('data.client.id');

        $this->switchHeader($second);
        $this->getJson('/api/v1/clients')
            ->assertOk()->assertJsonCount(0, 'data.clients');
        $this->postJson('/api/v1/clients', [
            'full_name' => 'Bob Second',
            'email' => 'bob@example.test',
        ])->assertCreated();

        $this->getJson('/api/v1/clients?q=Alice')
            ->assertOk()->assertJsonCount(0, 'data.clients');
        $this->getJson('/api/v1/clients/'.$firstClient)->assertNotFound();
        $this->patchJson('/api/v1/clients/'.$firstClient, [
            'full_name' => 'Cross tenant',
        ])->assertNotFound();

        $this->switchHeader($first);
        $this->getJson('/api/v1/clients?q=Alice')
            ->assertOk()->assertJsonCount(1, 'data.clients')
            ->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/clients?q=Bob')
            ->assertOk()->assertJsonCount(0, 'data.clients');
        $this->assertDatabaseHas('clients', [
            'id' => $firstClient,
            'company_id' => $first->id,
            'full_name' => 'Alice First',
        ]);
        $this->assertDatabaseCount('clients', 2);
    }

    public function test_unknown_phone_contact_country_is_not_assumed_to_be_swiss(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner, 'Importer');
        Sanctum::actingAs($owner);
        $this->switchHeader($company);

        $import = $this->postJson('/api/v1/clients/import', [
            'clients' => [
                [
                    'full_name' => 'Unknown Country',
                    'phone_number' => '+49 170 111 222',
                    'email' => 'contact@example.test',
                    'country_iso_code' => '',
                    'dialing_code' => '',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.clients.0.country_iso_code', '');
        $client = Client::findOrFail((int) $import->json('data.clients.0.id'));
        $this->assertNull($client->country_iso_code);
        $this->assertSame('phone_contact', $client->source);
        $this->assertSame($company->id, $client->company_id);
    }

    public function test_revoked_member_cannot_access_client_data_or_import_after_company_membership_change(): void
    {
        $owner = $this->owner();
        $member = $this->owner();
        $company = $this->company($owner, 'Private');
        $company->users()->attach($member->id, [
            'role' => 'member',
            'status' => 'active',
            'permissions' => json_encode(['clients.read', 'clients.write']),
        ]);
        Sanctum::actingAs($owner);
        $this->switchHeader($company);
        $client = $this->postJson('/api/v1/clients', [
            'full_name' => 'Private Client',
        ])->assertCreated()->json('data.client.id');

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/clients/'.$client)->assertOk();
        $company->users()->updateExistingPivot($member->id, [
            'status' => 'inactive',
        ]);
        $this->getJson('/api/v1/clients/'.$client)->assertForbidden();
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->postJson('/api/v1/clients/import', [
            'clients' => [['full_name' => 'Should not import']],
        ])->assertForbidden();
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_admin_company_and_archived_filters_do_not_mix_clients(): void
    {
        $owner = $this->owner();
        $first = $this->company($owner, 'First');
        $second = $this->company($owner, 'Second');
        Client::create([
            'company_id' => $first->id,
            'full_name' => 'Archived Client',
            'source' => 'manual',
        ])->delete();
        Client::create([
            'company_id' => $first->id,
            'full_name' => 'Active A',
            'source' => 'manual',
        ]);
        Client::create([
            'company_id' => $second->id,
            'full_name' => 'Active B',
            'source' => 'manual',
        ]);

        $admin = $this->owner();
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);
        $this->get('/admin/clients?status=active&company_id='.$first->id)
            ->assertOk()->assertSee('Active A')
            ->assertDontSee('Active B')->assertDontSee('Archived Client');
        $this->get('/admin/clients?status=archived&company_id='.$first->id)
            ->assertOk()->assertSee('Archived Client')
            ->assertDontSee('Active A')->assertDontSee('Active B');
    }

    public function test_m3_and_m4_company_and_client_routes_coexist_without_duplicates(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes());
        $required = [
            'GET' => ['/api/v1/companies', '/api/v1/clients', '/admin/clients'],
            'POST' => ['/api/v1/companies', '/api/v1/clients', '/api/v1/clients/import'],
            'PATCH' => ['/api/v1/clients/{client}', '/api/v1/companies/{company}'],
        ];
        foreach ($required as $method => $paths) {
            foreach ($paths as $path) {
                $matches = $routes->filter(fn ($r) =>
                    in_array($method, $r->methods(), true) &&
                    '/'.$r->uri() === $path);
                $this->assertCount(1, $matches, $method.' '.$path);
            }
        }
    }
}
