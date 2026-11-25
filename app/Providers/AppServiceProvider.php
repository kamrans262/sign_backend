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
        // M19 reminder quota is independent from equipment and loan creation.
        RateLimiter::for('equipment-reminders',function(Request $request): array {
            $actor=(string)($request->user()?->getAuthIdentifier()??$request->ip());
            $company=(string)($request->header('X-Company-Id')??'admin');
            $loan=(string)($request->route('loan')??'unknown');
            $scope=hash('sha256',$actor.'|'.$company.'|'.$loan);
            return [
                Limit::perMinute(3)->by('m19-reminder-minute:'.$scope),
                Limit::perHour(20)->by('m19-reminder-hour:'.$scope),
                Limit::perMinute(15)->by('m19-reminder-ip:'.$request->ip()),
            ];
        });

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
