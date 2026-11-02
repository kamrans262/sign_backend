<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => 'Sign2Digital']));

// Mobile authentication only. Administrator sign-in remains unchanged.
Route::prefix('v1/auth')->middleware('throttle:5,1')->group(function (): void {
    Route::post('/register', [\App\Http\Controllers\Api\MobileAuthController::class, 'register']);
    Route::post('/login', [\App\Http\Controllers\Api\MobileAuthController::class, 'login']);
    Route::post('/password/forgot', [\App\Http\Controllers\Api\MobileAuthController::class, 'forgotPassword']);
    Route::post('/otp/resend', [\App\Http\Controllers\Api\MobileAuthController::class, 'resend']);
    Route::post('/otp/verify', [\App\Http\Controllers\Api\MobileAuthController::class, 'verify']);
    Route::post('/password/reset', [\App\Http\Controllers\Api\MobileAuthController::class, 'resetPassword']);
});


Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
    Route::post('/v1/auth/logout', [\App\Http\Controllers\Api\MobileAuthController::class, 'logout']);
    // Existing route retained for older clients.
    Route::get('/user', fn (Request $request) => $request->user());

    Route::prefix('v1')->group(function (): void {
        Route::get('/me', function (Request $request) {
            $user = $request->user();
            $companies = $user->companies()
                ->wherePivot('status', 'active')
                ->where('companies.status', 'active')
                ->orderBy('companies.name')
                ->get(['companies.id', 'companies.name', 'companies.status']);
            return response()->json([
                'data' => [
                    'user' => $user->only(['id', 'name', 'email', 'phone_number']),
                    'companies' => $companies->map(fn ($company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'role' => $company->pivot->role,
                    ]),
                ],
            ]);
        });

        Route::middleware('company.access')->get('/company-context', function (Request $request) {
            $company = $request->attributes->get('company');
            return response()->json(['data' => [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status,
            ]]);
        });
    });
});
