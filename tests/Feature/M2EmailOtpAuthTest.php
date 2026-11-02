<?php

namespace Tests\Feature;

use App\Mail\EmailOtpCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class M2EmailOtpAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email' => 'client@example.test',
            'password' => Hash::make('TestPass@262'),
            'email_verified_at' => now(),
        ]);
    }

    private function latestCode(): string
    {
        $code = '';
        Mail::assertSent(EmailOtpCodeMail::class, function (EmailOtpCodeMail $mailable) use (&$code): bool {
            $code = $mailable->code;
            return true;
        });
        return $code;
    }

    public function test_registration_emails_a_six_digit_code_but_does_not_issue_auth_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.test',
            'phone_number' => '123456789',
            'country_iso_code' => 'CH',
            'dialing_code' => '+41',
            'password' => 'NewPass@262',
            'password_confirmation' => 'NewPass@262',
        ])->assertCreated();

        $response->assertJsonPath('data.purpose', 'register')
            ->assertJsonPath('data.expires_in', 300)
            ->assertJsonMissingPath('data.access_token');
        $this->assertNull(User::where('email', 'new@example.test')->firstOrFail()->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $this->latestCode());
        $challenge = EmailOtpChallenge::firstOrFail();
        $this->assertNotEquals($this->latestCode(), $challenge->code_hash);
        $this->assertTrue(Hash::check($this->latestCode(), $challenge->code_hash));
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'country_iso_code' => 'CH', 'dialing_code' => '+41']);
    }

    public function test_register_otp_marks_email_verified_and_grants_token_once(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.test',
            'phone_number' => '123456789',
            'country_iso_code' => 'CH',
            'dialing_code' => '+41',
            'password' => 'NewPass@262',
            'password_confirmation' => 'NewPass@262',
        ])->assertCreated();
        $challenge = EmailOtpChallenge::firstOrFail();
        $code = $this->latestCode();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challenge->id, 'code' => $code,
        ])->assertOk()->assertJsonPath('data.purpose', 'register');
        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertNotNull(User::where('email', 'new@example.test')->firstOrFail()->email_verified_at);
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challenge->id, 'code' => $code,
        ])->assertUnprocessable();
    }

    public function test_password_only_login_never_creates_session_or_token(): void
    {
        $user = $this->verifiedUser();
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'TestPass@262',
        ])->assertOk()->assertJsonPath('data.purpose', 'login');
        $response->assertJsonMissingPath('data.access_token');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $response->json('data.challenge_id'),
            'code' => $this->latestCode(),
            'device_name' => 'Flutter test phone',
        ])->assertOk()->assertJsonPath('data.user.email', $user->email);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_bad_password_does_not_email_code(): void
    {
        $user = $this->verifiedUser();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'bad',
        ])->assertUnprocessable();
        Mail::assertNothingSent();
    }

    public function test_five_incorrect_codes_lock_challenge_without_issuing_tokens(): void
    {
        $this->verifiedUser();
        $challengeId = $this->postJson('/api/v1/auth/login', [
            'email' => 'client@example.test', 'password' => 'TestPass@262',
        ])->json('data.challenge_id');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', [
                'challenge_id' => $challengeId, 'code' => '000000',
            ])->assertUnprocessable();
        }
        $this->assertSame(5, EmailOtpChallenge::findOrFail($challengeId)->attempts);
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => $this->latestCode(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_resend_is_cooldown_limited_and_revokes_prior_code(): void
    {
        $this->verifiedUser();
        $challengeId = $this->postJson('/api/v1/auth/login', [
            'email' => 'client@example.test', 'password' => 'TestPass@262',
        ])->json('data.challenge_id');
        $oldCode = $this->latestCode();

        $this->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $challengeId,
        ])->assertUnprocessable();

        $this->travel(61)->seconds();
        $response = $this->postJson('/api/v1/auth/otp/resend', [
            'challenge_id' => $challengeId,
        ])->assertOk();
        $this->assertNotSame($challengeId, $response->json('data.challenge_id'));
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => $oldCode,
        ])->assertUnprocessable();
    }

    public function test_expired_code_cannot_be_used(): void
    {
        $this->verifiedUser();
        $challengeId = $this->postJson('/api/v1/auth/login', [
            'email' => 'client@example.test', 'password' => 'TestPass@262',
        ])->json('data.challenge_id');
        $code = $this->latestCode();
        $this->travel(301)->seconds();
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => $code,
        ])->assertUnprocessable();
    }

    public function test_password_reset_requires_verified_code_and_revokes_existing_tokens(): void
    {
        $user = $this->verifiedUser();
        $user->createToken('previous phone');
        $challengeId = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk()->json('data.challenge_id');

        $result = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId, 'code' => $this->latestCode(),
        ])->assertOk()->assertJsonPath('data.purpose', 'reset');
        $resetToken = $result->json('data.reset_token');
        $this->assertNotEmpty($resetToken);
        $this->assertNull($result->json('data.access_token'));

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'UpdatedPass@262',
            'password_confirmation' => 'UpdatedPass@262',
        ])->assertOk();

        $this->assertTrue(Hash::check('UpdatedPass@262', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'UpdatedPass@262',
            'password_confirmation' => 'UpdatedPass@262',
        ])->assertUnprocessable();
    }

    public function test_unknown_password_reset_email_gets_same_public_response_shape(): void
    {
        $known = $this->verifiedUser();
        $knownResult = $this->postJson('/api/v1/auth/password/forgot', ['email' => $known->email])->assertOk();
        $unknownResult = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $this->assertEquals(
            array_keys($knownResult->json('data')),
            array_keys($unknownResult->json('data'))
        );
    }

    public function test_admin_login_remains_password_only_and_mobile_auth_dashboard_is_protected(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'password' => Hash::make('AdminPassword@262'),
        ]);
        $this->get('/admin/mobile-auth')->assertRedirect('/admin/login');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'AdminPassword@262'])
            ->assertRedirect(route('admin.dashboard'));
        $this->get('/admin/mobile-auth')->assertOk()->assertSee('Mobile Authentication');
        $this->assertDatabaseCount('email_otp_challenges', 0);
    }
}
