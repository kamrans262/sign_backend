<?php

namespace App\Services;

use App\Mail\EmailOtpCodeMail;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        // These codes exist only in request memory and in the mail; never log
        // them, return them through an API, or store them in plain text.
        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($user, $purpose, $code): EmailOtpChallenge {
            EmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $challenge = new EmailOtpChallenge();
            $challenge->id = (string) Str::uuid();
            $challenge->user_id = $user->id;
            $challenge->purpose = $purpose;
            $challenge->code_hash = Hash::make($code);
            $challenge->attempts = 0;
            $challenge->expires_at = now()->addSeconds(self::EXPIRES_IN);
            $challenge->save();
            return $challenge;
        });

        Mail::to($user->email)->send(new EmailOtpCodeMail($code, $purpose));

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
            $correct = Hash::check($code, $challenge->code_hash);
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
