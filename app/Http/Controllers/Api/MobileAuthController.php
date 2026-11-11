<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EmailOtpChallenge;
use App\Services\EmailOtpService;
use App\Services\M24TwoFactorService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function __construct(private readonly EmailOtpService $otp) {}

    private static function passwordRule(): Password
    {
        return Password::min(8)->mixedCase()->numbers()->symbols();
    }

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['required', 'string', 'max:40'],
            'country_iso_code' => ['required', 'string', 'size:2'],
            'dialing_code' => ['required', 'string', 'max:8'],
            'password' => ['required', 'confirmed', self::passwordRule()],
        ]);
        $existing = User::query()->where('email', $data['email'])->first();
        if ($existing) {
            if ($existing->email_verified_at || !$existing->is_active || !Hash::check($data['password'], $existing->password)) {
                throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
            }
            // Continue a previously unverified signup without creating a duplicate account.
            $challenge = $this->otp->issue($existing, 'register');
            return response()->json(['data' => EmailOtpService::challengeResponse($challenge)]);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'],
            'country_iso_code' => strtoupper($data['country_iso_code']),
            'dialing_code' => $data['dialing_code'],
            'password' => $data['password'],
        ]);
        $challenge = $this->otp->issue($user, 'register');
        AuditLogger::record('mobile.auth.registration_started', $user, $user, request: $request);
        return response()->json(['data' => EmailOtpService::challengeResponse($challenge)], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        if (!$user || !$user->is_active || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }
        $challenge = $this->otp->issue($user, $user->email_verified_at ? 'login' : 'register');
        AuditLogger::record('mobile.auth.login_code_requested', $user, $user, request: $request);
        return response()->json(['data' => EmailOtpService::challengeResponse($challenge)]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::query()->where('email', strtolower(trim($data['email'])))->where('is_active', true)->first();
        // Unknown addresses receive a genuine decoy challenge; this keeps
        // subsequent verify/resend responses indistinguishable.
        $challengeId = $user ? null : $this->otp->issueAnonymousReset()->id;
        if ($user) {
            $challenge = $this->otp->issue($user, 'reset');
            // New recovery requests invalidate outstanding reset grants.
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $challengeId = $challenge->id;
            AuditLogger::record('mobile.auth.password_reset_requested', $user, $user, request: $request);
        }
        // A plausible opaque challenge is returned even for unknown emails.
        return response()->json(['data' => [
            'challenge_id' => $challengeId,
            'purpose' => 'reset',
            'expires_in' => EmailOtpService::EXPIRES_IN,
            'resend_after' => EmailOtpService::RESEND_AFTER,
            'message' => 'If the address exists, a code has been sent.',
        ]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge_id' => ['required', 'uuid']]);
        $challenge = $this->otp->resend($data['challenge_id']);
        AuditLogger::record('mobile.auth.code_resent', $challenge->user, $challenge->user, request: $request);
        return response()->json(['data' => EmailOtpService::challengeResponse($challenge)]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'regex:/^[0-9]{6}$/'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $result = $this->otp->verify($data['challenge_id'], $data['code']);
        if (!empty($result['invalid'])) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired code.']);
        }
        $user = $result['user'];
        $purpose = $result['purpose'];

        if ($purpose === 'reset') {
            AuditLogger::record('mobile.auth.password_reset_code_verified', $user, $user, request: $request);
            return response()->json(['data' => [
                'purpose' => 'reset',
                'email' => $user->email,
                'reset_token' => $result['reset_token'],
                'expires_in' => 600,
            ]]);
        }

        // Enrollment and token issuance serialize on the same account lock.
        // If 2FA is enabled concurrently, this endpoint cannot slip through
        // the check and issue a bearer with only email OTP.
        $device = trim((string) ($data['device_name'] ?? 'Sign2Digital Mobile'));
        return DB::transaction(function () use ($user, $device, $purpose, $request): JsonResponse {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (!$locked->is_active || !$locked->email_verified_at) {
                throw ValidationException::withMessages(['code' => 'Invalid verification request.']);
            }
            $mfa = app(M24TwoFactorService::class);
            if ($mfa->enabled((int) $locked->id)) {
                $ticket = $mfa->createLoginTicket($locked, $device);
                AuditLogger::record('mobile.auth.2fa_required', $locked, $locked,
                    request: $request);
                return response()->json(['data' => [
                    'purpose' => $purpose, 'requires_two_factor' => true,
                    'two_factor_ticket' => $ticket, 'expires_in' => 300,
                ]], 200, ['Cache-Control' => 'private, no-store']);
            }
            $token = $locked->createToken($device, ['*'],
                now()->addDays(30))->plainTextToken;
            AuditLogger::record('mobile.auth.otp_verified', $locked, $locked,
                metadata: ['purpose' => $purpose], request: $request);
            return response()->json(['data' => [
                'purpose' => $purpose,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $locked->only(['id','name','email','email_verified_at','phone_number']),
            ]], 200, ['Cache-Control' => 'private, no-store']);
        }, 3);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', self::passwordRule()],
        ]);

        DB::transaction(function () use ($data, $request): void {
            $user = User::query()->where('email', $data['email'])->lockForUpdate()->first();
            $grant = DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();
            if (!$user || !$user->is_active || !$grant ||
                now()->diffInSeconds($grant->created_at, false) < -600 ||
                !hash_equals($grant->token, hash('sha256', $data['reset_token']))) {
                throw ValidationException::withMessages(['reset_token' => 'Invalid or expired reset request.']);
            }
            $user->password = $data['password'];
            $user->save();
            $user->tokens()->delete();
            DB::table('m24_two_factor_login_challenges')->where('user_id', $user->id)->delete();
            // Resetting a password must also cancel all outstanding OTP
            // challenges issued under the previous credentials.
            EmailOtpChallenge::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
            AuditLogger::record('mobile.auth.password_reset_completed', $user, $user, request: $request);
        });
        return response()->json(['message' => 'Password updated. Sign in with your new password.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
        AuditLogger::record('mobile.auth.logout', $request->user(), $request->user(), request: $request);
        return response()->json(['message' => 'Signed out.']);
    }
}
