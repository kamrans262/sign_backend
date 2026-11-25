<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M20ReminderAiVoiceTest extends TestCase
{
    use RefreshDatabase;

    private function recording(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'reminder.m4a', "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A isom"
        );
    }

    private function owner(): Company
    {
        $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $company = Company::create([
            'owner_user_id' => $user->id,
            'name' => 'Reminder Voice Test',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return $company;
    }

    public function test_auth_required_and_company_isolated_before_ai_call(): void
    {
        $this->postJson('/api/v1/reminders/ai/voice', [
            'file' => $this->recording(),
        ])->assertUnauthorized();
        $this->owner();
        config(['services.openai.key' => 'dummy-test-key']);
        Http::fake();
        $foreign = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        Sanctum::actingAs($foreign);
        $this->postJson('/api/v1/reminders/ai/voice', [
            'file' => $this->recording(),
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_real_server_ai_response_is_suggestion_only(): void
    {
        $this->owner();
        config(['services.openai.key' => 'dummy-test-key']);
        Http::fake([
            'https://api.openai.com/v1/audio/transcriptions' =>
                Http::response(['text' => 'Remind me to email the client tomorrow at 4 PM'], 200),
            'https://api.openai.com/v1/chat/completions' =>
                Http::response(['choices' => [['message' => [
                    'content' => json_encode([
                        'title' => 'Email the client',
                        'type' => 'Client',
                        'relatedItem' => null,
                        'reminderDate' => '2026-11-12',
                        'reminderTime' => '16:00',
                        'notes' => 'Follow up',
                        'sent' => true,
                        'completed' => true,
                    ]),
                ]]]], 200),
        ]);
        $this->postJson('/api/v1/reminders/ai/voice', [
            'file' => $this->recording(),
        ])->assertOk()
            ->assertJsonPath('data.suggestion.title', 'Email the client')
            ->assertJsonPath('data.suggestion.type', 'client')
            ->assertJsonPath('data.suggestion.reminderTime', '16:00')
            ->assertJsonPath('data.saved', false)
            ->assertJsonPath('data.notified', false)
            ->assertJsonPath('data.scheduled', false)
            ->assertJsonPath('data.requires_user_review', true)
            ->assertJsonMissingPath('data.suggestion.sent')
            ->assertJsonMissingPath('data.suggestion.completed');
        $this->assertDatabaseCount('m20_reminders', 0);
        Http::assertSentCount(2);
    }

    public function test_missing_key_invalid_audio_and_unsupported_date_fail_closed(): void
    {
        $this->owner();
        config(['services.openai.key' => '']);
        $this->postJson('/api/v1/reminders/ai/voice', [
            'file' => $this->recording(),
        ])->assertStatus(503);
        config(['services.openai.key' => 'dummy-test-key']);
        Http::fake();
        $this->postJson('/api/v1/reminders/ai/voice', [
            'file' => UploadedFile::fake()->createWithContent('reminder.m4a', 'invalid-recording'),
        ])->assertStatus(422);
        Http::assertNothingSent();
    }
}
