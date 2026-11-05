<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class M20FcmClient
{
    public function available(): bool
    {
        return (bool) config('services.m20_fcm.enabled')
            && (string) config('services.m20_fcm.project_id') === 'sign-kamran'
            && is_file((string) config('services.m20_fcm.credentials_path'));
    }

    private static function encoded(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function authorization(): string
    {
        $project = (string) config('services.m20_fcm.project_id');
        $credentials = json_decode((string) file_get_contents(
            (string) config('services.m20_fcm.credentials_path')
        ), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($credentials) || ($credentials['project_id'] ?? null) !== $project
            || !filter_var($credentials['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !is_string($credentials['private_key'] ?? null)) {
            throw new \RuntimeException('Invalid Firebase service-account credentials.');
        }
        $cacheKey = 'm20_fcm_oauth_'.$project.'_'.hash('sha256', $credentials['client_email']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') return $cached;
        $now = time();
        $header = self::encoded(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::encoded(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now, 'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $payload = $header.'.'.$claims;
        if (!openssl_sign($payload, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign Firebase OAuth assertion.');
        }
        $response = Http::asForm()->timeout(12)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $payload.'.'.self::encoded($signature),
        ]);
        if (!$response->successful() || !is_string($response->json('access_token'))
            || !$response->json('access_token')) {
            throw new \RuntimeException('Firebase OAuth authorization failed.');
        }
        $token = $response->json('access_token');
        $seconds = max(60, min(3300, (int) $response->json('expires_in', 3600) - 120));
        Cache::put($cacheKey, $token, $seconds);
        return $token;
    }

    /** No credentials, bearer tokens or FCM registration tokens in the result. */
    public function send(string $token, string $title, string $body, array $data): array
    {
        if (!$this->available()) return ['ok' => false, 'permanent' => false, 'code' => 'NOT_CONFIGURED'];
        try {
            $response = Http::withToken($this->authorization())->timeout(12)
                ->acceptJson()->post('https://fcm.googleapis.com/v1/projects/sign-kamran/messages:send', [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data' => array_map('strval', $data),
                        'android' => ['priority' => 'high'],
                        'apns' => ['payload' => ['aps' => ['content-available' => 1]]],
                    ],
                ]);
            if ($response->successful()) {
                return ['ok' => true, 'permanent' => false,
                    'reference' => mb_substr((string) $response->json('name', ''), 0, 255)];
            }
            $details = $response->json('error.details', []);
            $unregistered = collect(is_array($details) ? $details : [])
                ->contains(fn ($entry) => is_array($entry)
                    && ($entry['errorCode'] ?? '') === 'UNREGISTERED');
            $code = $unregistered ? 'UNREGISTERED' : 'HTTP_'.$response->status();
            return ['ok' => false, 'permanent' => $unregistered, 'code' => $code];
        } catch (Throwable) {
            return ['ok' => false, 'permanent' => false, 'code' => 'TRANSPORT_ERROR'];
        }
    }
}
