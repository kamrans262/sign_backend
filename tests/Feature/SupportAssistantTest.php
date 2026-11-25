<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SupportAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]));
    }

    public function test_support_assistant_is_private_and_first_time_guide_is_manual_only(): void
    {
        $this->postJson('/api/v1/support/assistant', ['message' => 'How do I send a quote?'])
            ->assertUnauthorized();
        $this->getJson('/api/v1/support/getting-started')->assertUnauthorized();

        $this->login();
        $this->getJson('/api/v1/support/getting-started')->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.auto_show', false)
            ->assertJsonPath('data.completed.company', false)
            ->assertJsonPath('data.completed.client', false);
    }

    public function test_absent_key_fails_closed_without_requesting_openai(): void
    {
        $this->login();
        config()->set('services.openai.key', '');
        Http::fake();
        $this->postJson('/api/v1/support/assistant', [
            'message' => 'Where are signed documents?',
            'screen' => 'documents',
        ])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_ai_can_explain_and_only_link_an_authorized_route(): void
    {
        $this->login();
        config()->set('services.openai.key', 'test-server-only-key');
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' =>
                json_encode(['answer' => 'Open Help & Support.', 'screen' => 'support'])]]],
        ], 200)]);
        $this->postJson('/api/v1/support/assistant', [
            'message' => 'How do I contact support?',
            'screen' => 'home',
        ])->assertOk()
            ->assertJsonPath('data.answer', 'Open Help & Support.')
            ->assertJsonPath('data.screen', 'support')
            ->assertJsonPath('data.read_only', true);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.openai.com/v1/chat/completions' &&
            !str_contains($request->body(), 'test-server-only-key'));
    }

    public function test_ai_cannot_link_an_unpermitted_company_module(): void
    {
        $this->login();
        config()->set('services.openai.key', 'test-server-only-key');
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' =>
                json_encode(['answer' => 'You may create a quote.', 'screen' => 'quotes'])]]],
        ], 200)]);
        $this->postJson('/api/v1/support/assistant', [
            'message' => 'Open quotes',
        ])->assertOk()->assertJsonPath('data.screen', null);
    }

    public function test_invalid_screen_and_foreign_company_are_rejected(): void
    {
        $this->login();
        $this->postJson('/api/v1/support/assistant', [
            'message' => 'Hello', 'screen' => 'admin-panel',
        ])->assertUnprocessable();
        $this->withHeader('X-Company-Id', '99999')
            ->getJson('/api/v1/support/getting-started')->assertForbidden();
    }

    public function test_existing_job_quote_creation_uses_jobs_to_job_details_to_quotes(): void
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($owner);
        $companyId = $this->postJson('/api/v1/companies', [
            'name' => 'Job Quote Workshop',
            'country_iso_code' => 'CH',
            'address' => 'Sample Street, Zurich',
            'email' => 'office@example.test',
            'swiss_company_id' => 'CHE-123.456.789',
            'vat_number' => 'CHE-123.456.789 MWST',
            'iban' => 'CH9300762011623852957',
            'qr_iban' => 'CH9300762011623852957',
            'document_numbering' => 'Q-1000',
        ])->assertCreated()->json('data.company.id');

        config()->set('services.openai.key', 'test-server-only-key');
        // Simulate a plausible but WRONG generic AI response. The verified
        // job-specific route must take priority over the generic model reply.
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' =>
                json_encode(['answer' => 'Open Documents and create a quote.',
                    'screen' => 'documents'])]]],
        ], 200)]);

        $reply = $this->withHeader('X-Company-Id', (string) $companyId)
            ->postJson('/api/v1/support/assistant', [
                'message' => 'I want to create a qoute against the job I created.',
                'screen' => 'home',
            ])->assertOk()->assertJsonPath('data.screen', 'jobs')
                ->assertJsonPath('data.read_only', true);
        $this->assertStringContainsString(
            'Open Jobs and select the job', $reply->json('data.answer'));
        $this->assertStringContainsString(
            'On Job Details, tap Quotes', $reply->json('data.answer'));
        $this->assertStringContainsString(
            'Create New Quote', $reply->json('data.answer'));
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.openai.com/v1/chat/completions' &&
            str_contains($request->body(), 'select the existing job'));

        // A standalone quote question is still answered by live AI.
        $this->withHeader('X-Company-Id', (string) $companyId)
            ->postJson('/api/v1/support/assistant', [
                'message' => 'How do I create a quotation?',
                'screen' => 'home',
            ])->assertOk()
                ->assertJsonPath('data.answer', 'Open Documents and create a quote.')
                ->assertJsonPath('data.screen', 'documents')
                ->assertJsonPath('data.sample_prompt',
                    'Create a quotation for installing three wooden doors at CHF 450 each. Add CHF 150 transportation, 8.1% VAT and a 30% deposit.');
    }

    public function test_voice_transcription_requires_real_m4a_and_never_uploads_invalid_bytes(): void
    {
        $this->login();
        config()->set('services.openai.key', 'server-only-test');
        Http::fake();
        $this->post('/api/v1/support/assistant/transcribe', [
            'file' => UploadedFile::fake()->createWithContent(
                'invalid.m4a', 'not an mp4 audio file'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_voice_transcription_uses_private_openai_key_and_returns_only_text(): void
    {
        $this->login();
        config()->set('services.openai.key', 'server-only-test');
        Http::fake(['api.openai.com/*' => Http::response([
            'text' => 'How do I create a quote for my existing job?',
        ], 200)]);
        $audio = pack('N', 20).'ftypM4A '.str_repeat(chr(0), 64);
        $this->post('/api/v1/support/assistant/transcribe', [
            'file' => UploadedFile::fake()->createWithContent(
                'question.m4a', $audio),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.text',
                'How do I create a quote for my existing job?');
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.openai.com/v1/audio/transcriptions' &&
            $request->hasHeader('Authorization', 'Bearer server-only-test') &&
            str_contains($request->body(), 'support-question.m4a'));
    }

    public function test_provider_quota_response_is_not_a_fake_answer(): void
    {
        $this->login();
        config()->set('services.openai.key', 'test-server-only-key');
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'quota'], 429)]);
        $this->postJson('/api/v1/support/assistant', ['message' => 'Help with invoices'])
            ->assertStatus(503)->assertJsonPath('message',
                'AI usage limit reached. Please try again later.');
    }
}
