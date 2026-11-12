<?php

namespace App\Support;

use App\Models\CompanyRole;
use Illuminate\Validation\ValidationException;

/**
 * Custom roles are always tenant-scoped. A linked role owns the live grants:
 * pivot JSON is only a compatibility snapshot for older clients.
 */
final class CompanyRolePermissions
{
    public const KEYS = [
        'clients.read', 'clients.write', 'jobs.read', 'jobs.write',
        'documents.read', 'documents.write', 'quotes.read', 'quotes.write',
        'invoices.read', 'invoices.write', 'reports.read', 'reports.write',
        'expenses.read', 'expenses.write', 'labour.read', 'labour.write',
        'equipment.read', 'equipment.write',
    ];

    public static function validateGrants(array $grants): array
    {
        foreach ($grants as $grant) {
            if (str_ends_with($grant, '.write') &&
                !in_array(substr($grant, 0, -6).'.read', $grants, true)) {
                throw ValidationException::withMessages([
                    'permissions' => 'Managing a module also requires its View permission.',
                ]);
            }
        }
        return array_values($grants);
    }

    public static function resolve(?int $companyRoleId, int $companyId, string $standardRole, ?string $snapshot): array
    {
        if ($standardRole === 'owner') return self::KEYS;
        if ($companyRoleId !== null) {
            $role = CompanyRole::query()->where('company_id', $companyId)
                ->whereKey($companyRoleId)->first();
            // Never fall back to stale pivot grants if the linked role is gone.
            return $role ? (array) $role->permissions : [];
        }
        $permissions = json_decode((string) $snapshot, true);
        return is_array($permissions) ? $permissions : [];
    }

    public static function findForCompany(int $companyId, int $roleId): CompanyRole
    {
        return CompanyRole::query()->where('company_id', $companyId)
            ->whereKey($roleId)->firstOrFail();
    }
}
