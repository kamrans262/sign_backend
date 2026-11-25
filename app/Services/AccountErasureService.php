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
    public function erase(User $user, Request $request, ?User $administrator = null, ?int $ticketId = null, bool $closeSoleOwned = false, array $transfers = []): void
    {
        if ($user->is_platform_admin || (!$closeSoleOwned && !$transfers && $user->ownedCompanies()->exists())) {
            throw ValidationException::withMessages(['account' =>
                'Transfer company ownership and platform administration first.']);
        }
        $privateFiles = [];
        DB::transaction(function () use ($user, $request, $administrator, $ticketId, $closeSoleOwned, $transfers, &$privateFiles): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_platform_admin || (!$closeSoleOwned && !$transfers && $locked->ownedCompanies()->exists())) {
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
            foreach ($locked->ownedCompanies()->orderBy('id')->lockForUpdate()->get() as $company) {
                $successor = $transfers[(string)$company->id] ?? null;
                $members = DB::table('company_user')->where('company_id',$company->id)
                    ->where('user_id','!=',$locked->id)->where('status','active')->lockForUpdate()->get();
                if ($successor !== null) {
                    $membership = $members->firstWhere('user_id',(int)$successor);
                    $next = User::whereKey($successor)->where('is_active',true)->whereNotNull('email_verified_at')->lockForUpdate()->first();
                    if (!$membership || $membership->role !== 'admin' || !$next) {
                        throw ValidationException::withMessages(['account'=>'Choose a verified active company administrator as successor.']);
                    }
                    $company->owner_user_id = $next->id;
                    $company->save();
                    DB::table('company_user')->where('company_id',$company->id)->where('user_id',$next->id)
                        ->update(['role'=>'owner','permissions'=>null,'company_role_id'=>null,'updated_at'=>now()]);
                    AuditLogger::record('account.deletion.ownership_transferred',$administrator??$locked,$next,$company,request:$request);
                } elseif ($closeSoleOwned && $members->isEmpty()) {
                    // Preserve the owner tombstone and all business evidence, suspend access.
                    $company->status='suspended';
                    $company->save();
                    DB::table('company_invitations')->where('company_id',$company->id)->whereNull('accepted_at')->whereNull('revoked_at')
                        ->update(['revoked_at'=>now(),'updated_at'=>now()]);
                    AuditLogger::record('account.deletion.sole_company_suspended',$administrator??$locked,$company,$company,request:$request);
                } else {
                    throw ValidationException::withMessages(['account'=>'Transfer ownership of shared companies or explicitly close your sole-owner companies.']);
                }
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
            $manualAppleRevocation = !empty($locked->apple_subject) && !DB::table('m25_apple_credentials')->where('user_id',$locked->id)->exists();
            app(M25AppleIdentity::class)->queueErasure((int)$locked->id);
            DB::table('m25_ai_consents')->where('user_id',$locked->id)->delete();
            DB::table('m25_deletion_challenges')->where('user_id',$locked->id)->delete();
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
                metadata: ['personal_profile_erased' => true, 'business_records_retained' => true, 'request_reference' => $reference, 'manual_apple_revocation' => $manualAppleRevocation],
                request: $request);
        });
        foreach (array_unique($privateFiles) as $path) Storage::disk('local')->delete($path);
        // Revocation is durably queued; cron handles retries without delaying account erasure.
        app(M25AppleIdentity::class)->retry(1);
    }
}
