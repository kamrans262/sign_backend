<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanyController extends Controller
{
    // Same finite grants as the M3 mobile tenant-permission contract.
    private const MEMBER_PERMISSIONS = [
        'clients.read', 'clients.write', 'jobs.read', 'jobs.write',
        'documents.read', 'documents.write', 'quotes.read', 'quotes.write',
        'invoices.read', 'invoices.write', 'reports.read', 'reports.write',
        'expenses.read', 'expenses.write', 'labour.read', 'labour.write',
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $query = Company::query()->with('owner:id,name,email')->withCount('users')->latest();
        if (in_array($status, ['active', 'suspended'], true)) {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        return view('admin.companies.index', [
            'companies' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('admin.companies.form', ['availableUsers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $company = DB::transaction(function () use ($request, $data): Company {
            $company = Company::create($data);
            $company->users()->attach($company->owner_user_id, ['role' => 'owner', 'status' => 'active']);
            AuditLogger::record('admin.company.created', $request->user(), $company, $company, request: $request);
            return $company;
        });
        return redirect()->route('admin.companies.edit', $company)->with('success', 'Company created.');
    }

    public function edit(Company $company): View
    {
        return view('admin.companies.form', [
            'company' => $company->load(['users' => fn ($q) => $q->orderBy('users.name'), 'owner', 'invitations']),
            'availableUsers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $company, $data): void {
            $oldOwnerId = $company->owner_user_id;
            $company->update($data);
            if ($company->owner_user_id !== $oldOwnerId) {
                $company->users()->syncWithoutDetaching([
                    $oldOwnerId => ['role' => 'admin', 'status' => 'active'],
                    $company->owner_user_id => ['role' => 'owner', 'status' => 'active'],
                ]);
            }
            AuditLogger::record('admin.company.updated', $request->user(), $company, $company, metadata: [
                'owner_changed' => $oldOwnerId !== $company->owner_user_id, 'status' => $company->status,
            ], request: $request);
        });
        return back()->with('success', 'Company updated.');
    }

    public function addMember(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)],
            'role' => ['required', Rule::in(['admin', 'member'])],
        ]);
        if ((int) $data['user_id'] === (int) $company->owner_user_id) {
            return back()->withErrors(['user_id' => 'The company owner must retain the owner role.']);
        }
        DB::transaction(function () use ($company, $data, $request): void {
            $company->users()->syncWithoutDetaching([(int) $data['user_id'] => [
                'role' => $data['role'], 'status' => 'active',
            ]]);
            AuditLogger::record('admin.company.member_saved', $request->user(), $company, $company, metadata: [
                'member_user_id' => (int) $data['user_id'], 'role' => $data['role'],
            ], request: $request);
        });
        return back()->with('success', 'Membership saved.');
    }

    public function editMember(Company $company, User $user): View
    {
        $membership = $company->users()->where('users.id', $user->id)->firstOrFail();
        abort_if($user->id === $company->owner_user_id, 403,
            'Manage the owner through company ownership settings.');

        $permissions = json_decode((string) $membership->pivot->permissions, true);
        return view('admin.companies.member', [
            'company' => $company,
            'member' => $membership,
            'allowedPermissions' => self::MEMBER_PERMISSIONS,
            'selectedPermissions' => is_array($permissions) ? $permissions : [],
        ]);
    }

    public function updateMember(Request $request, Company $company, User $user): RedirectResponse
    {
        // Platform administrators can manage company members, but never
        // silently overwrite the owner's permanent ownership role.
        abort_if($user->id === $company->owner_user_id, 403,
            'Company owner cannot be changed from team management.');
        $membership = $company->users()->where('users.id', $user->id)->firstOrFail();
        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'permissions' => ['sometimes', 'array', 'max:20'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(self::MEMBER_PERMISSIONS)],
        ]);
        DB::transaction(function () use ($request, $company, $user, $membership, $data): void {
            $company->users()->updateExistingPivot($user->id, [
                'role' => $data['role'],
                'status' => $data['status'],
                'permissions' => json_encode($data['permissions'] ?? [], JSON_THROW_ON_ERROR),
            ]);
            if ($data['status'] !== 'active') {
                User::query()->whereKey($user->id)
                    ->where('active_company_id', $company->id)
                    ->update(['active_company_id' => null]);
            }
            AuditLogger::record('admin.company.member_updated', $request->user(), $user, $company,
                metadata: [
                    'member_user_id' => $user->id,
                    'previous_role' => $membership->pivot->role,
                    'role' => $data['role'],
                    'status' => $data['status'],
                    'permissions' => $data['permissions'] ?? [],
                ], request: $request);
        });
        return redirect()->route('admin.companies.edit', $company)->with('success', 'Member updated.');
    }

    public function removeMember(Request $request, Company $company, User $user): RedirectResponse
    {
        if ($user->id === $company->owner_user_id) {
            return back()->withErrors(['membership' => 'Transfer ownership before removing the owner.']);
        }
        DB::transaction(function () use ($company, $user, $request): void {
            $company->users()->detach($user->id);
            // Also revoke the server's selected tenant; an already-open
            // mobile session may not continue using this company.
            User::query()->whereKey($user->id)
                ->where('active_company_id', $company->id)
                ->update(['active_company_id' => null]);
            AuditLogger::record('admin.company.member_removed', $request->user(), $company, $company, metadata: [
                'member_user_id' => $user->id,
            ], request: $request);
        });
        return back()->with('success', 'Membership removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'owner_user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:4000'],
            'country_iso_code' => ['required', 'string', 'size:2'],
            'dialing_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'swiss_company_id' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:48'],
            'qr_iban' => ['nullable', 'string', 'max:48'],
            'document_numbering' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);
    }
}
