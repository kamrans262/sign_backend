<?php

namespace App\Services;

use App\Mail\EmailOtpCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailOtpService
{
    public const EXPIRES_IN = 300;
    public const RESEND_AFTER = 60;
    public const MAX_ATTEMPTS = 5;

    public function issue(User $user, string $purpose): EmailOtpChallenge
    {
        if (!in_array($purpose, ['register', 'login', 'reset'], true) || !$user->is_active) {
            throw new \InvalidArgumentException('Invalid OTP request.');
        }

        // Serialize issues per account to prevent two simultaneous requests
        // from generating two different codes and invalidating each other.
        // Only a keyed verifier is stored: six-digit codes must not be
        // recoverable by an offline brute-force attack against a database dump.
        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($user, $purpose, $code): EmailOtpChallenge {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser || !$lockedUser->is_active) {
                throw ValidationException::withMessages(['email' => 'Unable to request a verification code.']);
            }

            $recent = EmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->latest()
                ->first();

            if ($recent && $recent->created_at->greaterThan(now()->subSeconds(self::RESEND_AFTER))) {
                throw ValidationException::withMessages(['email' => 'Please wait 60 seconds before requesting another code.']);
            }

            EmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $challenge = new EmailOtpChallenge();
            $challenge->id = (string) Str::uuid();
            $challenge->user_id = $user->id;
            $challenge->purpose = $purpose;
            $challenge->code_hash = self::codeVerifier($challenge->id, $code);
            $challenge->attempts = 0;
            $challenge->expires_at = now()->addSeconds(self::EXPIRES_IN);
            $challenge->save();

            return $challenge;
        });

        try {
            Mail::to($user->email)->send(new EmailOtpCodeMail($code, $purpose));
        } catch (\Throwable $error) {
            // A failed send is not a successful code request; remove the
            // unusable code so the person can retry without waiting a minute.
            EmailOtpChallenge::query()->whereKey($challenge->id)->delete();
            report($error);
            throw ValidationException::withMessages([
                'email' => 'Unable to send a verification email. Please try again.',
            ]);
        }

        return $challenge;
    }

    public function resend(string $challengeId): EmailOtpChallenge
    {
        $old = EmailOtpChallenge::query()->with('user')->find($challengeId);
        if (!$old || $old->consumed_at || !$old->user?->is_active) {
            throw ValidationException::withMessages(['challenge_id' => 'Invalid or expired verification request.']);
        }

        if ($old->created_at->greaterThan(now()->subSeconds(self::RESEND_AFTER))) {
            throw ValidationException::withMessages(['challenge_id' => 'Please wait 60 seconds before requesting a new code.']);
        }

        return $this->issue($old->user, $old->purpose);
    }

    /** @return array{user: User, purpose: string, reset_token?: string} */
    public function verify(string $challengeId, string $code): array
    {
        return DB::transaction(function () use ($challengeId, $code): array {
            $challenge = EmailOtpChallenge::query()->lockForUpdate()->find($challengeId);
            if (!$challenge || $challenge->consumed_at || $challenge->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired code.']);
            }
            if ($challenge->attempts >= self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['code' => 'Too many attempts. Request another code.']);
            }

            $challenge->attempts++;
            $correct = hash_equals($challenge->code_hash, self::codeVerifier($challenge->id, $code));
            if (!$correct) {
                $challenge->save();
                // Do not throw from inside the transaction: attempts must commit.
                return ['invalid' => true];
            }

            $user = User::query()->lockForUpdate()->find($challenge->user_id);
            if (!$user || !$user->is_active) {
                return ['invalid' => true];
            }

            $challenge->consumed_at = now();
            $challenge->save();
            $result = ['user' => $user, 'purpose' => $challenge->purpose];

            if ($challenge->purpose === 'register') {
                $user->email_verified_at ??= now();
                $user->save();
            } elseif ($challenge->purpose === 'login') {
                if (!$user->email_verified_at) return ['invalid' => true];
            } elseif ($challenge->purpose === 'reset') {
                $token = Str::random(64);
                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $user->email],
                    ['token' => hash('sha256', $token), 'created_at' => now()]
                );
                $result['reset_token'] = $token;
            }

            return $result;
        });
    }

    public static function codeVerifier(string $challengeId, string $code): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('Missing application key for OTP verification.');
        }
        return hash_hmac('sha256', $challengeId.'|'.$code, $key);
    }

    public static function challengeResponse(EmailOtpChallenge $challenge): array
    {
        return [
            'challenge_id' => $challenge->id,
            'purpose' => $challenge->purpose,
            'expires_in' => self::EXPIRES_IN,
            'resend_after' => self::RESEND_AFTER,
            'message' => 'Check your email for a verification code.',
        ];
    }
}
