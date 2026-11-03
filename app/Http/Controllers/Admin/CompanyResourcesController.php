<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin-only management of the same companies used by mobile.
 * All actions inherit existing auth + platform.admin middleware.
 */
class CompanyResourcesController extends Controller
{
    public function invite(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['member', 'admin'])],
        ]);
        $email = strtolower(trim($data['email']));
        if ($company->users()->where('users.email', $email)->exists()) {
            return back()->withErrors(['email' => 'That account is already a team member.']);
        }
        if ($company->invitations()->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->exists()) {
            return back()->withErrors(['email' => 'An invitation is already pending.']);
        }
        if ($company->invitations()->where('created_at', '>', now()->subHour())->count() >= 20) {
            return back()->withErrors(['email' => 'Invitation limit reached for this company.']);
        }
        $token = Str::random(64);
        $invite = new CompanyInvitation();
        $invite->company_id = $company->id;
        $invite->invited_by_user_id = $request->user()->id;
        $invite->email = $email;
        $invite->role = $data['role'];
        $invite->permissions = [];
        $invite->token_hash = hash('sha256', $token);
        $invite->expires_at = now()->addDays(7);
        $invite->save();

        try {
            Mail::to($email)->send(new CompanyInvitationMail(
                $company->name, $token, $request->user()->name,
            ));
        } catch (\Throwable $e) {
            $invite->delete();
            Log::warning('M3 admin invitation delivery failed.', [
                'exception_type' => get_class($e),
            ]);
            return back()->withErrors(['email' => 'Unable to deliver invitation email.']);
        }

        AuditLogger::record('admin.company.invitation_sent', $request->user(), $invite, $company,
            metadata: ['role' => $data['role']], request: $request);
        return back()->with('success', 'Invitation email sent.');
    }

    public function revoke(Request $request, Company $company, CompanyInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->company_id === $company->id, 404);
        if ($invitation->accepted_at) {
            return back()->withErrors(['invitation' => 'Already accepted invitations cannot be revoked.']);
        }
        $invitation->revoked_at = now();
        $invitation->save();
        AuditLogger::record('admin.company.invitation_revoked', $request->user(), $invitation, $company,
            request: $request);
        return back()->with('success', 'Invitation revoked.');
    }

    private function columns(string $kind): array
    {
        return match ($kind) {
            'logo' => ['logo_path', 'logo_original_name'],
            'terms' => ['terms_path', 'terms_original_name'],
            default => abort(404),
        };
    }

    public function upload(Request $request, Company $company, string $kind): RedirectResponse
    {
        [$pathField, $nameField] = $this->columns($kind);
        $mimes = $kind === 'logo' ? 'image/png,image/jpeg,image/svg+xml' : 'application/pdf';
        $data = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimetypes:'.$mimes],
        ]);
        $file = $data['file'];
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($ext, $kind === 'logo'
            ? ['png', 'jpg', 'jpeg', 'svg'] : ['pdf'], true)) {
            return back()->withErrors(['file' => 'Unsupported file extension.']);
        }
        $old = $company->{$pathField};
        $stored = $file->storeAs('company-private/'.$company->id,
            $kind.'-'.Str::random(32).'.'.$ext, 'local');
        if (!$stored) return back()->withErrors(['file' => 'Cannot store the uploaded file.']);
        try {
            $company->update([$pathField => $stored,
                $nameField => basename($file->getClientOriginalName())]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }
        if ($old) Storage::disk('local')->delete($old);
        AuditLogger::record('admin.company.file_uploaded', $request->user(), $company, $company,
            metadata: ['kind' => $kind], request: $request);
        return back()->with('success', ucfirst($kind).' uploaded.');
    }

    public function download(Request $request, Company $company, string $kind): BinaryFileResponse
    {
        [$pathField, $nameField] = $this->columns($kind);
        $path = $company->{$pathField};
        if (!$path || !Storage::disk('local')->exists($path)) abort(404);
        $name = str_replace(["\r", "\n", '"'], '', (string) ($company->{$nameField} ?? 'company-file'));
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
            'pdf' => 'application/pdf', default => 'application/octet-stream',
        };
        return response()->download(Storage::disk('local')->path($path), $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function remove(Request $request, Company $company, string $kind): RedirectResponse
    {
        [$pathField, $nameField] = $this->columns($kind);
        $old = $company->{$pathField};
        $company->update([$pathField => null, $nameField => null]);
        if ($old) Storage::disk('local')->delete($old);
        AuditLogger::record('admin.company.file_removed', $request->user(), $company, $company,
            metadata: ['kind' => $kind], request: $request);
        return back()->with('success', ucfirst($kind).' removed.');
    }
}
