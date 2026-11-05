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
        $this->assertSame(['invoice' => 1, 'equipment' => 1, 'document' => 0],
            $service->sync());
        $this->assertSame(['invoice' => 0, 'equipment' => 0, 'document' => 0],
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
        $this->assertSame(['invoice' => 0, 'equipment' => 0, 'document' => 0],
            app(M20BusinessNotifications::class)->sync());
        $this->assertSame(0, DB::table('m20_notifications')->count());
    }
}
