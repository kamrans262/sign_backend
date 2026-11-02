<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->is_active || !$request->user()?->is_platform_admin) {
            abort(403, 'Administrator access required.');
        }
        return $next($request);
    }
}
