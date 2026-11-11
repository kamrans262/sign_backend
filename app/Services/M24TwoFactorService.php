<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * RFC 6238 authenticator verification and single-use recovery codes.
 * No authenticator secret, code or login ticket is logged or stored in plaintext.
 */
final class M24TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function enabled(int $userId): bool
    {
        return DB::table('m24_two_factor_credentials')
            ->where('user_id', $userId)->whereNotNull('enabled_at')->exists();
    }

    public function generateSecret(): string
    {
        $buffer = 0;
        $bits = 0;
        $output = '';
        foreach (unpack('C*', random_bytes(20)) as $byte) {
            $buffer = (($buffer << 8) | $byte) & 65535;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        return $output;
    }

    private function secretBytes(string $secret): string
    {
        if (!preg_match('/^[A-Z2-7]{32}$/D', $secret)) {
            throw new \InvalidArgumentException('Invalid authenticator secret.');
        }
        $buffer = 0;
        $bits = 0;
        $bytes = '';
        foreach (str_split($secret) as $char) {
            $value = strpos(self::ALPHABET, $char);
            $buffer = (($buffer << 5) | $value) & 65535;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 255);
            }
        }
        return $bytes;
    }

    public function codeAt(string $secret, int $step): string
    {
        $digest = hash_hmac('sha1', pack('N2', 0, $step), $this->secretBytes($secret), true);
        $offset = ord($digest[19]) & 15;
        $value = ((ord($digest[$offset]) & 127) << 24) |
            (ord($digest[$offset + 1]) << 16) |
            (ord($digest[$offset + 2]) << 8) |
            ord($digest[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Return the accepted timestep; never accept an earlier/replayed step. */
    public function matchingStep(string $secret, string $code, ?int $last = null): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) return null;
        $now = intdiv(time(), 30);
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if ($step >= 0 && ($last === null || $step > $last) &&
                hash_equals($this->codeAt($secret, $step), $code)) return $step;
        }
        return null;
    }

    private function recoveryHash(string $code): string
    {
        $key = (string) config('app.key');
        if ($key === '') throw new \RuntimeException('Missing application encryption key.');
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $code)), $key);
    }

    private function recoveryCodes(int $userId): array
    {
        DB::table('m24_two_factor_recovery_codes')->where('user_id', $userId)->delete();
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(8)));
            $codes[] = implode('-', str_split($raw, 4));
            DB::table('m24_two_factor_recovery_codes')->insert([
                'user_id' => $userId,
                'code_hash' => $this->recoveryHash($raw),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $codes;
    }

    private function password(User $user, string $password): void
    {
        if (!Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Password confirmation failed.']);
        }
    }

    public function setup(User $user, string $password, Request $request): array
    {
        $this->password($user, $password);
        if ($this->enabled((int) $user->id)) {
            throw ValidationException::withMessages(['two_factor' => 'Authenticator is already enabled.']);
        }
        $secret = $this->generateSecret();
        DB::table('m24_two_factor_credentials')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'pending_secret_ciphertext' => Crypt::encryptString($secret),
                'pending_expires_at' => now()->addMinutes(10),
                'updated_at' => now(),
            ]
        );
        $issuer = rawurlencode('Sign2Digital');
        $label = rawurlencode('Sign2Digital:'.$user->email);
        AuditLogger::record('mobile.security.2fa_setup_started', $user, $user, request: $request);
        return [
            'secret' => $secret,
            'otpauth_uri' => "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30",
            'expires_in' => 600,
        ];
    }

    public function confirm(User $user, string $code, Request $request): array
    {
        return DB::transaction(function () use ($user, $code, $request): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('m24_two_factor_credentials')
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if (!$row || $row->enabled_at !== null || !$row->pending_secret_ciphertext ||
                !$row->pending_expires_at || now()->greaterThanOrEqualTo($row->pending_expires_at)) {
                throw ValidationException::withMessages(['code' => 'Authenticator setup expired. Start again.']);
            }
            $secret = Crypt::decryptString($row->pending_secret_ciphertext);
            $step = $this->matchingStep($secret, $code);
            if ($step === null) {
                throw ValidationException::withMessages(['code' => 'Invalid authenticator code.']);
            }
            DB::table('m24_two_factor_credentials')->where('user_id', $user->id)->update([
                'secret_ciphertext' => $row->pending_secret_ciphertext,
                'pending_secret_ciphertext' => null, 'pending_expires_at' => null,
                'enabled_at' => now(), 'last_used_step' => $step, 'updated_at' => now(),
            ]);
            $codes = $this->recoveryCodes((int) $user->id);
            // A newly enabled second factor must protect all subsequently issued sessions.
            $this->revokeOtherSessions($user);
            AuditLogger::record('mobile.security.2fa_enabled', $user, $user, request: $request);
            return ['enabled' => true, 'recovery_codes' => $codes];
        }, 3);
    }

    private function revokeOtherSessions(User $user): void
    {
        $current = $user->currentAccessToken();
        if ($current && method_exists($current, 'getKey')) {
            $user->tokens()->where('id', '!=', $current->getKey())->delete();
        } else {
            $user->tokens()->delete();
        }
        DB::table('m24_two_factor_login_challenges')->where('user_id', $user->id)->delete();
    }

    /** Called inside a transaction holding the user/credential row lock. */
    private function consumeProof(User $user, string $code, object $credential): bool
    {
        if (!$credential->enabled_at || !$credential->secret_ciphertext) return false;
        $secret = Crypt::decryptString($credential->secret_ciphertext);
        $step = $this->matchingStep($secret, $code,
            $credential->last_used_step === null ? null : (int) $credential->last_used_step);
        if ($step !== null) {
            DB::table('m24_two_factor_credentials')->where('user_id', $user->id)
                ->update(['last_used_step' => $step, 'updated_at' => now()]);
            return true;
        }
        if (!preg_match('/^[A-Fa-f0-9]{4}(-?[A-Fa-f0-9]{4}){3}$/D', $code)) return false;
        $record = DB::table('m24_two_factor_recovery_codes')
            ->where('user_id', $user->id)->where('code_hash', $this->recoveryHash($code))
            ->whereNull('used_at')->lockForUpdate()->first();
        if (!$record) return false;
        DB::table('m24_two_factor_recovery_codes')->where('id', $record->id)
            ->update(['used_at' => now(), 'updated_at' => now()]);
        return true;
    }

    public function disable(User $user, string $password, string $code, Request $request): array
    {
        $this->password($user, $password);
        return DB::transaction(function () use ($user, $code, $request): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('m24_two_factor_credentials')
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if (!$row || !$this->consumeProof($user, $code, $row)) {
                throw ValidationException::withMessages(['code' => 'Invalid authenticator or recovery code.']);
            }
            DB::table('m24_two_factor_recovery_codes')->where('user_id', $user->id)->delete();
            DB::table('m24_two_factor_credentials')->where('user_id', $user->id)->delete();
            $this->revokeOtherSessions($user);
            AuditLogger::record('mobile.security.2fa_disabled', $user, $user, request: $request);
            return ['enabled' => false];
        }, 3);
    }

    public function regenerate(User $user, string $password, string $code, Request $request): array
    {
        $this->password($user, $password);
        return DB::transaction(function () use ($user, $code, $request): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('m24_two_factor_credentials')
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if (!$row || !$this->consumeProof($user, $code, $row)) {
                throw ValidationException::withMessages(['code' => 'Invalid authenticator or recovery code.']);
            }
            $codes = $this->recoveryCodes((int) $user->id);
            $this->revokeOtherSessions($user);
            AuditLogger::record('mobile.security.2fa_recovery_regenerated', $user, $user, request: $request);
            return ['recovery_codes' => $codes];
        }, 3);
    }

    public function createLoginTicket(User $user, string $device): string
    {
        $ticket = bin2hex(random_bytes(32));
        DB::table('m24_two_factor_login_challenges')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'ticket_hash' => hash('sha256', $ticket),
            'device_name' => $device,
            'attempts' => 0, 'expires_at' => now()->addMinutes(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $ticket;
    }

    public function verifyLogin(string $ticket, string $code, Request $request): string
    {
        // A row lock on the user serializes authenticator-code reuse across
        // devices and recovery-code consumption. Never create a Sanctum token
        // until all factors are verified and the login ticket is consumed.
        return DB::transaction(function () use ($ticket, $code, $request): string {
            $hash = hash('sha256', $ticket);
            $probe = DB::table('m24_two_factor_login_challenges')
                ->where('ticket_hash', $hash)->first();
            if (!$probe) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired sign-in verification.']);
            }
            $user = User::whereKey($probe->user_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('m24_two_factor_login_challenges')
                ->where('ticket_hash', $hash)->lockForUpdate()->first();
            if (!$row || $row->consumed_at || now()->greaterThanOrEqualTo($row->expires_at) ||
                $row->attempts >= 5 || !$user->is_active || !$user->email_verified_at) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired sign-in verification.']);
            }
            // Persist attempt limits even when the submitted proof is wrong.
            DB::table('m24_two_factor_login_challenges')->where('id', $row->id)
                ->update(['attempts' => $row->attempts + 1, 'updated_at' => now()]);
            $credential = DB::table('m24_two_factor_credentials')
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if (!$credential || !$this->consumeProof($user, $code, $credential)) {
                // Commit the failed-attempt counter rather than rolling it back.
                return '';
            }
            DB::table('m24_two_factor_login_challenges')->where('id', $row->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            $token = $user->createToken($row->device_name, ['*'],
                now()->addDays(30))->plainTextToken;
            AuditLogger::record('mobile.auth.2fa_verified', $user, $user,
                request: $request);
            return $token;
        }, 3);
    }
}
