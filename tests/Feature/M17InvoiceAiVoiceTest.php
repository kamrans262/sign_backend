<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M17InvoiceAiVoiceTest extends TestCase
{
    use RefreshDatabase;

    private function voice(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'invoice.m4a', "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A isom"
        );
    }

    private function tenant(): array
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => 'Invoice Workshop',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, [
            'role' => 'owner', 'status' => 'active',
        ]);
        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return [$owner, $company];
    }

    public function test_invoice_voice_ai_requires_authenticated_tenant_and_never_issues_or_saves(): void
    {
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => $this->voice()])->assertUnauthorized();
        [$owner, $company] = $this->tenant();
        config(['services.openai.key' => 'server-only-test-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' =>
                Http::response(['text' => 'Invoice for two replacement windows at CHF 85 each'], 200),
            'https://api.openai.com/v1/chat/completions' =>
                Http::response(['choices' => [['message' => [
                    'content' => json_encode([
                        'jobReference' => 'JOB-42', 'clientName' => 'Marc Dupont',
                        'invoiceNumber' => null, 'iban' => null,
                        'issueDate' => '2026-11-11', 'dueDate' => '2026-11-30',
                        'notes' => null, 'items' => [
                            ['description' => 'Replacement windows', 'quantity' => 2,
                                'unitPrice' => 85.0],
                            ['description' => 'Unpriced labour', 'quantity' => 1,
                                'unitPrice' => null],
                        ],
                    ]),
                ]]]], 200),
        ]);
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => $this->voice()])
            ->assertOk()
            ->assertJsonPath('data.suggestion.jobReference', 'JOB-42')
            ->assertJsonPath('data.suggestion.items.0.unitPrice', 85)
            ->assertJsonPath('data.suggestion.items.1.unitPrice', null)
            ->assertJsonPath('data.requires_user_review', true)
            ->assertJsonPath('data.saved', false)
            ->assertJsonPath('data.approved', false)
            ->assertJsonPath('data.issued', false);
        $this->assertDatabaseCount('service_invoices', 0);
        Http::assertSentCount(2);
    }

    public function test_invoice_ai_denies_other_company_and_disallowed_members_before_openai(): void
    {
        [$owner, $company] = $this->tenant();
        config(['services.openai.key' => 'server-only-test-key']);
        Http::fake();
        $outsider = User::factory()->create(['is_active' => true,
            'email_verified_at' => now()]);
        Sanctum::actingAs($outsider);
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => $this->voice()])->assertForbidden();

        $member = User::factory()->create(['is_active' => true,
            'email_verified_at' => now()]);
        $company->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['documents.read', 'jobs.read']),
        ]);
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => $this->voice()])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invoice_voice_fails_closed_for_missing_key_and_invalid_audio(): void
    {
        $this->tenant();
        config(['services.openai.key' => '']);
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => $this->voice()])->assertStatus(503);
        config(['services.openai.key' => 'server-only-test-key']);
        Http::fake();
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => UploadedFile::fake()->createWithContent('voice.m4a', 'not real audio data')])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_invoice_voice_cannot_accept_ai_provided_payment_or_invalid_price(): void
    {
        $this->tenant();
        config(['services.openai.key' => 'server-only-test-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' =>
                Http::response(['text' => 'One task, price unknown'], 200),
            'https://api.openai.com/v1/chat/completions' =>
                Http::response(['choices' => [['message' => [
                    'content' => json_encode([
                        'notes' => 'One task', 'paid' => true, 'approved' => true,
                        'items' => [['description' => 'Task', 'quantity' => 1,
                            'unitPrice' => -100]],
                    ]),
                ]]]], 200),
        ]);
        $this->postJson('/api/v1/invoices/ai/voice',
            ['file' => $this->voice()])
            ->assertOk()
            ->assertJsonPath('data.suggestion.items.0.unitPrice', null)
            ->assertJsonMissingPath('data.suggestion.paid')
            ->assertJsonMissingPath('data.suggestion.approved')
            ->assertJsonPath('data.approved', false);
        $this->assertDatabaseCount('service_invoices', 0);
    }
}
