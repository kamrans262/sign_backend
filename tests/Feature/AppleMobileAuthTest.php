<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppleMobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private \OpenSSLAsymmetricKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $rsa = openssl_pkey_get_details($this->key)['rsa'];
        Cache::forget('s2d_apple_public_keys_v1');
        Http::fake(['https://appleid.apple.com/auth/keys' => Http::response(['keys' => [[
            'kid' => 'test-apple-key', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig',
            'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e']),
        ]]], 200)]);
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function nonce(): string
    {
        return $this->postJson('/api/v1/auth/apple/challenge')->assertOk()->json('data.nonce');
    }

    private function token(string $nonce, array $changes = []): string
    {
        $header = self::b64(json_encode(['alg' => 'RS256', 'kid' => 'test-apple-key'], JSON_THROW_ON_ERROR));
        $claims = self::b64(json_encode(array_replace([
            'iss' => 'https://appleid.apple.com', 'aud' => 'com.sign2digital.app',
            'sub' => 'apple-subject-test', 'nonce' => $nonce,
            'iat' => time(), 'exp' => time() + 300,
            'email' => 'test@privaterelay.appleid.com', 'email_verified' => 'true',
        ], $changes), JSON_THROW_ON_ERROR));
        $message = $header.'.'.$claims;
        openssl_sign($message, $signature, $this->key, OPENSSL_ALGO_SHA256);
        return $message.'.'.self::b64($signature);
    }

    public function test_new_account_repeat_login_and_challenge_replay(): void
    {
        $nonce = $this->nonce();
        $payload = ['nonce' => $nonce, 'id_token' => $this->token($nonce), 'name' => 'Test Apple User'];
        $first = $this->postJson('/api/v1/auth/apple', $payload)->assertOk()
            ->assertJsonPath('data.user.email', 'test@privaterelay.appleid.com');
        $this->assertNotEmpty($first->json('data.access_token'));
        $this->postJson('/api/v1/auth/apple', $payload)->assertUnprocessable();
        $next = $this->nonce();
        $this->postJson('/api/v1/auth/apple', ['nonce' => $next, 'id_token' => $this->token($next, ['email' => null])])
            ->assertOk()->assertJsonPath('data.user.id', $first->json('data.user.id'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_tokens_and_expired_challenge_are_rejected(): void
    {
        $nonce = $this->nonce();
        foreach ([
            $this->token($nonce, ['aud' => 'other.app']),
            $this->token($nonce, ['iss' => 'https://other.example']),
            $this->token($nonce, ['exp' => time() - 1]),
            $this->token(str_repeat('a', 64)),
            $this->token($nonce, ['email_verified' => false]),
            $this->token($nonce).'bad',
        ] as $token) {
            $this->postJson('/api/v1/auth/apple', ['nonce' => $nonce, 'id_token' => $token])->assertUnprocessable();
        }
        DB::table('apple_sign_in_challenges')->where('nonce_hash', hash('sha256', $nonce))
            ->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/apple', ['nonce' => $nonce, 'id_token' => $this->token($nonce)])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_existing_password_account_is_not_silently_linked(): void
    {
        $user = User::factory()->create(['email' => 'test@privaterelay.appleid.com', 'email_verified_at' => now()]);
        $nonce = $this->nonce();
        $this->postJson('/api/v1/auth/apple', ['nonce' => $nonce, 'id_token' => $this->token($nonce)])->assertUnprocessable();
        $this->assertNull($user->fresh()->apple_subject);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_apple_login_honors_two_factor(): void
    {
        $user = User::factory()->create(['email' => 'test@privaterelay.appleid.com', 'email_verified_at' => now()]);
        $user->apple_subject = 'apple-subject-test';
        $user->save();
        DB::table('m24_two_factor_credentials')->insert([
            'user_id' => $user->id, 'enabled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $nonce = $this->nonce();
        $this->postJson('/api/v1/auth/apple', ['nonce' => $nonce, 'id_token' => $this->token($nonce)])
            ->assertOk()->assertJsonPath('data.requires_two_factor', true)
            ->assertJsonMissingPath('data.access_token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
