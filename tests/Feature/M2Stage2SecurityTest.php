<?php

namespace Tests\Feature;

use App\Mail\EmailOtpCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class M2Stage2SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Focus on security semantics here; the throttle test runs with middleware.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'stage2@example.test',
            'password' => Hash::make('Stage2Pass@262'),
            'email_verified_at' => now(),
        ]);
    }

    private function sentCode(string $purpose): string
    {
        $code = null;
        Mail::assertSent(EmailOtpCodeMail::class, function (EmailOtpCodeMail $mail) use ($purpose, &$code): bool {
            if ($mail->purpose !== $purpose) return false;
            $code = $mail->code;
            return true;
        });
        $this->assertNotNull($code);

        return $code;
    }

    public function test_otp_is_keyed_to_server_secret_and_challenge_id_not_plain_or_plain_hash(): void
    {
        $user = $this->user();
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Stage2Pass@262',
        ])->assertOk();
        $code = $this->sentCode('login');
        $record = EmailOtpChallenge::findOrFail($response->json('data.challenge_id'));

        $this->assertSame(64, strlen($record->code_hash));
        $this->assertSame(EmailOtpService::codeVerifier($record->id, $code), $record->code_hash);
        $this->assertNotSame(hash('sha256', $code), $record->code_hash);
        $this->assertNotSame(EmailOtpService::codeVerifier('another-challenge', $code), $record->code_hash);
        $this->assertSame(0, $record->attempts);
    }

    public function test_cooldown_blocks_duplicate_password_based_code_requests(): void
    {
        $user = $this->user();
        $payload = ['email' => $user->email, 'password' => 'Stage2Pass@262'];
        $this->postJson('/api/v1/auth/login', $payload)->assertOk();

        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('email_otp_challenges', 1);
        Mail::assertSentCount(1);
    }

    public function test_successful_login_token_has_30_day_expiry_and_single_use_otp(): void
    {
        $user = $this->user();
        $challengeId = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Stage2Pass@262',
        ])->json('data.challenge_id');
        $code = $this->sentCode('login');

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
            'device_name' => 'Test Android',
        ])->assertOk();

        $token = $user->tokens()->firstOrFail();
        $this->assertSame('Test Android', $token->name);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => $code,
        ])->assertUnprocessable();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_resetting_password_revokes_old_login_code_and_all_mobile_tokens(): void
    {
        $user = $this->user();
        $user->createToken('old phone');

        $loginId = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Stage2Pass@262',
        ])->json('data.challenge_id');
        $loginCode = $this->sentCode('login');

        $resetId = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->json('data.challenge_id');
        $resetCode = $this->sentCode('reset');

        $resetGrant = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $resetId, 'code' => $resetCode,
        ])->assertOk()->json('data.reset_token');

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'reset_token' => $resetGrant,
            'password' => 'UpdatedPass@262',
            'password_confirmation' => 'UpdatedPass@262',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNotNull(EmailOtpChallenge::findOrFail($loginId)->consumed_at);
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $loginId, 'code' => $loginCode,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Stage2Pass@262',
        ])->assertUnprocessable();
    }

    public function test_new_password_reset_request_invalidates_previous_verified_grant(): void
    {
        $user = $this->user();
        $firstId = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->json('data.challenge_id');
        $code = $this->sentCode('reset');
        $grant = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $firstId, 'code' => $code,
        ])->assertOk()->json('data.reset_token');

        $this->travel(61)->seconds();
        $second = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();
        $this->assertNotSame($firstId, $second->json('data.challenge_id'));

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'reset_token' => $grant,
            'password' => 'ThirdPass@262',
            'password_confirmation' => 'ThirdPass@262',
        ])->assertUnprocessable();
        $this->assertTrue(Hash::check('Stage2Pass@262', $user->fresh()->password));
    }

    public function test_failed_otp_does_not_return_bearer_or_password_reset_grant(): void
    {
        $user = $this->user();
        $challengeId = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Stage2Pass@262',
        ])->json('data.challenge_id');
        $res = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => '000000',
        ])->assertUnprocessable();
        $res->assertJsonMissingPath('data.access_token')->assertJsonMissingPath('data.reset_token');
        $this->assertSame(1, EmailOtpChallenge::findOrFail($challengeId)->attempts);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_demo_provision_is_idempotent_and_does_not_grant_platform_admin(): void
    {
        $email = 'demo-stage2@example.test';
        $this->artisan('sign:demo-account', ['email' => $email])
            ->expectsQuestion('Demo password (8+ chars, upper/lowercase, number, symbol)', 'ExamplePass@262')
            ->expectsOutput('Demo account created; mobile sign-in requires email OTP.')
            ->assertExitCode(0);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_platform_admin);
        $this->assertTrue(Hash::check('ExamplePass@262', $user->password));

        $this->artisan('sign:demo-account', ['email' => $email])
            ->expectsOutput('Account already exists. Nothing was changed.')
            ->assertExitCode(0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_password_login_remains_separate_from_mobile_otp(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'email_verified_at' => null,
            'password' => Hash::make('AdminPass@262'),
        ]);
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'AdminPass@262',
        ])->assertRedirect();
        $this->get('/admin/mobile-auth')->assertOk();
        $this->assertDatabaseCount('email_otp_challenges', 0);
        Mail::assertNothingSent();
    }
}
