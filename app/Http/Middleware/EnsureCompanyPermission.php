<?php

namespace App\Http\Middleware;

use App\Support\CompanyRolePermissions;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-aware tenant guard for upcoming client/job/document APIs.
 * Must run after auth:sanctum, active.user and company.access.
 */
class EnsureCompanyPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $company = $request->attributes->get('company');
        $actor = $request->user();
        if (!$actor || !$actor->is_active || !$company || $company->status !== 'active') {
            abort(403, 'Company permission denied.');
        }
        $member = $company->users()->where('users.id', $actor->id)
            ->wherePivot('status', 'active')->first();
        if (!$member) abort(403, 'Company permission denied.');
        // Owners retain full control; admins must follow assigned module grants.
        if ($member->pivot->role === 'owner') return $next($request);
        $permissions = CompanyRolePermissions::resolve(
            $member->pivot->company_role_id ? (int) $member->pivot->company_role_id : null,
            (int) $company->id, (string) $member->pivot->role,
            $member->pivot->permissions
        );
        if (!in_array($permission, $permissions, true)) {
            abort(403, 'Company permission denied.');
        }
        return $next($request);
    }
}
