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
            'company' => $company->load(['users' => fn ($q) => $q->orderBy('users.name'), 'owner']),
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

    public function removeMember(Request $request, Company $company, User $user): RedirectResponse
    {
        if ($user->id === $company->owner_user_id) {
            return back()->withErrors(['membership' => 'Transfer ownership before removing the owner.']);
        }
        DB::transaction(function () use ($company, $user, $request): void {
            $company->users()->detach($user->id);
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
