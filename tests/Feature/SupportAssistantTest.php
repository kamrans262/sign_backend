<?php

namespace Tests\Feature;

use App\Models\User;
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
