<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class GoogleMobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private \OpenSSLAsymmetricKey $privateKey;
    private string $pem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048,
        ]);
        $csr = openssl_csr_new(['commonName' => 'Google public key test fixture'],
            $this->privateKey);
        $certificate = openssl_csr_sign($csr, null, $this->privateKey, 1);
        $pem = '';
        openssl_x509_export($certificate, $pem);
        $this->pem = $pem;
        Http::fake(['https://www.googleapis.com/oauth2/v1/certs' =>
            Http::response(['unit-test-key' => $this->pem], 200)]);
        config()->set('services.google_sign_in.web_client_id',
            's2d-test-client.apps.googleusercontent.com');
        Cache::forget('sign2digital_google_signin_public_certs_v1');
    }

    private static function segment(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)),
            '+/', '-_'), '=');
    }

    private function token(array $changes = [], ?string $alg = null): string
    {
        $header = self::segment(['alg' => $alg ?? 'RS256',
            'typ' => 'JWT', 'kid' => 'unit-test-key']);
        $payload = self::segment(array_replace([
            'iss' => 'https://accounts.google.com',
            'aud' => 's2d-test-client.apps.googleusercontent.com',
            'sub' => 'google-account-subject-123',
            'email' => 'member@gmail.com',
            'email_verified' => true,
            'name' => 'Verified Google Member',
            'iat' => time() - 30, 'exp' => time() + 3600,
        ], $changes));
        $message = $header.'.'.$payload;
        openssl_sign($message, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return $message.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    public function test_google_login_creates_verified_user_without_password_login(): void
    {
        $first = $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertOk()->assertJsonPath('data.purpose', 'login')
            ->assertJsonPath('data.user.email', 'member@gmail.com');
        $this->assertNotEmpty($first->json('data.access_token'));
        $user = User::where('email', 'member@gmail.com')->firstOrFail();
        $this->assertSame('google-account-subject-123', $user->google_subject);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('test-password',
            $user->password));

        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertOk();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_verified_gmail_existing_account_is_linked_without_losing_company(): void
    {
        $user = User::factory()->create([
            'email' => 'member@gmail.com', 'email_verified_at' => now(),
        ]);
        $resp = $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertOk();
        $this->assertSame($user->id, $resp->json('data.user.id'));
        $this->assertSame('google-account-subject-123',
            $user->fresh()->google_subject);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unverified_or_disabled_existing_account_is_not_bypassed(): void
    {
        $user = User::factory()->create([
            'email' => 'member@gmail.com', 'email_verified_at' => null,
        ]);
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertUnprocessable()->assertJsonMissingPath('data.access_token');
        $this->assertNull($user->fresh()->google_subject);
        $user->update(['email_verified_at' => now(), 'is_active' => false]);
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_google_authoritative_email_never_silently_links_password_account(): void
    {
        $user = User::factory()->create([
            'email' => 'member@untrusted.example',
            'email_verified_at' => now(),
        ]);
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token([
                'email' => 'member@untrusted.example',
                'sub' => 'third-party-google-identity',
            ]),
        ])->assertUnprocessable();
        $this->assertNull($user->fresh()->google_subject);
    }

    public function test_wrong_audience_expired_forged_or_unverified_tokens_are_rejected(): void
    {
        $tokens = [
            $this->token(['aud' => 'attacker.apps.googleusercontent.com']),
            $this->token(['exp' => time() - 1]),
            $this->token(['email_verified' => false]),
            $this->token(['iss' => 'https://attacker.example']),
            $this->token(['iat' => time() + 3600]),
            $this->token(['sub' => 'x']),
            $this->token([], 'none'),
            substr($this->token(), 0, -3).'abc',
        ];
        foreach ($tokens as $token) {
            $this->postJson('/api/v1/auth/google', [
                'id_token' => $token,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_server_client_id_fails_closed(): void
    {
        config()->set('services.google_sign_in.web_client_id', '');
        $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_login_honors_existing_authenticator_second_factor(): void
    {
        $user = User::factory()->create([
            'email' => 'member@gmail.com',
            'email_verified_at' => now(),
            'google_subject' => 'google-account-subject-123',
        ]);
        DB::table('m24_two_factor_credentials')->insert([
            'user_id' => $user->id,
            'enabled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = $this->postJson('/api/v1/auth/google', [
            'id_token' => $this->token(),
        ])->assertOk()
            ->assertJsonPath('data.requires_two_factor', true)
            ->assertJsonMissingPath('data.access_token');
        $this->assertSame(64, strlen($result->json('data.two_factor_ticket')));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_google_payload_does_not_call_google_cert_endpoint(): void
    {
        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'not-a-token',
        ])->assertUnprocessable();
        Http::assertNothingSent();
    }
}
