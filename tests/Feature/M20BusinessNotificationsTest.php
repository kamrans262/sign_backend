<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\M20BusinessNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class M20BusinessNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_unpaid_invoice_and_overdue_active_equipment_are_idempotent_and_no_email_sent(): void
    {
        Mail::fake();
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'owner_user_id' => $user->id, 'name' => 'M20 Event Tenant',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        $client = Client::create([
            'company_id' => $company->id, 'full_name' => 'Business Client',
            'email' => 'business@example.test', 'source' => 'manual',
        ]);
        $job = DB::table('service_jobs')->insertGetId([
            'company_id' => $company->id, 'client_id' => $client->id,
            'reference_number' => 'M20-EVENT-JOB-1', 'title' => 'Business event QA',
            'description' => 'M20 smoke test', 'start_date' => today()->subDay(),
            'due_date' => today()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = DB::table('service_invoices')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_id' => $client->id, 'invoice_number' => 'M20-INV-001',
            'invoice_type' => 'final', 'status' => 'sent', 'currency' => 'CHF',
            'invoice_date' => today()->subDays(2), 'due_date' => today()->subDay(),
            'client_name_snapshot' => 'Business Client', 'vat_basis_points' => 0,
            'subtotal_minor' => 5000, 'vat_minor' => 0, 'total_minor' => 5000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assetId = DB::table('equipment_assets')->insertGetId([
            'company_id' => $company->id, 'code' => 'M20-EQUIP-01',
            'name' => 'Company camera', 'category' => 'camera',
            'brand' => 'Test', 'model' => 'M20',
            'purchase_date' => today()->subMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $loanId = DB::table('equipment_loans')->insertGetId([
            'company_id' => $company->id, 'equipment_asset_id' => $assetId,
            'borrower_type' => 'person', 'borrower_name' => 'Internal staff',
            'loan_date' => today()->subDays(7),
            'expected_return_date' => today()->subDay(),
            'status' => 'active', 'active_slot' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(M20BusinessNotifications::class);
        $this->assertSame(['invoice' => 1, 'equipment' => 1, 'document' => 0, 'customer' => 0],
            $service->sync());
        $this->assertSame(['invoice' => 0, 'equipment' => 0, 'document' => 0, 'customer' => 0],
            $service->sync());
        $invoice = DB::table('m20_notifications')
            ->where('event_key', 'invoice.payment_due.'.$invoiceId)->first();
        $loan = DB::table('m20_notifications')
            ->where('event_key', 'equipment.loan_overdue.'.$loanId)->first();
        $this->assertNotNull($invoice);
        $this->assertNotNull($loan);
        $this->assertSame($company->id, $invoice->company_id);
        $this->assertSame($user->id, $loan->recipient_user_id);
        $this->assertSame(2, DB::table('m20_notifications')->count());
        $this->assertTrue($service->actionable($invoice));
        $this->assertTrue($service->actionable($loan));
        $this->assertSame(0, DB::table('m20_push_deliveries')->count());
        Mail::assertNothingSent();

        DB::table('service_invoice_payments')->insert([
            'company_id' => $company->id, 'service_invoice_id' => $invoiceId,
            'amount_minor' => 5000, 'method' => 'bank_transfer',
            'payment_date' => today(), 'status' => 'recorded',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('equipment_loans')->where('id', $loanId)->update([
            'status' => 'returned', 'active_slot' => null,
            'returned_at' => now(), 'actual_return_date' => today(),
        ]);
        $this->assertFalse($service->actionable($invoice));
        $this->assertFalse($service->actionable($loan));
        Mail::assertNothingSent();
    }

    public function test_unverified_owner_cannot_receive_automated_business_notifications(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $company = Company::create([
            'owner_user_id' => $user->id, 'name' => 'Inactive Owner',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        $this->assertSame(['invoice' => 0, 'equipment' => 0, 'document' => 0, 'customer' => 0],
            app(M20BusinessNotifications::class)->sync());
        $this->assertSame(0, DB::table('m20_notifications')->count());
    }

    public function test_signed_acceptance_with_reservations_creates_safe_customer_action_event(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => 'M20 Customer Action',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $client = Client::create([
            'company_id' => $company->id, 'full_name' => 'Review Customer',
            'email' => 'review@example.test', 'source' => 'manual',
        ]);
        $job = DB::table('service_jobs')->insertGetId([
            'company_id' => $company->id, 'client_id' => $client->id,
            'reference_number' => 'M20-ACTION-JOB',
            'title' => 'Customer reservation review',
            'description' => 'Verified customer acceptance',
            'start_date' => today()->subDay(),
            'due_date' => today()->addDays(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $acceptanceId = DB::table('work_acceptances')->insertGetId([
            'company_id' => $company->id, 'service_job_id' => $job,
            'client_id' => $client->id, 'number' => 'M20-WA-001',
            'completed_work' => 'Exterior works completed',
            'completion_date' => today(), 'cleaning_status' => 'cleaned',
            'status' => 'accepted_with_reservations',
            'acceptance_result' => 'accepted_with_reservations',
            'customer_reservation' => 'PRIVATE CUSTOMER RESERVATION: gate lock',
            'signed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $requestId = DB::table('work_acceptance_requests')->insertGetId([
            'company_id' => $company->id, 'work_acceptance_id' => $acceptanceId,
            'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'document_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token_sha256' => hash('sha256', 'request-m20-action'),
            'consent_reference' => hash('sha256', 'consent-m20-action'),
            'approved_revision' => 1,
            'approved_pdf_sha256' => hash('sha256', 'approved-pdf-m20'),
            'signer_name' => 'Review Customer',
            'signer_email' => 'review@example.test', 'status' => 'signed',
            'expires_at' => now()->addDay(),
            'signed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_acceptance_artifacts')->insert([
            'company_id' => $company->id,
            'work_acceptance_id' => $acceptanceId,
            'work_acceptance_request_id' => $requestId,
            'signature_path' => 'private/m20-action-signature.png',
            'signature_sha256' => hash('sha256', 'signature-m20'),
            'signed_pdf_path' => 'private/m20-action-signed.pdf',
            'signed_pdf_sha256' => hash('sha256', 'signed-m20'),
            'evidence' => json_encode(['status' => 'verified']),
            'created_at' => now(),
        ]);
        $service = app(M20BusinessNotifications::class);
        $this->assertSame(
            ['invoice' => 0, 'equipment' => 0, 'document' => 0, 'customer' => 1],
            $service->sync()
        );
        $row = DB::table('m20_notifications')
            ->where('event_key', 'customer.action_required.'.$acceptanceId)->first();
        $this->assertNotNull($row);
        $this->assertSame('customer', $row->category);
        $this->assertSame($owner->id, $row->recipient_user_id);
        $this->assertStringNotContainsString('PRIVATE CUSTOMER', $row->body);
        $this->assertTrue($service->actionable($row));
        $this->assertSame(
            ['invoice' => 0, 'equipment' => 0, 'document' => 0, 'customer' => 0],
            $service->sync()
        );
        DB::table('work_acceptances')->where('id', $acceptanceId)
            ->update(['status' => 'archived']);
        $this->assertFalse($service->actionable($row));
        $this->assertSame(0, DB::table('m20_push_deliveries')->count());
        Mail::assertNothingSent();
    }

}
