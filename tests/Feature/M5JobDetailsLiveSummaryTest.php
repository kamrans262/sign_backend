<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M5JobDetailsLiveSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function job(): array
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $company = Company::create([
            'name' => 'Live Job Details', 'owner_user_id' => $owner->id,
            'status' => 'active', 'country_iso_code' => 'CH',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $client = Client::create([
            'company_id' => $company->id,
            'full_name' => 'Real Client', 'source' => 'manual',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $job = (int) $this->postJson('/api/v1/jobs', [
            'client_id' => $client->id,
            'description' => 'Replace windows',
            'start_date' => '2026-12-01',
            'due_date' => '2026-12-02',
            'team_member_ids' => [],
        ])->assertCreated()->json('data.job.id');
        return [$owner, $company, $client, $job];
    }

    public function test_verified_job_detail_counts_and_money_are_not_placeholders(): void
    {
        Storage::fake('local');
        [$owner, $company, $client, $job] = $this->job();
        $this->getJson('/api/v1/jobs/'.$job)->assertOk()
            ->assertJsonPath('data.section_counts.quotes', 0)
            ->assertJsonPath('data.section_counts.photos', 0)
            ->assertJsonPath('data.section_counts.invoices', 0)
            ->assertJsonPath('data.financials_minor.quote_amount_minor', 0)
            ->assertJsonPath('data.financials_minor.received_minor', 0)
            ->assertJsonPath('data.financials_minor.remaining_balance_minor', null);

        DB::table('service_quotes')->insert([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'service_job_id' => $job,
            'reference_number' => 'Q-JOB-DETAIL-ONE',
            'client_name' => 'Real Client',
            'quote_date' => '2026-12-01',
            'currency' => 'CHF', 'status' => 'approved',
            'total_minor' => 125000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('job_expenses')->insert([
            'company_id' => $company->id, 'service_job_id' => $job,
            'category' => 'materials', 'expense_date' => '2026-12-01',
            'amount_minor' => 10000, 'currency' => 'CHF',
            'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('job_deposits')->insert([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_name_snapshot' => 'Real Client',
            'amount_minor' => 35000, 'currency' => 'CHF',
            'deposit_date' => '2026-12-01', 'payment_method' => 'bank',
            'status' => 'received',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoice = DB::table('service_invoices')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_id' => $client->id, 'invoice_number' => 'INV-JOB-ONE',
            'invoice_date' => '2026-12-01', 'due_date' => '2026-12-30',
            'client_name_snapshot' => 'Real Client',
            'status' => 'sent', 'currency' => 'CHF',
            'subtotal_minor' => 90000, 'vat_minor' => 0,
            'total_minor' => 90000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_invoice_payments')->insert([
            'company_id' => $company->id, 'service_invoice_id' => $invoice,
            'amount_minor' => 40000, 'method' => 'bank',
            'payment_date' => '2026-12-05', 'status' => 'recorded',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRzcAAAAASUVORK5CYII=');
        $this->post('/api/v1/jobs/'.$job.'/photos', [
            'file' => UploadedFile::fake()->createWithContent('site.png', $png),
            'category' => 'before', 'caption' => 'Before work',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseCount('job_photos', 1);
        DB::table('job_notes')->insert([
            'company_id' => $company->id, 'service_job_id' => $job,
            'title' => 'Tenant-private job note',
            'body' => 'Verified private site observation.',
            'note_type' => 'text', 'visibility' => 'private',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/jobs/'.$job)->assertOk()
            ->assertJsonPath('data.section_counts.quotes', 1)
            ->assertJsonPath('data.section_counts.photos', 1)
            ->assertJsonPath('data.section_counts.purchases', 1)
            ->assertJsonPath('data.section_counts.deposits', 1)
            ->assertJsonPath('data.section_counts.invoices', 1)
            ->assertJsonPath('data.sections.purchases.0.amount_minor', 10000)
            ->assertJsonPath('data.sections.deposits.0.amount_minor', 35000)
            ->assertJsonPath('data.sections.invoices.0.invoice_number', 'INV-JOB-ONE')
            ->assertJsonPath('data.sections.notes.0.note_type', 'text')
            ->assertJsonMissing(['body' => 'Verified private site observation.'])
            ->assertJsonPath('data.financials_minor.quote_amount_minor', 125000)
            ->assertJsonPath('data.financials_minor.expenses_minor', 10000)
            ->assertJsonPath('data.financials_minor.deposits_minor', 35000)
            ->assertJsonPath('data.financials_minor.invoiced_minor', 90000)
            ->assertJsonPath('data.financials_minor.received_minor', 75000)
            ->assertJsonPath('data.financials_minor.remaining_balance_minor', 15000);

        $this->getJson('/api/v1/clients/'.$client->id)->assertOk()
            ->assertJsonPath('data.invoices.0.number', 'INV-JOB-ONE')
            ->assertJsonPath('data.quotes.0.reference_number', 'Q-JOB-DETAIL-ONE')
            ->assertJsonPath('data.notes_history.0.title', 'Tenant-private job note')
            ->assertJsonPath('data.notes_history.0.description', 'Verified private site observation.')
            ->assertJsonCount(1, 'data.jobs');
    }

    public function test_live_job_details_are_tenant_scoped(): void
    {
        [$owner, $company, $client, $job] = $this->job();
        $outsider = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/jobs/'.$job)->assertForbidden();
    }
}
