<?php

namespace App\Services;

use App\Http\Controllers\PublicAccountDeletionController;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountErasureService
{
    public function erase(User $user, Request $request, ?User $administrator = null, ?int $ticketId = null): void
    {
        if ($user->is_platform_admin || $user->ownedCompanies()->exists()) {
            throw ValidationException::withMessages(['account' =>
                'Transfer company ownership and platform administration first.']);
        }
        $privateFiles = [];
        DB::transaction(function () use ($user, $request, $administrator, $ticketId, &$privateFiles): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_platform_admin || $locked->ownedCompanies()->exists()) {
                throw ValidationException::withMessages(['account' => 'Account ownership must be transferred.']);
            }
            $reference = null;
            if (!$locked->is_active) throw ValidationException::withMessages(['account' => 'Account is already inactive.']);
            if ($administrator) {
                $ticket = DB::table('m22_support_tickets')->where('id', $ticketId)
                    ->where('user_id', $locked->id)->where('category', 'account_deletion')
                    ->whereIn('status', ['open', 'in_progress', 'waiting_for_customer'])
                    ->lockForUpdate()->first();
                if (!$ticket || !$ticket->deletion_verified_email_hash ||
                    !hash_equals($ticket->deletion_verified_email_hash, PublicAccountDeletionController::emailHash($locked->email))) {
                    throw ValidationException::withMessages(['account' => 'A current verified deletion request is required.']);
                }
                $reference = $ticket->reference;
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
            DB::table('public_deletion_challenges')->where('user_id', $locked->id)->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $locked->id)->delete();
            }
            $locked->forceFill([
                'name' => 'Deleted account', 'email' => 'deleted-'.$locked->id.'@accounts.invalid',
                'password' => Str::random(64), 'phone_number' => null,
                'country_iso_code' => null, 'dialing_code' => null,
                'job_title' => null, 'email_verified_at' => null,
                'profile_photo_path' => null, 'profile_photo_mime' => null,
                'remember_token' => null, 'is_active' => false,
                'google_subject' => null, 'apple_subject' => null,
            ])->save();
            $locked->tokens()->delete();
            AuditLogger::record($administrator ? 'admin.account.deleted' : 'mobile.account.deleted',
                $administrator ?? $locked, $locked,
                metadata: ['personal_profile_erased' => true, 'business_records_retained' => true, 'request_reference' => $reference],
                request: $request);
        });
        foreach (array_unique($privateFiles) as $path) Storage::disk('local')->delete($path);
    }
}
