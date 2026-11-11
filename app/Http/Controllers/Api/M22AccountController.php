<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\M22EmailChangeCodeMail;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class M22AccountController extends Controller
{
    private function actor(Request $request): User
    {
        return $request->user();
    }

    private function password(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (!Hash::check($data['password'], $this->actor($request)->password)) {
            throw ValidationException::withMessages(['password' => 'Password confirmation failed.']);
        }
    }

    public function security(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? (int) $current->id : null;
        $sessions = $user->tokens()->orderByDesc('id')->limit(100)->get()->map(
            static fn ($token) => [
                'id' => (string) $token->id,
                'device_name' => (string) $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_current' => $currentId !== null && (int) $token->id === $currentId,
            ])->all();
        return response()->json(['data' => [
            'two_factor_enabled' => app(\App\Services\M24TwoFactorService::class)
                ->enabled((int) $user->id),
            'two_factor_available' => true,
            'sessions' => $sessions,
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $this->actor($request);
        if (!Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }
        DB::transaction(function () use ($user, $data, $request): void {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (!Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
            }
            $locked->password = $data['password'];
            $locked->save();
            $locked->tokens()->delete();
            DB::table('m24_two_factor_login_challenges')->where('user_id',$user->id)->delete();
            DB::table('email_otp_challenges')->where('user_id', $user->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('m22_email_change_challenges')->where('user_id', $user->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            AuditLogger::record('mobile.security.password_changed', $user, $user, request: $request);
        });
        return response()->json(['data' => ['requires_login' => true]],
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function revokeSession(Request $request, int $session): JsonResponse
    {
        $this->password($request);
        $user = $this->actor($request);
        $token = $user->tokens()->whereKey($session)->firstOrFail();
        $current = $user->currentAccessToken();
        abort_if($current instanceof PersonalAccessToken && $current->id === $token->id,
            422, 'Current session must be signed out through Logout.');
        $token->delete();
        AuditLogger::record('mobile.security.session_revoked', $user, $user,
            metadata: ['token_id' => $session], request: $request);
        return response()->json(['data' => ['revoked' => true]]);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $this->password($request);
        $user = $this->actor($request);
        $current = $user->currentAccessToken();
        abort_unless($current instanceof PersonalAccessToken, 403);
        $count = $user->tokens()->where('id', '!=', $current->id)->delete();
        AuditLogger::record('mobile.security.other_sessions_revoked', $user, $user,
            metadata: ['count' => $count], request: $request);
        return response()->json(['data' => ['revoked' => $count]]);
    }

    private function preferenceData(User $user): array
    {
        $row = DB::table('m22_user_preferences')->where('user_id', $user->id)->first();
        return [
            'language' => $row?->language ?? 'English',
            'dateFormat' => $row?->date_format ?? 'DD/MM/YYYY',
            'timeFormat' => $row?->time_format ?? '24 hours',
            'timeZone' => $row?->timezone ?? 'Europe/Zurich',
            'pushNotifications' => (bool) ($row?->push_notifications ?? true),
            'emailNotifications' => (bool) ($row?->email_notifications ?? true),
            'smsNotifications' => (bool) ($row?->sms_notifications ?? false),
        ];
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferenceData($this->actor($request))],
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(['English','French','German','Italian'])],
            'dateFormat' => ['required', Rule::in(['DD/MM/YYYY','MM/DD/YYYY','YYYY-MM-DD'])],
            'timeFormat' => ['required', Rule::in(['24 hours','12 hours'])],
            'timeZone' => ['required', Rule::in(['Europe/Zurich','Europe/London','America/New_York','Asia/Karachi','UTC'])],
            'pushNotifications' => ['required','boolean'],
            'emailNotifications' => ['required','boolean'],
            'smsNotifications' => ['required','boolean'],
        ]);
        if ($data['smsNotifications']) {
            throw ValidationException::withMessages(['smsNotifications' => 'SMS delivery is not connected.']);
        }
        $user = $this->actor($request);
        DB::table('m22_user_preferences')->updateOrInsert(
            ['user_id' => $user->id],
            ['language' => $data['language'], 'date_format' => $data['dateFormat'],
                'time_format' => $data['timeFormat'], 'timezone' => $data['timeZone'],
                'push_notifications' => $data['pushNotifications'],
                'email_notifications' => $data['emailNotifications'],
                'sms_notifications' => false, 'updated_at' => now(),
                // updateOrInsert on an existing row preserves original timestamp.
            ]
        );
        // M22 is a global consent ceiling above M20 per-company opt-in.
        // Disabling push immediately cancels all registered device deliveries.
        $locale = $data['language'] === 'French' ? 'fr' : 'en';
        DB::table('m20_notification_preferences')->where('user_id', $user->id)
            ->update(['locale' => $locale, 'timezone' => $data['timeZone'],
                'updated_at' => now()]);
        if (!$data['pushNotifications']) {
            DB::table('m20_notification_preferences')->where('user_id', $user->id)
                ->update(['push_enabled' => false, 'updated_at' => now()]);
            DB::table('m20_push_devices')->where('user_id', $user->id)
                ->where('enabled', true)->update([
                    'enabled' => false, 'revoked_at' => now(), 'updated_at' => now(),
                ]);
        }
        AuditLogger::record('mobile.account.preferences_updated', $user, $user, request: $request);
        return $this->preferences($request);
    }

    public function savePhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required','file','max:4096']]);
        $upload = $request->file('photo');
        $bytes = file_get_contents($upload->getRealPath());
        if (!is_string($bytes) || strlen($bytes) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['photo' => 'Photo exceeds upload limit.']);
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 ||
            $info[1] > 4096 || !in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp'], true)) {
            throw ValidationException::withMessages(['photo' => 'Use a valid JPEG, PNG or WebP image up to 4096px.']);
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            throw ValidationException::withMessages(['photo' => 'Invalid image bytes.']);
        }
        ob_start();
        imagejpeg($source, null, 85);
        $clean = ob_get_clean();
        imagedestroy($source);
        if (!is_string($clean) || strlen($clean) > 4 * 1024 * 1024) {
            throw ValidationException::withMessages(['photo' => 'Could not normalize this photo.']);
        }
        $user = $this->actor($request);
        $path = 'm22/profile/'.$user->id.'/'.Str::uuid().'.jpg';
        if (!Storage::disk('local')->put($path, $clean)) {
            throw ValidationException::withMessages(['photo' => 'Could not store the image.']);
        }
        $old = $user->profile_photo_path;
        try {
            $user->forceFill(['profile_photo_path' => $path,
                'profile_photo_mime' => 'image/jpeg'])->save();
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($old) Storage::disk('local')->delete($old);
        AuditLogger::record('mobile.account.photo_updated', $user, $user, request: $request);
        return response()->json(['data' => ['has_photo' => true]]);
    }

    public function photo(Request $request)
    {
        $user = $this->actor($request);
        $path = $user->profile_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $old = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => null,'profile_photo_mime' => null])->save();
        if ($old) Storage::disk('local')->delete($old);
        AuditLogger::record('mobile.account.photo_removed', $user, $user, request: $request);
        return response()->json(['data' => ['has_photo' => false]]);
    }

    public function emailStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required','email','max:255'],
            'password' => ['required','string'],
        ]);
        $user = $this->actor($request);
        $email = strtolower(trim($data['email']));
        if (!Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Password confirmation failed.']);
        }
        if ($email === $user->email || User::where('email',$email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Email cannot be used.']);
        }
        $last = DB::table('m22_email_change_challenges')->where('user_id', $user->id)
            ->orderByDesc('created_at')->first();
        if ($last && now()->subSeconds(60)->lessThan($last->created_at)) {
            throw ValidationException::withMessages(['email' => 'Please wait before requesting another code.']);
        }
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        $verifier = hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
        DB::transaction(function () use ($user, $email, $id, $verifier): void {
            DB::table('m22_email_change_challenges')->where('user_id', $user->id)
                ->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('m22_email_change_challenges')->insert([
                'id' => $id, 'user_id' => $user->id,
                'pending_email' => $email, 'code_hash' => $verifier,
                'attempts' => 0, 'expires_at' => now()->addMinutes(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        try {
            Mail::to($email)->send(new M22EmailChangeCodeMail($code));
        } catch (\Throwable $e) {
            DB::table('m22_email_change_challenges')->where('id', $id)->delete();
            throw ValidationException::withMessages(['email' => 'Unable to send a verification email.']);
        }
        AuditLogger::record('mobile.account.email_change_requested', $user, $user, request: $request);
        return response()->json(['data' => [
            'challenge_id' => $id, 'expires_in' => 600,
            'message' => 'Check your new email for a verification code.',
        ]]);
    }

    public function emailVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required','uuid'], 'code' => ['required','regex:/^[0-9]{6}$/'],
        ]);
        $user = $this->actor($request);
        $result = DB::transaction(function () use ($user, $data, $request): bool {
            $challenge = DB::table('m22_email_change_challenges')
                ->where('id',$data['challenge_id'])->where('user_id',$user->id)
                ->lockForUpdate()->first();
            if (!$challenge || $challenge->consumed_at ||
                now()->greaterThanOrEqualTo($challenge->expires_at) || $challenge->attempts >= 5) {
                return false;
            }
            DB::table('m22_email_change_challenges')->where('id',$challenge->id)
                ->update(['attempts' => $challenge->attempts + 1, 'updated_at' => now()]);
            $expected = hash_hmac('sha256',$challenge->id.'|'.$data['code'],(string) config('app.key'));
            if (!hash_equals($challenge->code_hash,$expected)) return false;
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (User::where('email',$challenge->pending_email)->where('id','!=',$user->id)->exists()) return false;
            $locked->email = $challenge->pending_email;
            $locked->email_verified_at = now();
            $locked->save();
            $locked->tokens()->delete();
            DB::table('m24_two_factor_login_challenges')->where('user_id',$locked->id)->delete();
            DB::table('m22_email_change_challenges')->where('id',$challenge->id)->update([
                'consumed_at' => now(), 'updated_at' => now(),
            ]);
            AuditLogger::record('mobile.account.email_changed', $user, $locked, request: $request);
            return true;
        });
        if (!$result) throw ValidationException::withMessages(['code'=>'Invalid or expired verification code.']);
        return response()->json(['data' => ['verified' => true, 'requires_login' => true]],
            200, ['Cache-Control'=>'private, no-store']);
    }

    public function emailCancel(Request $request): JsonResponse
    {
        DB::table('m22_email_change_challenges')->where('user_id',$this->actor($request)->id)
            ->whereNull('consumed_at')->update(['consumed_at'=>now()]);
        return response()->json(['data'=>['cancelled'=>true]]);
    }

    public function export(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $userData = $user->only(['id','name','email','job_title','phone_number',
            'country_iso_code','dialing_code','created_at']);
        return response()->json(['data' => [
            'account' => $userData,
            'preferences' => $this->preferenceData($user),
            'support_tickets' => DB::table('m22_support_tickets')
                ->where('user_id', $user->id)->orderBy('id')
                ->get(['id','reference','category','subject','description','status','created_at'])
                ->map(function ($ticket) {
                    $ticket->replies = DB::table('m22_support_replies')
                        ->where('ticket_id', $ticket->id)->where('internal', false)
                        ->orderBy('id')->get(['body','created_at'])->all();
                    $ticket->attachments = DB::table('m22_support_attachments')
                        ->where('ticket_id', $ticket->id)
                        ->get(['original_name','mime_type','byte_size'])->all();
                    return $ticket;
                })->all(),
            'scope' => 'personal_account_only_company_documents_not_included',
        ]], 200, ['Cache-Control'=>'private, no-store']);
    }


    /**
     * Erase personal profile and private support data without destroying
     * issued company records, legal audit evidence or signed documents.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $this->password($request);
        $user = $this->actor($request);
        if ($user->is_platform_admin || $user->ownedCompanies()->exists()) {
            throw ValidationException::withMessages(['account' =>
                'Transfer company ownership and platform administration first.']);
        }
        $privateFiles = [];
        DB::transaction(function () use ($user, $request, &$privateFiles): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_platform_admin || $locked->ownedCompanies()->exists()) {
                throw ValidationException::withMessages(['account' => 'Account ownership must be transferred.']);
            }
            $privateFiles = array_merge(
                $locked->profile_photo_path ? [$locked->profile_photo_path] : [],
                DB::table('m22_support_attachments as a')
                    ->join('m22_support_tickets as t', 't.id', '=', 'a.ticket_id')
                    ->where('t.user_id', $locked->id)->pluck('a.private_path')->all()
            );
            DB::table('m22_support_tickets')->where('user_id', $locked->id)->delete();
            DB::table('m22_user_preferences')->where('user_id', $locked->id)->delete();
            DB::table('m22_email_change_challenges')->where('user_id', $locked->id)->delete();
            DB::table('m24_two_factor_login_challenges')->where('user_id', $locked->id)->delete();
            DB::table('m24_two_factor_recovery_codes')->where('user_id', $locked->id)->delete();
            DB::table('m24_two_factor_credentials')->where('user_id', $locked->id)->delete();
            DB::table('email_otp_challenges')->where('user_id', $locked->id)->delete();
            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            DB::table('company_user')->where('user_id', $locked->id)
                ->update(['status' => 'inactive', 'updated_at' => now()]);
            DB::table('m20_notification_preferences')->where('user_id', $locked->id)
                ->update(['push_enabled' => false, 'updated_at' => now()]);
            DB::table('m20_push_devices')->where('user_id', $locked->id)->update([
                'enabled' => false, 'revoked_at' => now(),
                'fid_hash' => hash('sha256', 'deleted-'.$locked->id),
                'encrypted_fid' => Crypt::encryptString('revoked'),
                'updated_at' => now(),
            ]);
            $locked->forceFill([
                'name' => 'Deleted account', 'email' => 'deleted-'.$locked->id.'@accounts.invalid',
                'password' => Str::random(64), 'phone_number' => null,
                'country_iso_code' => null, 'dialing_code' => null,
                'job_title' => null, 'email_verified_at' => null,
                'profile_photo_path' => null, 'profile_photo_mime' => null,
                'remember_token' => null, 'is_active' => false,
            ])->save();
            $locked->tokens()->delete();
            AuditLogger::record('mobile.account.deleted', $locked, $locked,
                metadata: ['personal_profile_erased' => true, 'business_records_retained' => true],
                request: $request);
        });
        foreach (array_unique($privateFiles) as $path) Storage::disk('local')->delete($path);
        return response()->json(['data' => [
            'deleted' => true, 'requires_login' => true,
            'business_records_retained' => true,
        ]], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function deactivate(Request $request): JsonResponse
    {
        $this->password($request);
        $user = $this->actor($request);
        if ($user->ownedCompanies()->where('status','active')->exists()) {
            throw ValidationException::withMessages(['account' =>
                'Transfer or close your owned companies before deactivating this account.']);
        }
        DB::transaction(function () use ($user, $request): void {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();
            AuditLogger::record('mobile.account.deactivated', $user, $user, request: $request);
        });
        return response()->json(['data'=>['deactivated'=>true]]);
    }
}
