<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\ServiceQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M21GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function company(User $user, string $name): Company
    {
        $company = Company::create([
            'owner_user_id' => $user->id, 'name' => $name,
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($user->id, [
            'role' => 'owner', 'status' => 'active',
        ]);
        return $company;
    }

    private function records(Company $company, string $tag): array
    {
        $client = Client::create([
            'company_id' => $company->id, 'full_name' => $tag.' Client',
            'email' => strtolower($tag).'@example.test',
        ]);
        $job = ServiceJob::create([
            'company_id' => $company->id, 'client_id' => $client->id,
            'reference_number' => $tag.'-J', 'title' => $tag.' Job',
            'description' => 'Construction', 'start_date' => '2026-11-01',
            'due_date' => '2026-11-25', 'stage' => 'planning',
            'category' => 'active',
        ]);
        ServiceQuote::create([
            'company_id' => $company->id, 'client_id' => $client->id,
            'service_job_id' => $job->id, 'reference_number' => $tag.'-Q',
            'client_name' => $tag.' Quote', 'quote_date' => '2026-11-01',
            'status' => 'draft',
        ]);
        DB::table('managed_documents')->insert([
            'company_id' => $company->id, 'client_id' => $client->id,
            'service_job_id' => $job->id, 'category' => 'report',
            'number' => $tag.'-D', 'title' => $tag.' Document',
            'status' => 'file', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_invoices')->insert([
            'company_id' => $company->id, 'client_id' => $client->id,
            'service_job_id' => $job->id, 'invoice_number' => $tag.'-I',
            'invoice_date' => '2026-11-01', 'due_date' => '2026-11-25',
            'client_name_snapshot' => $tag.' Invoice',
            'subtotal_minor' => 10000, 'vat_minor' => 0, 'total_minor' => 10000,
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('job_expenses')->insert([
            'company_id' => $company->id, 'service_job_id' => $job->id,
            'supplier' => $tag.' Supplier', 'category' => 'materials',
            'expense_date' => '2026-11-01', 'amount_minor' => 100,
            'status' => 'to_review', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('equipment_assets')->insert([
            'company_id' => $company->id, 'code' => $tag.'-E',
            'name' => $tag.' Equipment', 'category' => 'Tools',
            'brand' => 'Brand', 'model' => 'Model',
            'purchase_date' => '2026-01-01',
            'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$client, $job];
    }

    public function test_search_covers_all_seven_core_types_with_company_isolation(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $company = $this->company($owner, 'Alpha');
        [$client, $job] = $this->records($company, 'ALPHA');
        $other = User::factory()->create(['is_active' => true]);
        $this->records($this->company($other, 'Bravo'), 'BRAVO');

        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $response = $this->getJson('/api/v1/search?per_page=25')
            ->assertOk()->assertJsonPath('data.pagination.total', 7);
        $types = array_column($response->json('data.records'), 'type');
        foreach (['clients', 'jobs', 'quotes', 'documents', 'invoices',
            'expenses', 'equipment'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertNotContains('BRAVO Client', array_column(
            $response->json('data.records'), 'title'));
        $this->getJson('/api/v1/search?types=jobs&client_id='.$client->id
            .'&job_id='.$job->id.'&status=planning')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?types=documents&document_type=report')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?q=ALPHA%20Equipment')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?types=clients&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?types=clients,bad')
            ->assertUnprocessable();
        $this->getJson('/api/v1/search?company_id=99999')->assertForbidden();
        $this->getJson('/api/v1/search?date_from=2026-11-20&date_to=2026-11-01')
            ->assertUnprocessable();
    }

    public function test_permissions_and_inactive_membership_exclude_foreign_records(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $company = $this->company($owner, 'Alpha');
        $this->records($company, 'ALPHA');
        $member = User::factory()->create(['is_active' => true]);
        $company->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['clients.read']),
        ]);
        Sanctum::actingAs($member);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $this->getJson('/api/v1/search')->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?types=quotes')->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
        $company->users()->updateExistingPivot($member->id, ['status' => 'inactive']);
        $this->getJson('/api/v1/search')->assertForbidden();
    }

    public function test_admin_search_is_guarded_and_links_to_existing_modules(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $company = $this->company($owner, 'Alpha');
        $this->records($company, 'ALPHA');
        $this->get('/admin/search')->assertRedirect('/admin/login');
        $admin = User::factory()->create([
            'is_active' => true, 'is_platform_admin' => true,
        ]);
        $this->actingAs($admin)->get('/admin/search?types=jobs&company_id='.$company->id)
            ->assertOk()->assertSee('ALPHA Job')->assertSee('Manage');
    }
}
