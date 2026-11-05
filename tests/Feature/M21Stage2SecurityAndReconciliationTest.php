<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M21Stage2SecurityAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'owner_user_id' => $user->id, 'name' => 'Tenant A',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        $client = Client::create([
            'company_id' => $company->id, 'full_name' => 'A_100% literal',
            'email' => 'literal@example.test',
        ]);
        $job = ServiceJob::create([
            'company_id' => $company->id, 'client_id' => $client->id,
            'reference_number' => 'M21-OVERDUE-001', 'title' => 'Overdue Installation',
            'description' => 'Description', 'start_date' => '2025-01-01',
            'due_date' => '2025-01-02', 'category' => 'active',
            'stage' => 'planning',
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return [$user, $company, $client, $job];
    }

    public function test_overdue_job_and_invoice_statuses_match_business_logic(): void
    {
        [, $company, $client, $job] = $this->setupTenant();
        $this->getJson('/api/v1/search?types=jobs&status=overdue')
            ->assertOk()->assertJsonPath('data.records.0.status', 'overdue')
            ->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?types=jobs&status=planning')
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
        $invoiceId = DB::table('service_invoices')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job->id,
            'client_id' => $client->id, 'invoice_number' => 'INV-M21-001',
            'client_name_snapshot' => 'Tenant A', 'status' => 'approved',
            'invoice_date' => '2025-01-01', 'due_date' => '2025-01-05',
            'subtotal_minor' => 10000, 'vat_minor' => 0, 'total_minor' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/search?types=invoices&status=overdue')
            ->assertOk()->assertJsonPath('data.records.0.status', 'overdue')
            ->assertJsonPath('data.pagination.total', 1);
        $depositId = DB::table('job_deposits')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job->id,
            'client_name_snapshot' => 'Tenant A', 'amount_minor' => 2000,
            'deposit_date' => '2025-01-01', 'payment_method' => 'cash',
            'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_invoice_deposit_allocations')->insert([
            'company_id' => $company->id, 'service_invoice_id' => $invoiceId,
            'job_deposit_id' => $depositId, 'amount_minor' => 2000,
            'created_at' => now(),
        ]);
        $this->getJson('/api/v1/search?types=invoices&status=partially_paid')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        DB::table('job_deposits')->where('id', $depositId)
            ->update(['status' => 'refunded']);
        $this->getJson('/api/v1/search?types=invoices&status=partially_paid')
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/v1/search?types=invoices&status=overdue')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        DB::table('service_invoice_payments')->insert([
            'company_id' => $company->id, 'service_invoice_id' => $invoiceId,
            'amount_minor' => 10000, 'method' => 'cash',
            'payment_date' => '2025-01-06', 'status' => 'recorded',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/search?types=invoices&status=paid')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    public function test_search_treats_wildcards_literally_and_never_reads_deleted_clients(): void
    {
        [, $company, $client] = $this->setupTenant();
        Client::create([
            'company_id' => $company->id, 'full_name' => 'A-100-Z',
        ]);
        $this->getJson('/api/v1/search?types=clients&q='.rawurlencode('_100%'))
            ->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.records.0.id', $client->id);
        $this->getJson('/api/v1/search?types=clients&q=%25')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $client->delete();
        $this->getJson('/api/v1/search?types=clients&q=%25')
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_admin_document_result_links_to_catalog_key_and_pagination_is_stable(): void
    {
        [, $company, $client, $job] = $this->setupTenant();
        $admin = User::factory()->create([
            'is_active' => true, 'is_platform_admin' => true,
        ]);
        $documentId = DB::table('managed_documents')->insertGetId([
            'company_id' => $company->id, 'client_id' => $client->id,
            'service_job_id' => $job->id, 'category' => 'contacts',
            'number' => 'DOC-M21-001', 'title' => 'Contract',
            'status' => 'file', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('managed_document_versions')->insert([
            'company_id' => $company->id, 'managed_document_id' => $documentId,
            'version' => 1, 'original_name' => 'contract.pdf',
            'private_path' => 'm21/test-contract.pdf',
            'mime_type' => 'application/pdf', 'byte_size' => 20,
            'sha256' => str_repeat('a', 64), 'created_at' => now(),
        ]);
        $this->actingAs($admin)->get('/admin/search?types=documents&company_id='.$company->id)
            ->assertOk()->assertSee('/admin/documents/managed-'.$documentId, false);
        $this->get('/admin/documents/managed-'.$documentId)->assertOk();
        Sanctum::actingAs($company->owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $this->getJson('/api/v1/search?types=documents&per_page=1&page=1')
            ->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.has_more', false);
    }

    public function test_cross_tenant_ids_filters_and_member_permissions_are_enforced(): void
    {
        [, $company, $client] = $this->setupTenant();
        $foreignUser = User::factory()->create(['is_active' => true]);
        $foreign = Company::create([
            'owner_user_id' => $foreignUser->id, 'name' => 'Foreign',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $foreign->users()->attach($foreignUser->id,
            ['role' => 'owner', 'status' => 'active']);
        $otherClient = Client::create([
            'company_id' => $foreign->id, 'full_name' => 'Foreign Person',
        ]);
        $this->getJson('/api/v1/search?types=clients&client_id='.$otherClient->id)
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/v1/search?company_id='.$foreign->id)
            ->assertForbidden();
        $member = User::factory()->create(['is_active' => true]);
        $company->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['jobs.read']),
        ]);
        Sanctum::actingAs($member);
        $this->getJson('/api/v1/search?types=clients')
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/v1/search?types=jobs')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/search?types=invoices,deposits')
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
    }
}
