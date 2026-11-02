<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $company = $request->attributes->get('company');
        $user = $request->user();
        if (!$company || !$user || !$user->is_active || !$allowedRoles) {
            abort(403, 'Company permission denied.');
        }

        // Always read current membership from the database; never trust a
        // company ID or role supplied in a client token, body or query string.
        $member = $company->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->first();

        if (!$member || !in_array($member->pivot->role, $allowedRoles, true)) {
            abort(403, 'Company permission denied.');
        }

        return $next($request);
    }
}
