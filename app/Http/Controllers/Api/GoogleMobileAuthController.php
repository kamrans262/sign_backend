<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use App\Services\M24TwoFactorService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GoogleMobileAuthController extends Controller
{
    public function __invoke(Request $request, GoogleIdTokenVerifier $google,
        M24TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $identity = $google->verify($data['id_token']);
        $device = trim($data['device_name'] ?? 'Sign2Digital Google Sign-In');
        return DB::transaction(function () use ($request, $twoFactor, $identity, $device): JsonResponse {
            $user = User::query()->where('google_subject', $identity['sub'])
                ->lockForUpdate()->first();

            if (!$user) {
                // Never silently link a non-authoritative third-party address
                // to an existing password account.
                $existing = User::query()->where('email', $identity['email'])
                    ->lockForUpdate()->first();
                if ($existing) {
                    if (!$identity['authoritative_email'] ||
                        !$existing->email_verified_at || !$existing->is_active ||
                        ($existing->google_subject !== null &&
                         !hash_equals($existing->google_subject, $identity['sub']))) {
                        throw ValidationException::withMessages(['google' =>
                            'This email already has an account. Sign in with email to continue.']);
                    }
                    $user = $existing;
                    $user->google_subject = $identity['sub'];
                    $user->save();
                } else {
                    $user = new User();
                    $user->name = mb_substr($identity['name'], 0, 255);
                    $user->email = $identity['email'];
                    // No usable password is created for a Google-only account.
                    $user->password = Str::random(80);
                    $user->email_verified_at = now();
                    $user->google_subject = $identity['sub'];
                    $user->save();
                }
            }

            if (!$user->is_active || !$user->email_verified_at ||
                $user->google_subject !== $identity['sub']) {
                throw ValidationException::withMessages(['google' =>
                    'This account cannot sign in with Google.']);
            }

            if ($twoFactor->enabled((int) $user->id)) {
                $ticket = $twoFactor->createLoginTicket($user, $device);
                AuditLogger::record('mobile.auth.google_2fa_required', $user, $user,
                    request: $request);
                return response()->json(['data' => [
                    'purpose' => 'login', 'requires_two_factor' => true,
                    'two_factor_ticket' => $ticket, 'expires_in' => 300,
                ]], 200, ['Cache-Control' => 'private, no-store']);
            }

            $token = $user->createToken($device, ['*'], now()->addDays(30))
                ->plainTextToken;
            AuditLogger::record('mobile.auth.google_verified', $user, $user,
                request: $request);
            return response()->json(['data' => [
                'purpose' => 'login',
                'access_token' => $token, 'token_type' => 'Bearer',
                'user' => $user->only(['id', 'name', 'email', 'email_verified_at']),
            ]], 200, ['Cache-Control' => 'private, no-store']);
        }, 3);
    }
}
