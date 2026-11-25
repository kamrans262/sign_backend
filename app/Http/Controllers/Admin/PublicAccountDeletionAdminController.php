<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountErasureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class PublicAccountDeletionAdminController extends Controller
{
    public function complete(Request $request, int $ticket, AccountErasureService $eraser)
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'in:DELETE'],
            'reviewed' => ['accepted'],
            'close_sole_owned' => ['sometimes','accepted'],
        ]);
        $admin = $request->user();
        abort_unless($admin?->is_active && $admin?->is_platform_admin, 403);
        if (!Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['password' => 'Administrator password confirmation failed.']);
        }
        $item = DB::table('m22_support_tickets')->where('id', $ticket)
            ->where('category', 'account_deletion')->whereNotNull('deletion_verified_email_hash')->firstOrFail();
        $user = User::findOrFail($item->user_id);
        $email = $user->email;
        $ref = $item->reference;
        $manualAppleRevocation = !empty($user->apple_subject) && !DB::table('m25_apple_credentials')->where('user_id',$user->id)->exists();
        $eraser->erase($user, $request, $admin, $ticket, closeSoleOwned:$request->boolean('close_sole_owned'));
        try {
            Mail::raw("Your Sign2Digital account deletion request ".$ref." has been processed. Your personal account has been erased from active use. Required business and audit records may be retained. Review any active Stripe subscription separately." . ($manualAppleRevocation ? " To remove remaining Sign in with Apple authorization, open Apple Account settings and stop using Sign in with Apple for Sign2Digital." : ""), function ($message) use ($email): void {
                $message->to($email)->subject('Sign2Digital account deletion processed');
            });
        } catch (\Throwable) {
            return redirect()->route('admin.support.index')->with('success', 'Account deletion processed. Confirmation email could not be sent.');
        }
        return redirect()->route('admin.support.index')->with('success', 'Account deletion processed.');
    }
}
