<?php

namespace App\Http\Middleware;

use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplyRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $before = app()->getLocale();
        app()->setLocale(AppLocale::forRequest($request));
        try {
            return $next($request);
        } finally {
            app()->setLocale($before);
        }
    }
}
