<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Company-Id');
        if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/', $id)) {
            return response()->json(['message' => 'A valid X-Company-Id header is required.'], 422);
        }
        if (!$request->user()?->is_active) {
            abort(403);
        }
        $company = Company::query()
            ->whereKey($id)
            ->where('status', 'active')
            ->whereHas('users', fn ($q) => $q->where('users.id', $request->user()->id)->where('company_user.status', 'active'))
            ->first();
        if (!$company) {
            // Same answer for an unknown company and a company belonging to another account.
            abort(403, 'Company access denied.');
        }
        $request->attributes->set('company', $company);
        return $next($request);
    }
}
