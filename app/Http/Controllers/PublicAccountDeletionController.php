<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublicAccountDeletionController extends Controller
{
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), (string) config('app.key'));
    }

    private function codeHash(string $id, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$code, (string) config('app.key'));
    }

    public function show(Request $request)
    {
        $id = $request->session()->get('public_deletion_challenge');
        $awaiting = is_string($id) && DB::table('public_deletion_challenges')
            ->where('id', $id)->where('expires_at', '>', now())->exists();
        return response()->view('site.deletion', ['awaiting' => $awaiting])
            ->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'],
            'confirm' => ['accepted']]);
        $email = strtolower(trim($data['email']));
        $hash = self::emailHash($email);
        $key = 'public-deletion-email:'.$hash;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Please try again later.']);
        }
        RateLimiter::hit($key, 3600);
        $user = User::where('email', $email)->where('is_active', true)
            ->whereNotNull('email_verified_at')->first();
        $id = (string) Str::uuid();
        $code = (string) random_int(100000, 999999);
        DB::table('public_deletion_challenges')->where('expires_at', '<=', now())->delete();
        if ($previous = $request->session()->get('public_deletion_challenge')) {
            DB::table('public_deletion_challenges')->where('id', $previous)->delete();
        }
        DB::table('public_deletion_challenges')->insert([
            'id' => $id, 'user_id' => $user?->id, 'email_hash' => $hash,
            'code_hash' => $this->codeHash($id, $code), 'expires_at' => now()->addMinutes(10),
        ]);
        $request->session()->put('public_deletion_challenge', $id);
        if ($user) {
            try {
                Mail::raw("Your Sign2Digital account deletion request verification code is ".$code.". It expires in 10 minutes. If you did not request deletion, ignore this email.", function ($message) use ($email): void {
                    $message->to($email)->subject('Verify your Sign2Digital deletion request');
                });
            } catch (\Throwable) {
                // Do not reveal whether an email belongs to an account.
                DB::table('public_deletion_challenges')->where('id', $id)->update(['user_id' => null]);
            }
        }
        return redirect()->route('site.deletion')->with('notice',
            'If your email belongs to an active Sign2Digital account, a verification code has been requested. Check your inbox and spam folder.');
    }

    public function restart(Request $request)
    {
        $id = $request->session()->pull('public_deletion_challenge');
        if (is_string($id)) DB::table('public_deletion_challenges')->where('id', $id)->delete();
        return redirect()->route('site.deletion');
    }

    public function verify(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{6}$/D']]);
        $id = $request->session()->get('public_deletion_challenge');
        if (!is_string($id)) return redirect()->route('site.deletion');
        $reference = DB::transaction(function () use ($id, $data, $request): ?string {
            $row = DB::table('public_deletion_challenges')->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->attempts >= 5 || now()->greaterThanOrEqualTo($row->expires_at)) return null;
            DB::table('public_deletion_challenges')->where('id', $id)->increment('attempts');
            if (!hash_equals($row->code_hash, $this->codeHash($id, $data['code'])) || !$row->user_id) return null;
            $user = User::whereKey($row->user_id)->lockForUpdate()->first();
            if (!$user || !$user->is_active || !$user->email_verified_at ||
                !hash_equals($row->email_hash, self::emailHash($user->email))) return null;
            $existing = DB::table('m22_support_tickets')->where('user_id', $user->id)
                ->where('category', 'account_deletion')->where('deletion_verified_email_hash', $row->email_hash)
                ->whereIn('status', ['open', 'in_progress', 'waiting_for_customer'])->first();
            $ref = $existing?->reference ?? 'S2D-'.strtoupper(Str::random(12));
            if (!$existing) {
                $ticket = DB::table('m22_support_tickets')->insertGetId([
                    'reference' => $ref, 'user_id' => $user->id, 'company_id' => null,
                    'category' => 'account_deletion', 'subject' => 'Verified account deletion request',
                    'description' => 'The account holder requested deletion through the public website and confirmed control of the registered email address. Verify any additional account-security and ownership requirements before processing.',
                    'status' => 'open', 'priority' => 'high', 'deletion_verified_email_hash' => $row->email_hash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                AuditLogger::record('public.account.deletion_requested', $user, $user,
                    metadata: ['ticket_id' => $ticket, 'reference' => $ref], request: $request);
            }
            DB::table('public_deletion_challenges')->where('id', $id)->delete();
            return $ref;
        }, 3);
        if (!$reference) throw ValidationException::withMessages(['code' => 'Invalid or expired code. Try again or start a new request.']);
        $request->session()->forget('public_deletion_challenge');
        return redirect()->route('site.deletion')->with('deletion_reference', $reference);
    }
}
