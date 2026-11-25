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
        $eraser->erase($user, $request, $admin, $ticket);
        try {
            Mail::raw("Your Sign2Digital account deletion request ".$ref." has been processed. Your personal account has been erased from active use. Required business and audit records may be retained.", function ($message) use ($email): void {
                $message->to($email)->subject('Sign2Digital account deletion processed');
            });
        } catch (\Throwable) {
            return redirect()->route('admin.support.index')->with('success', 'Account deletion processed. Confirmation email could not be sent.');
        }
        return redirect()->route('admin.support.index')->with('success', 'Account deletion processed.');
    }
}
