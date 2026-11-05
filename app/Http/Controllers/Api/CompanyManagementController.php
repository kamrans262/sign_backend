<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * M3 mobile company APIs. The caller's current DB membership is authoritative.
 * No company authorization comes from client-supplied roles, IDs or tokens.
 */
class CompanyManagementController extends Controller
{
    private const PERMISSIONS = [
        'clients.read', 'clients.write', 'jobs.read', 'jobs.write',
        'documents.read', 'documents.write', 'quotes.read', 'quotes.write',
        'invoices.read', 'invoices.write', 'reports.read', 'reports.write',
        'expenses.read', 'expenses.write', 'labour.read', 'labour.write',
        'equipment.read', 'equipment.write',
    ];

    private function company(Request $request, int $id, array $allowedRoles = []): Company
    {
        $user = $request->user();
        $company = Company::query()
            ->whereKey($id)
            ->where('status', 'active')
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id)
                ->where('company_user.status', 'active'))
            ->first();
        if (!$company) abort(403, 'Company access denied.');

        $membership = $company->users()->where('users.id', $user->id)->first();
        if (!$membership || !$membership->is_active || $membership->pivot->status !== 'active') {
            abort(403, 'Company access denied.');
        }
        if ($allowedRoles !== [] && !in_array($membership->pivot->role, $allowedRoles, true)) {
            abort(403, 'Company permission denied.');
        }

        $request->attributes->set('company', $company);
        $request->attributes->set('company_role', $membership->pivot->role);
        return $company;
    }

    private function profile(Company $company, ?User $user = null): array
    {
        $membership = $user ? $company->users()->where('users.id', $user->id)->first() : null;
        $permissions = $membership ? json_decode((string) $membership->pivot->permissions, true) : null;
        return [
            'id' => $company->id,
            'owner_user_id' => $company->owner_user_id,
            'name' => $company->name,
            'address' => $company->address,
            'country_iso_code' => $company->country_iso_code,
            'dialing_code' => $company->dialing_code,
            'phone_number' => $company->phone_number,
            'email' => $company->email,
            'swiss_company_id' => $company->swiss_company_id,
            'vat_number' => $company->vat_number,
            'iban' => $company->iban,
            'qr_iban' => $company->qr_iban,
            'document_numbering' => $company->document_numbering,
            'has_logo' => $company->logo_path !== null,
            'has_terms' => $company->terms_path !== null,
            'logo_file_name' => $company->logo_original_name,
            'terms_file_name' => $company->terms_original_name,
            'status' => $company->status,
            'role' => $membership?->pivot->role,
            'permissions' => is_array($permissions) ? $permissions : [],
        ];
    }

    private function validated(Request $request, bool $create = false): array
    {
        $rules = [
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:4000'],
            'country_iso_code' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'dialing_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'swiss_company_id' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:48'],
            'qr_iban' => ['nullable', 'string', 'max:48'],
            'document_numbering' => ['nullable', 'string', 'max:255'],
        ];
        $data = $request->validate($rules);
        if (isset($data['country_iso_code'])) {
            $data['country_iso_code'] = strtoupper($data['country_iso_code']);
        }
        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $companies = $user->companies()->wherePivot('status', 'active')
            ->where('companies.status', 'active')
            ->orderBy('companies.name')->get();

        return response()->json(['data' => [
            'active_company_id' => $companies->contains('id', $user->active_company_id)
                ? $user->active_company_id : null,
            'companies' => $companies->map(fn (Company $company) => $this->profile($company, $user))->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $company = DB::transaction(function () use ($request, $data): Company {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $company = Company::create(array_merge($data, [
                'owner_user_id' => $user->id,
                'status' => 'active',
                'country_iso_code' => $data['country_iso_code'] ?? 'CH',
            ]));
            $company->users()->attach($user->id, ['role' => 'owner', 'status' => 'active', 'permissions' => null]);
            $user->active_company_id = $company->id;
            $user->save();
            AuditLogger::record('mobile.company.created', $user, $company, $company, request: $request);
            return $company;
        });

        return response()->json(['data' => [
            'company' => $this->profile($company, $request->user()),
            'active_company_id' => $company->id,
        ]], 201);
    }

    public function show(Request $request, int $company): JsonResponse
    {
        return response()->json(['data' => ['company' => $this->profile(
            $this->company($request, $company), $request->user(),
        )]]);
    }

    public function update(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        $data = $this->validated($request);
        if ($data === []) {
            throw ValidationException::withMessages(['name' => 'No changes were supplied.']);
        }
        $target->update($data);
        AuditLogger::record('mobile.company.updated', $request->user(), $target, $target,
            metadata: ['fields' => array_keys($data)], request: $request);
        return response()->json(['data' => ['company' => $this->profile($target->fresh(), $request->user())]]);
    }

    public function switch(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company);
        $user = $request->user();
        $user->active_company_id = $target->id;
        $user->save();
        AuditLogger::record('mobile.company.switched', $user, $target, $target, request: $request);
        return response()->json(['data' => [
            'active_company_id' => $target->id,
            'company' => $this->profile($target, $user),
        ]]);
    }

    private function memberJson(User $user): array
    {
        $permissions = json_decode((string) $user->pivot->permissions, true);
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
            'status' => $user->pivot->status,
            'permissions' => is_array($permissions) ? $permissions : [],
        ];
    }

    public function members(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company);
        $members = $target->users()->orderBy('users.name')->get();
        return response()->json(['data' => [
            'members' => $members->map(fn (User $user) => $this->memberJson($user))->values(),
        ]]);
    }

    private function roleValidation(Request $request, bool $owner): array
    {
        return $request->validate([
            'role' => ['required', Rule::in($owner ? ['admin', 'member'] : ['member'])],
            'permissions' => ['sometimes', 'array', 'max:20'],
            'permissions.*' => ['string', 'distinct', Rule::in(self::PERMISSIONS)],
        ]);
    }

    public function updateMember(Request $request, int $company, int $user): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        $owner = $request->attributes->get('company_role') === 'owner';
        if ($user === (int) $target->owner_user_id) abort(403, 'Company owner cannot be changed here.');
        $data = $this->roleValidation($request, $owner);
        $member = $target->users()->where('users.id', $user)->first();
        if (!$member || $member->pivot->status !== 'active') abort(404);
        if (!$owner && $member->pivot->role !== 'member') abort(403);

        $target->users()->updateExistingPivot($user, [
            'role' => $data['role'],
            'permissions' => json_encode($data['permissions'] ?? [], JSON_THROW_ON_ERROR),
        ]);
        AuditLogger::record('mobile.company.member_updated', $request->user(), $member, $target,
            metadata: ['member_user_id' => $user, 'role' => $data['role']], request: $request);
        return response()->json(['data' => [
            'member' => $this->memberJson($target->users()->where('users.id', $user)->firstOrFail()),
        ]]);
    }

    public function removeMember(Request $request, int $company, int $user): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        $owner = $request->attributes->get('company_role') === 'owner';
        if ($user === (int) $target->owner_user_id) abort(403, 'Company owner cannot be removed.');
        $member = $target->users()->where('users.id', $user)->first();
        if (!$member) abort(404);
        if (!$owner && $member->pivot->role !== 'member') abort(403);

        DB::transaction(function () use ($target, $member, $request): void {
            $target->users()->detach($member->id);
            User::query()->where('id', $member->id)->where('active_company_id', $target->id)
                ->update(['active_company_id' => null]);
            AuditLogger::record('mobile.company.member_removed', $request->user(), $member, $target,
                metadata: ['member_user_id' => $member->id], request: $request);
        });
        return response()->json(['message' => 'Member removed.']);
    }

    private function invitationJson(CompanyInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'company_id' => $invitation->company_id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'permissions' => $invitation->permissions ?? [],
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'status' => $invitation->accepted_at ? 'accepted' : ($invitation->revoked_at
                ? 'revoked' : ($invitation->expires_at->isPast() ? 'expired' : 'pending')),
        ];
    }

    public function invitations(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        return response()->json(['data' => [
            'invitations' => $target->invitations()->latest()->limit(100)->get()
                ->map(fn (CompanyInvitation $i) => $this->invitationJson($i))->values(),
        ]]);
    }

    public function invite(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        $owner = $request->attributes->get('company_role') === 'owner';
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($owner ? ['admin', 'member'] : ['member'])],
            'permissions' => ['sometimes', 'array', 'max:20'],
            'permissions.*' => ['string', 'distinct', Rule::in(self::PERMISSIONS)],
        ]);
        $email = strtolower(trim($data['email']));
        $existing = $target->users()->where('users.email', $email)->exists();
        if ($existing) {
            throw ValidationException::withMessages(['email' => 'This user is already a member.']);
        }
        $pending = $target->invitations()->where('email', $email)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->exists();
        if ($pending) {
            throw ValidationException::withMessages(['email' => 'An invitation is already pending.']);
        }
        // Per-company rate limit independent of IP, including invitations to new emails.
        if ($target->invitations()->where('created_at', '>', now()->subHour())->count() >= 20) {
            throw ValidationException::withMessages(['email' => 'Company invitation limit reached. Try later.']);
        }

        $plain = Str::random(64);
        $invitation = new CompanyInvitation();
        $invitation->company_id = $target->id;
        $invitation->invited_by_user_id = $request->user()->id;
        $invitation->email = $email;
        $invitation->role = $data['role'];
        $invitation->permissions = $data['permissions'] ?? [];
        $invitation->token_hash = hash('sha256', $plain);
        $invitation->expires_at = now()->addDays(7);
        $invitation->save();

        try {
            Mail::to($email)->send(new CompanyInvitationMail(
                $target->name, $plain, $request->user()->name,
            ));
        } catch (\Throwable $error) {
            $invitation->delete();
            Log::warning('M3 invitation email delivery failed.', [
                'exception_type' => get_class($error),
            ]);
            throw ValidationException::withMessages(['email' => 'Unable to send invitation email.']);
        }
        AuditLogger::record('mobile.company.invitation_sent', $request->user(), $invitation, $target,
            metadata: ['role' => $data['role']], request: $request);
        return response()->json(['data' => [
            'invitation' => $this->invitationJson($invitation),
            'message' => 'Invitation sent to the provided email.',
        ]], 201);
    }

    public function revokeInvitation(Request $request, int $company, int $invitation): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        $row = $target->invitations()->whereKey($invitation)->firstOrFail();
        if ($row->accepted_at) abort(422, 'An accepted invitation cannot be revoked.');
        $row->revoked_at = now();
        $row->save();
        AuditLogger::record('mobile.company.invitation_revoked', $request->user(), $row, $target,
            request: $request);
        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function myInvitations(Request $request): JsonResponse
    {
        // Invitation discovery must not expose company membership to an
        // authenticated account whose email ownership was never verified.
        if ($request->user()->email_verified_at === null) {
            abort(403, 'Verify your email before viewing company invitations.');
        }
        $email = strtolower($request->user()->email);
        $invites = CompanyInvitation::query()->with('company:id,name,status')
            ->where('email', $email)->whereNull('accepted_at')
            ->whereNull('revoked_at')->where('expires_at', '>', now())
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->latest()->get();
        return response()->json(['data' => ['invitations' => $invites->map(fn ($row) => [
            'id' => $row->id,
            'company_name' => $row->company->name,
            'role' => $row->role,
            'expires_at' => $row->expires_at->toIso8601String(),
            // Never expose token hashes or a usable invitation code.
        ])->values()]]);
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:64']]);
        $hash = hash('sha256', $data['code']);

        $result = DB::transaction(function () use ($request, $hash): Company {
            $row = CompanyInvitation::query()->where('token_hash', $hash)
                ->lockForUpdate()->first();
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if ($user->email_verified_at === null) {
                throw ValidationException::withMessages([
                    'code' => 'Verify your email before accepting a company invitation.',
                ]);
            }
            if (!$row || $row->revoked_at || $row->accepted_at || $row->expires_at->isPast() ||
                !hash_equals(strtolower($row->email), strtolower($user->email))) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired company invitation.']);
            }

            $target = Company::query()->whereKey($row->company_id)->where('status', 'active')->first();
            if (!$target) {
                throw ValidationException::withMessages(['code' => 'Invalid or expired company invitation.']);
            }
            if ($target->users()->where('users.id', $user->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'You are already a company member.']);
            }

            $target->users()->attach($user->id, [
                'role' => $row->role,
                'status' => 'active',
                'permissions' => json_encode($row->permissions ?? [], JSON_THROW_ON_ERROR),
            ]);
            $row->accepted_at = now();
            $row->save();
            $user->active_company_id = $target->id;
            $user->save();
            AuditLogger::record('mobile.company.invitation_accepted', $user, $target, $target,
                request: $request);
            return $target;
        });
        return response()->json(['data' => [
            'company' => $this->profile($result, $request->user()),
            'active_company_id' => $result->id,
        ]]);
    }

    private function fileAttributes(string $kind): array
    {
        return match ($kind) {
            'logo' => ['logo_path', 'logo_original_name'],
            'terms' => ['terms_path', 'terms_original_name'],
            default => abort(404),
        };
    }

    public function uploadFile(Request $request, int $company, string $kind): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        [$pathField, $nameField] = $this->fileAttributes($kind);
        $allowed = $kind === 'logo'
            ? ['image/png', 'image/jpeg', 'image/svg+xml']
            : ['application/pdf'];
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimetypes:'.implode(',', $allowed)],
        ]);
        $file = $validated['file'];
        $original = $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, $kind === 'logo'
            ? ['png', 'jpg', 'jpeg', 'svg'] : ['pdf'], true)) {
            throw ValidationException::withMessages(['file' => 'Unsupported file extension.']);
        }
        $old = $target->{$pathField};
        $stored = $file->storeAs('company-private/'.$target->id,
            $kind.'-'.Str::random(32).'.'.$extension, 'local');
        if (!$stored) {
            throw ValidationException::withMessages(['file' => 'Unable to store file.']);
        }
        try {
            $target->update([$pathField => $stored, $nameField => basename($original)]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }
        if ($old && $old !== $stored) Storage::disk('local')->delete($old);
        AuditLogger::record('mobile.company.file_uploaded', $request->user(), $target, $target,
            metadata: ['kind' => $kind], request: $request);
        return response()->json(['data' => ['company' => $this->profile($target->fresh(), $request->user())]]);
    }

    public function downloadFile(Request $request, int $company, string $kind): BinaryFileResponse
    {
        $target = $this->company($request, $company);
        [$pathField, $nameField] = $this->fileAttributes($kind);
        $path = $target->{$pathField};
        if (!$path || !Storage::disk('local')->exists($path)) abort(404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
            'pdf' => 'application/pdf', default => 'application/octet-stream',
        };
        // Never serve uploaded SVG inline: it may contain active XML scripts.
        $downloadName = str_replace(["\r", "\n", '"'], '', (string) ($target->{$nameField} ?? 'company-file'));
        return response()->download(Storage::disk('local')->path($path), $downloadName, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function deleteFile(Request $request, int $company, string $kind): JsonResponse
    {
        $target = $this->company($request, $company, ['owner', 'admin']);
        [$pathField, $nameField] = $this->fileAttributes($kind);
        $old = $target->{$pathField};
        $target->update([$pathField => null, $nameField => null]);
        if ($old) Storage::disk('local')->delete($old);
        AuditLogger::record('mobile.company.file_deleted', $request->user(), $target, $target,
            metadata: ['kind' => $kind], request: $request);
        return response()->json(['data' => ['company' => $this->profile($target->fresh(), $request->user())]]);
    }
}
