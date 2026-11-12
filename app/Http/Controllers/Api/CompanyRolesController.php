<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyRole;
use App\Support\AuditLogger;
use App\Support\CompanyRolePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompanyRolesController extends Controller
{
    private function company(Request $request, int $companyId, bool $ownerOnly = false): Company
    {
        $company = Company::query()->whereKey($companyId)->where('status', 'active')
            ->firstOrFail();
        $member = $company->users()->where('users.id', $request->user()->id)
            ->wherePivot('status', 'active')->first();
        abort_unless($member && $member->is_active, 403, 'Company access denied.');
        if ($ownerOnly) {
            abort_unless($member->pivot->role === 'owner' &&
                (int) $company->owner_user_id === (int) $request->user()->id,
                403, 'Only the company owner can manage custom roles.');
        }
        return $company;
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60',
                'regex:/^[\pL\pN][\pL\pN ._-]*$/u'],
            'permissions' => ['required', 'array', 'max:18'],
            'permissions.*' => ['required', 'string', 'distinct',
                Rule::in(CompanyRolePermissions::KEYS)],
        ]);
        $data['name'] = trim(preg_replace('/\s+/u', ' ', $data['name']));
        if (in_array(mb_strtolower($data['name']), ['owner', 'admin', 'member'], true)) {
            throw ValidationException::withMessages([
                'name' => 'This role name is reserved.',
            ]);
        }
        $data['permissions'] = CompanyRolePermissions::validateGrants($data['permissions']);
        return $data;
    }

    private function uniqueName(Company $company, string $name, ?int $except = null): void
    {
        $query = $company->customRoles()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        if ($except !== null) $query->where('id', '!=', $except);
        if ($query->exists()) throw ValidationException::withMessages([
            'name' => 'A role with this name already exists.',
        ]);
    }

    private function result(CompanyRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions ?? [],
            'assigned_members' => DB::table('company_user')
                ->where('company_id', $role->company_id)
                ->where('company_role_id', $role->id)->count(),
        ];
    }

    public function index(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company);
        return response()->json(['data' => ['roles' => $target->customRoles()
            ->orderBy('name')->get()->map(fn (CompanyRole $role) =>
                $this->result($role))->values()]]);
    }

    public function store(Request $request, int $company): JsonResponse
    {
        $target = $this->company($request, $company, true);
        $data = $this->data($request);
        $this->uniqueName($target, $data['name']);
        if ($target->customRoles()->count() >= 30) {
            throw ValidationException::withMessages(['name' => 'Maximum 30 custom roles per company.']);
        }
        $role = $target->customRoles()->create($data);
        AuditLogger::record('mobile.company.role_created', $request->user(), $role, $target,
            request: $request);
        return response()->json(['data' => ['role' => $this->result($role)]], 201);
    }

    public function update(Request $request, int $company, int $role): JsonResponse
    {
        $target = $this->company($request, $company, true);
        $data = $this->data($request);
        return DB::transaction(function () use ($request, $target, $role, $data): JsonResponse {
            $existing = $target->customRoles()->whereKey($role)
                ->lockForUpdate()->firstOrFail();
            $this->uniqueName($target, $data['name'], $existing->id);
            $existing->update($data);
            $snapshot = json_encode($data['permissions'], JSON_THROW_ON_ERROR);
            DB::table('company_user')->where('company_id', $target->id)
                ->where('company_role_id', $existing->id)
                ->update(['permissions' => $snapshot, 'updated_at' => now()]);
            DB::table('company_invitations')->where('company_id', $target->id)
                ->where('company_role_id', $existing->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['permissions' => $snapshot, 'updated_at' => now()]);
            AuditLogger::record('mobile.company.role_updated', $request->user(), $existing,
                $target, request: $request);
            return response()->json(['data' => ['role' => $this->result($existing->fresh())]]);
        });
    }

    public function destroy(Request $request, int $company, int $role): JsonResponse
    {
        $target = $this->company($request, $company, true);
        DB::transaction(function () use ($request, $target, $role): void {
            $existing = $target->customRoles()->whereKey($role)
                ->lockForUpdate()->firstOrFail();
            $assigned = DB::table('company_user')->where('company_id', $target->id)
                ->where('company_role_id', $existing->id)->exists();
            $pending = DB::table('company_invitations')->where('company_id', $target->id)
                ->where('company_role_id', $existing->id)
                ->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->exists();
            if ($assigned || $pending) {
                throw ValidationException::withMessages([
                    'role' => 'Reassign team members and revoke pending invitations before deleting this role.',
                ]);
            }
            $existing->delete();
            AuditLogger::record('mobile.company.role_deleted', $request->user(),
                $existing, $target, request: $request);
        });
        return response()->json(['message' => 'Role deleted.']);
    }
}
