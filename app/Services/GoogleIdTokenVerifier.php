<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Verifies Google's RS256-signed ID tokens using Google's HTTPS public
 * certificates. No user-controlled key URLs, unsigned tokens or access tokens.
 * Google OAuth web client ID is an audience identifier, NOT a client secret.
 */
class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    /** @return array{sub:string,email:string,name:string,authoritative_email:bool} */
    public function verify(string $idToken): array
    {
        $audience = trim((string) config('services.google_sign_in.web_client_id'));
        if ($audience === '') {
            throw ValidationException::withMessages([
                'google' => 'Google sign-in is not configured on the server.',
            ]);
        }
        if (strlen($idToken) > 8192) return $this->invalid();

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) return $this->invalid();
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
        $header = $this->decodedJson($headerEncoded);
        $claims = $this->decodedJson($payloadEncoded);
        $signature = $this->base64url($signatureEncoded);
        if (!$header || !$claims || $signature === null ||
            ($header['alg'] ?? null) !== 'RS256' ||
            !is_string($header['kid'] ?? null) ||
            !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $header['kid'])) {
            return $this->invalid();
        }

        // Time, issuer, client audience and email validation are mandatory.
        $now = time();
        $issuer = $claims['iss'] ?? null;
        $aud = $claims['aud'] ?? null;
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true) ||
            !is_string($aud) || !hash_equals($audience, $aud) ||
            !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now ||
            !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 ||
            $claims['iat'] < $now - 86400 ||
            (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now)) ||
            !is_string($claims['sub'] ?? null) ||
            strlen($claims['sub']) < 5 || strlen($claims['sub']) > 255 ||
            !is_string($claims['email'] ?? null) ||
            !filter_var($claims['email'], FILTER_VALIDATE_EMAIL) ||
            !in_array($claims['email_verified'] ?? null, [true, 'true'], true)) {
            return $this->invalid();
        }

        $certificates = Cache::remember('sign2digital_google_signin_public_certs_v1',
            now()->addMinutes(45), static function (): array {
                try {
                    $response = Http::timeout(6)->acceptJson()->get(self::CERTS_URL);
                    if (!$response->successful()) return [];
                    $data = $response->json();
                    if (!is_array($data) || count($data) > 12) return [];
                    return array_filter($data, static fn ($pem) =>
                        is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE')
                        && strlen($pem) < 12000);
                } catch (\Throwable) {
                    return [];
                }
            });
        $cert = $certificates[$header['kid']] ?? null;
        if (!is_string($cert) ||
            openssl_verify($headerEncoded.'.'.$payloadEncoded,
                $signature, $cert, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->invalid();
        }

        $email = strtolower(trim($claims['email']));
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $hosted = $claims['hd'] ?? null;
        return [
            'sub' => $claims['sub'],
            'email' => $email,
            'name' => trim((string) ($claims['name'] ?? '')) ?: strstr($email, '@', true),
            // Google is authoritative over Gmail and verified Workspace domains.
            // Other emails may be recycled after initial Google verification.
            'authoritative_email' => in_array($domain, ['gmail.com', 'googlemail.com'], true)
                || (is_string($hosted) && $hosted !== '' && $hosted === $domain),
        ];
    }

    private function decodedJson(string $encoded): ?array
    {
        $raw = $this->base64url($encoded);
        if ($raw === null || strlen($raw) > 8192) return null;
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function base64url(string $encoded): ?string
    {
        if ($encoded === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) return null;
        return base64_decode(strtr($encoded, '-_', '+/').
            str_repeat('=', (4 - strlen($encoded) % 4) % 4), true) ?: null;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['google' =>
            'Google sign-in could not be verified. Please try again.']);
    }
}
