<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppleIdTokenVerifier;
use App\Services\M24TwoFactorService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AppleMobileAuthController extends Controller
{
    public function challenge(): JsonResponse
    {
        $nonce = bin2hex(random_bytes(32));
        DB::table('apple_sign_in_challenges')->where('expires_at', '<=', now())->delete();
        DB::table('apple_sign_in_challenges')->insert([
            'nonce_hash' => hash('sha256', $nonce), 'expires_at' => now()->addMinutes(5),
        ]);
        return response()->json(['data' => ['nonce' => $nonce]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function __invoke(Request $request, AppleIdTokenVerifier $apple,
        M24TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'nonce' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);
        $identity = $apple->verify($data['id_token'], $data['nonce']);
        // Atomic one-time consumption, even if login later fails.
        if (DB::table('apple_sign_in_challenges')
            ->where('nonce_hash', hash('sha256', $data['nonce']))
            ->where('expires_at', '>', now())->delete() !== 1) {
            throw ValidationException::withMessages(['apple' => 'Apple sign-in expired. Please try again.']);
        }
        $device = trim($data['device_name'] ?? 'Sign2Digital Apple Mobile');
        return DB::transaction(function () use ($request, $twoFactor, $identity, $data, $device): JsonResponse {
            $user = User::query()->where('apple_subject', $identity['sub'])->lockForUpdate()->first();
            if (!$user) {
                $email = $identity['email'];
                if (!$email) {
                    throw ValidationException::withMessages(['apple' => 'A verified Apple email is required to create an account.']);
                }
                $existing = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($existing) {
                    // Apple does not prove ownership of our existing account.
                    // Never automatically merge independent identity providers.
                    throw ValidationException::withMessages(['apple' =>
                        'This email already has an account. Please sign in using your original login method.']);
                }
                $user = new User();
                $user->name = trim($data['name'] ?? '') ?: 'Apple User';
                $user->email = $email;
                $user->password = Str::random(80);
                $user->email_verified_at = now();
                $user->apple_subject = $identity['sub'];
                $user->save();
            }

            if (!$user->is_active || !$user->email_verified_at ||
                !hash_equals((string) $user->apple_subject, $identity['sub'])) {
                throw ValidationException::withMessages(['apple' => 'This account cannot sign in with Apple.']);
            }
            if ($twoFactor->enabled((int) $user->id)) {
                $ticket = $twoFactor->createLoginTicket($user, $device);
                AuditLogger::record('mobile.auth.apple_2fa_required', $user, $user, request: $request);
                return response()->json(['data' => [
                    'purpose' => 'login', 'requires_two_factor' => true,
                    'two_factor_ticket' => $ticket, 'expires_in' => 300,
                ]], 200, ['Cache-Control' => 'private, no-store']);
            }
            $token = $user->createToken($device, ['*'], now()->addDays(30))->plainTextToken;
            AuditLogger::record('mobile.auth.apple_verified', $user, $user, request: $request);
            return response()->json(['data' => [
                'purpose' => 'login', 'access_token' => $token, 'token_type' => 'Bearer',
                'user' => $user->only(['id', 'name', 'email', 'email_verified_at']),
            ]], 200, ['Cache-Control' => 'private, no-store']);
        }, 3);
    }
}
