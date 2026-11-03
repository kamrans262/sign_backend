<?php

namespace App\Http\Middleware;

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
        if (in_array($member->pivot->role, ['owner', 'admin'], true)) return $next($request);
        $permissions = json_decode((string) $member->pivot->permissions, true);
        if (!is_array($permissions) || !in_array($permission, $permissions, true)) {
            abort(403, 'Company permission denied.');
        }
        return $next($request);
    }
}
