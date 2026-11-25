<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AppleIdTokenVerifier
{
    public const AUDIENCE = 'com.sign2digital.app';
    private const KEYS_URL = 'https://appleid.apple.com/auth/keys';

    public function verify(string $token, string $nonce): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 8192) $this->invalid();
        [$head, $body, $sig] = $parts;
        $header = $this->json($head);
        $claims = $this->json($body);
        $signature = $this->decode($sig);
        $kid = $header['kid'] ?? null;
        $now = time();
        if (($header['alg'] ?? null) !== 'RS256' ||
            !is_string($kid) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $kid) ||
            $signature === null ||
            ($claims['iss'] ?? null) !== 'https://appleid.apple.com' ||
            ($claims['aud'] ?? null) !== self::AUDIENCE ||
            !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now ||
            !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 ||
            $claims['iat'] < $now - 600 ||
            (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now)) ||
            !is_string($claims['sub'] ?? null) || strlen($claims['sub']) < 5 ||
            strlen($claims['sub']) > 255 ||
            !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            $this->invalid();
        }

        $keys = Cache::remember('s2d_apple_public_keys_v1', now()->addMinutes(15),
            static function (): array {
                try {
                    $response = Http::timeout(8)->acceptJson()->get(self::KEYS_URL);
                    $keys = $response->successful() ? $response->json('keys') : null;
                    return is_array($keys) && count($keys) <= 20 ? $keys : [];
                } catch (\Throwable) {
                    return [];
                }
            });
        $pem = null;
        foreach ($keys as $key) {
            if (!is_array($key) || ($key['kid'] ?? null) !== $kid ||
                ($key['kty'] ?? null) !== 'RSA' || ($key['alg'] ?? 'RS256') !== 'RS256' ||
                ($key['use'] ?? 'sig') !== 'sig') continue;
            $n = is_string($key['n'] ?? null) ? $this->decode($key['n']) : null;
            $e = is_string($key['e'] ?? null) ? $this->decode($key['e']) : null;
            if ($n === null || $e === null || strlen($n) < 256 || strlen($n) > 1024 ||
                strlen($e) > 8) continue;
            $rsa = $this->der(0x30, $this->integer($n).$this->integer($e));
            $pem = "-----BEGIN RSA PUBLIC KEY-----\n".
                chunk_split(base64_encode($rsa), 64, "\n")."-----END RSA PUBLIC KEY-----\n";
            break;
        }
        if (!$pem || openssl_verify($head.'.'.$body, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            $this->invalid();
        }

        // Existing linked users are identified only by Apple's stable subject.
        // A new account requires a verified email in the signed claims.
        $email = $claims['email'] ?? null;
        $email = is_string($email) && strlen($email) <= 255 &&
            filter_var($email, FILTER_VALIDATE_EMAIL) &&
            in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            ? strtolower(trim($email)) : null;
        return ['sub' => $claims['sub'], 'email' => $email];
    }

    private function decode(string $value): ?string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return null;
        $raw = base64_decode(strtr($value, '-_', '+/').
            str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return $raw === false || $raw === '' ? null : $raw;
    }

    private function json(string $value): array
    {
        $raw = $this->decode($value);
        if ($raw === null) $this->invalid();
        $data = json_decode($raw, true, 16);
        if (!is_array($data)) $this->invalid();
        return $data;
    }

    private function der(int $tag, string $value): string
    {
        $len = strlen($value);
        $size = $len < 128 ? chr($len) : ($len <= 255 ? chr(0x81).chr($len) : chr(0x82).pack('n', $len));
        return chr($tag).$size.$value;
    }

    private function integer(string $value): string
    {
        $value = ltrim($value, "\0");
        if ($value === '' || (ord($value[0]) & 0x80)) $value = "\0".$value;
        return $this->der(0x02, $value);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['apple' =>
            'Apple sign-in could not be verified. Please try again.']);
    }
}
