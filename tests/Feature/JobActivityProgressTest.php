<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class JobActivityProgressTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Company, 2: Client, 3: int} */
    private function accountWithJob(string $name): array
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(), 'is_active' => true,
        ]);
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => $name,
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $client = Client::create([
            'company_id' => $company->id, 'full_name' => $name.' Client',
            'source' => 'manual',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $job = (int) $this->postJson('/api/v1/jobs', [
            'client_id' => $client->id,
            'description' => 'Renovate the property',
            'start_date' => '2026-11-01',
            'due_date' => '2026-12-01',
            'team_member_ids' => [],
        ])->assertCreated()->assertJsonPath('data.job.progress_percentage', 0)
            ->json('data.job.id');
        return [$owner, $company, $client, $job];
    }

    private function progress(int $job): int
    {
        $detail = (int) $this->getJson('/api/v1/jobs/'.$job)
            ->assertOk()->json('data.job.progress_percentage');
        $list = $this->getJson('/api/v1/jobs?category=active')->assertOk();
        $row = collect($list->json('data.jobs'))->firstWhere('id', $job);
        $this->assertNotNull($row);
        $this->assertSame($detail, (int) $row['progress_percentage']);
        return $detail;
    }

    public function test_verified_actions_increase_and_reversals_reduce_progress(): void
    {
        [$owner, $company, $client, $job] = $this->accountWithJob('Job Progress');
        $quote = DB::table('service_quotes')->insertGetId([
            'company_id' => $company->id, 'client_id' => $client->id,
            'service_job_id' => $job, 'reference_number' => 'QP-001',
            'client_name' => 'Client', 'quote_date' => '2026-11-02',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $expense = DB::table('job_expenses')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'category' => 'materials', 'expense_date' => '2026-11-03',
            'amount_minor' => 20000, 'status' => 'to_review',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoice = DB::table('service_invoices')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_id' => $client->id, 'invoice_number' => 'INV-P-001',
            'invoice_date' => '2026-11-04', 'due_date' => '2026-12-04',
            'client_name_snapshot' => 'Client',
            'subtotal_minor' => 100000, 'vat_minor' => 0,
            'total_minor' => 100000, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $deposit = DB::table('job_deposits')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_name_snapshot' => 'Client', 'amount_minor' => 10000,
            'deposit_date' => '2026-11-04', 'payment_method' => 'bank',
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->progress($job)); // drafts and pending do not count

        DB::table('service_quotes')->where('id', $quote)->update(['status' => 'approved']);
        $this->assertSame(10, $this->progress($job));

        DB::table('job_expenses')->where('id', $expense)->update(['status' => 'approved']);
        $this->assertSame(20, $this->progress($job));

        DB::table('job_labour_entries')->insert([
            'company_id' => $company->id, 'service_job_id' => $job,
            'worker_user_id' => $owner->id, 'worker_name_snapshot' => $owner->name,
            'kind' => 'labour', 'work_date' => '2026-11-04',
            'start_minutes' => 540, 'end_minutes' => 600,
            'work_started_at' => '2026-11-04 09:00:00',
            'work_ended_at' => '2026-11-04 10:00:00',
            'duration_minutes' => 60, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(45, $this->progress($job));

        $photo = DB::table('job_photos')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'category' => 'other', 'original_name' => 'renovation.jpg',
            'private_path' => 'job-progress/proof.jpg', 'mime_type' => 'image/jpeg',
            'byte_size' => 8, 'sha256' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(50, $this->progress($job));

        DB::table('service_invoices')->where('id', $invoice)->update(['status' => 'sent']);
        $this->assertSame(55, $this->progress($job));

        DB::table('job_deposits')->where('id', $deposit)->update(['status' => 'received']);
        $this->assertSame(60, $this->progress($job));
        DB::table('service_invoice_payments')->insert([
            'company_id' => $company->id, 'service_invoice_id' => $invoice,
            'amount_minor' => 20000, 'method' => 'bank',
            'payment_date' => '2026-11-05', 'status' => 'recorded',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(60, $this->progress($job)); // same milestone counted once

        DB::table('job_photos')->where('id', $photo)->update(['archived_at' => now()]);
        $this->assertSame(55, $this->progress($job));
        DB::table('job_expenses')->where('id', $expense)->update(['status' => 'to_review']);
        $this->assertSame(45, $this->progress($job));

        // Numeric percentage is never editable; stage completion is separate.
        $this->patchJson('/api/v1/jobs/'.$job, ['progress_percentage' => 88])
            ->assertUnprocessable();
        $this->assertSame(45, $this->progress($job));
        $this->patchJson('/api/v1/jobs/'.$job, ['stage' => 'completed'])
            ->assertOk()->assertJsonPath('data.job.progress_percentage', 100);
        $this->getJson('/api/v1/jobs?category=completed')->assertOk()
            ->assertJsonPath('data.jobs.0.progress_percentage', 100);
        $this->patchJson('/api/v1/jobs/'.$job, ['stage' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.job.progress_percentage', 45);
    }

    public function test_no_activity_is_shared_across_jobs_or_companies(): void
    {
        [$ownerA, $companyA, $clientA, $jobA] = $this->accountWithJob('Company A');
        [$ownerB, $companyB, $clientB, $jobB] = $this->accountWithJob('Company B');
        DB::table('job_expenses')->insert([
            'company_id' => $companyB->id, 'service_job_id' => $jobB,
            'category' => 'materials', 'expense_date' => '2026-11-01',
            'amount_minor' => 10000, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(10, $this->progress($jobB));
        $this->getJson('/api/v1/jobs/'.$jobA)->assertNotFound();
        Sanctum::actingAs($ownerA);
        $this->withHeader('X-Company-Id', (string) $companyA->id);
        $this->assertSame(0, $this->progress($jobA));
        $this->getJson('/api/v1/jobs/'.$jobB)->assertNotFound();
    }

    public function test_signed_scope_and_signed_acceptance_are_creditable(): void
    {
        [$owner, $company, $client, $job] = $this->accountWithJob('Signed Work');
        $quote = DB::table('service_quotes')->insertGetId([
            'company_id' => $company->id, 'client_id' => $client->id,
            'service_job_id' => $job, 'reference_number' => 'QP-002',
            'client_name' => 'Client', 'quote_date' => '2026-11-02',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request = DB::table('signature_requests')->insertGetId([
            'company_id' => $company->id,
            'service_quote_id' => $quote,
            'request_uuid' => (string) Str::uuid(),
            'document_uuid' => (string) Str::uuid(),
            'signer_uuid' => (string) Str::uuid(),
            'token_sha256' => str_repeat('b', 64),
            'signer_name' => 'Customer', 'signer_email' => 'signed@example.test',
            'approved_revision' => 1, 'approved_pdf_sha256' => str_repeat('c', 64),
            'consent_reference' => str_repeat('d', 64),
            'quote_link_version' => 1,
            'expires_at' => now()->addDay(), 'status' => 'signed',
            'signed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('signed_document_artifacts')->insert([
            'company_id' => $company->id, 'service_quote_id' => $quote,
            'signature_request_id' => $request,
            'source_pdf_sha256' => str_repeat('e', 64), 'source_revision' => 1,
            'signed_pdf_path' => 'signed/job-progress.pdf',
            'signed_pdf_sha256' => str_repeat('f', 64),
            'evidence' => '{}', 'created_at' => now(),
        ]);
        $this->assertSame(20, $this->progress($job));
        $acceptance = DB::table('work_acceptances')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_id' => $client->id, 'number' => 'ACCEPT-P-001',
            'completed_work' => 'Windows installed',
            'completion_date' => '2026-11-06', 'cleaning_status' => 'complete',
            'status' => 'accepted',
            'signed_at' => now(),
            'signed_pdf_sha256' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(35, $this->progress($job));
        DB::table('work_acceptances')->where('id', $acceptance)
            ->update(['status' => 'draft']);
        $this->assertSame(20, $this->progress($job));
    }
}
