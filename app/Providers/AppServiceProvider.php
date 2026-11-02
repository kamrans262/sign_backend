<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Email and challenge scoped limits remain effective across IP changes.
        // Opaque hashed identifiers prevent addresses from appearing in cache keys.
        RateLimiter::for('mobile-auth', function (Request $request): array {
            $identity = strtolower((string) $request->input(
                'email', $request->input('challenge_id', '')
            ));

            return [
                Limit::perMinute(20)->by('mobile-auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('mobile-auth-identity:'.hash('sha256', $identity ?: $request->ip())),
            ];
        });
    }
}
