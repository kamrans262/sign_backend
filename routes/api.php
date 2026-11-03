<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => 'Sign2Digital']));

// Mobile authentication only. Administrator sign-in remains unchanged.
Route::prefix('v1/auth')->middleware('throttle:mobile-auth')->group(function (): void {
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
                    'user' => $user->only(['id', 'name', 'email', 'phone_number', 'country_iso_code', 'dialing_code', 'email_verified_at']),
                    'companies' => $companies->map(fn ($company) => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'role' => $company->pivot->role,
                    ]),
                ],
            ]);
        });

        Route::patch('/me', function (Request $request) {
            $user = $request->user();
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', \Illuminate\Validation\Rule::in([$user->email])],
                'phone_number' => ['nullable', 'string', 'max:40'],
                'country_iso_code' => ['nullable', 'string', 'size:2'],
                'dialing_code' => ['nullable', 'string', 'max:8'],
            ]);
            // Email changes require re-verification and are not silently accepted.
            $user->fill(\Illuminate\Support\Arr::only($data, [
                'name', 'phone_number', 'country_iso_code', 'dialing_code',
            ]));
            $user->save();
            \App\Support\AuditLogger::record('mobile.account.profile_updated', $user, $user, request: $request);
            return response()->json(['data' => ['user' => $user->only([
                'id', 'name', 'email', 'phone_number', 'country_iso_code', 'dialing_code',
            ])]]);
        });



        // M3: all company actions require a real Sanctum-authenticated account.
        // Access to each company is additionally checked on the server using
        // an active membership, not only by matching the URL or a header.
        Route::get('/companies', [\App\Http\Controllers\Api\CompanyManagementController::class, 'index']);
        Route::post('/companies', [\App\Http\Controllers\Api\CompanyManagementController::class, 'store']);
        Route::get('/companies/invitations/mine', [\App\Http\Controllers\Api\CompanyManagementController::class, 'myInvitations']);
        Route::post('/companies/invitations/accept', [\App\Http\Controllers\Api\CompanyManagementController::class, 'acceptInvitation'])
            ->middleware('throttle:5,1');
        Route::get('/companies/{company}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'show'])
            ->whereNumber('company');
        Route::patch('/companies/{company}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'update'])
            ->whereNumber('company');
        Route::post('/companies/{company}/switch', [\App\Http\Controllers\Api\CompanyManagementController::class, 'switch'])
            ->whereNumber('company');
        Route::get('/companies/{company}/members', [\App\Http\Controllers\Api\CompanyManagementController::class, 'members'])
            ->whereNumber('company');
        Route::patch('/companies/{company}/members/{user}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'updateMember'])
            ->whereNumber('company')->whereNumber('user');
        Route::delete('/companies/{company}/members/{user}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'removeMember'])
            ->whereNumber('company')->whereNumber('user');
        Route::get('/companies/{company}/invitations', [\App\Http\Controllers\Api\CompanyManagementController::class, 'invitations'])
            ->whereNumber('company');
        Route::post('/companies/{company}/invitations', [\App\Http\Controllers\Api\CompanyManagementController::class, 'invite'])
            ->whereNumber('company')->middleware('throttle:10,1');
        Route::delete('/companies/{company}/invitations/{invitation}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'revokeInvitation'])
            ->whereNumber('company')->whereNumber('invitation');
        Route::post('/companies/{company}/files/{kind}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'uploadFile'])
            ->whereNumber('company')->whereIn('kind', ['logo', 'terms']);
        Route::get('/companies/{company}/files/{kind}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'downloadFile'])
            ->whereNumber('company')->whereIn('kind', ['logo', 'terms']);
        Route::delete('/companies/{company}/files/{kind}', [\App\Http\Controllers\Api\CompanyManagementController::class, 'deleteFile'])
            ->whereNumber('company')->whereIn('kind', ['logo', 'terms']);

        // M4 Client Management: tenant header and DB permissions are enforced
        // independently for reads and writes, including bulk phone import.
        Route::middleware(['company.access', 'company.permission:clients.read'])->group(function (): void {
            Route::get('/clients', [\App\Http\Controllers\Api\ClientController::class, 'index']);
            Route::get('/clients/{client}', [\App\Http\Controllers\Api\ClientController::class, 'show'])
                ->whereNumber('client');
        });
        Route::middleware(['company.access', 'company.permission:clients.write'])->group(function (): void {
            Route::post('/clients', [\App\Http\Controllers\Api\ClientController::class, 'store']);
            Route::post('/clients/import', [\App\Http\Controllers\Api\ClientController::class, 'import'])
                ->middleware('throttle:10,1');
            Route::patch('/clients/{client}', [\App\Http\Controllers\Api\ClientController::class, 'update'])
                ->whereNumber('client');
            Route::delete('/clients/{client}', [\App\Http\Controllers\Api\ClientController::class, 'destroy'])
                ->whereNumber('client');
        });

        // M5: real company-isolated customer jobs (not Laravel queue jobs).
        Route::middleware(['company.access', 'company.permission:jobs.read'])->group(function (): void {
            Route::get('/jobs', [\App\Http\Controllers\Api\ServiceJobController::class, 'index']);
            Route::get('/jobs/{job}', [\App\Http\Controllers\Api\ServiceJobController::class, 'show'])->whereNumber('job');
        });
        Route::middleware(['company.access', 'company.permission:jobs.write'])->group(function (): void {
            Route::post('/jobs', [\App\Http\Controllers\Api\ServiceJobController::class, 'store']);
            Route::patch('/jobs/{job}', [\App\Http\Controllers\Api\ServiceJobController::class, 'update'])->whereNumber('job');
            Route::delete('/jobs/{job}', [\App\Http\Controllers\Api\ServiceJobController::class, 'destroy'])->whereNumber('job');
            Route::post('/jobs/{job}/restore', [\App\Http\Controllers\Api\ServiceJobController::class, 'restore'])->whereNumber('job');
        });

        // M6: tenant-scoped quotes; AI is always proxied by Laravel, never by client keys.
        Route::middleware(['company.access','company.permission:quotes.read'])->group(function (): void {
            Route::get('/quotes', [\App\Http\Controllers\Api\ServiceQuoteController::class,'index']);
            Route::get('/quotes/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class,'show'])->whereNumber('quote');
            Route::get('/quotes/{quote}/revisions', [\App\Http\Controllers\Api\ServiceQuoteController::class,'revisions'])->whereNumber('quote');
        });
        Route::middleware(['company.access','company.permission:quotes.write'])->group(function (): void {
            Route::post('/quotes', [\App\Http\Controllers\Api\ServiceQuoteController::class,'store']);
            Route::patch('/quotes/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class,'update'])->whereNumber('quote');
            Route::post('/quotes/{quote}/approve', [\App\Http\Controllers\Api\ServiceQuoteController::class,'approve'])->whereNumber('quote');
            Route::post('/quotes/{quote}/share', [\App\Http\Controllers\Api\ServiceQuoteController::class,'share'])->whereNumber('quote')
                ->middleware('throttle:10,1');
            Route::delete('/quotes/{quote}', [\App\Http\Controllers\Api\ServiceQuoteController::class,'archive'])->whereNumber('quote');
            Route::post('/quotes/prepare/{method}', [\App\Http\Controllers\Api\QuoteAiController::class,'prepare'])
                ->whereIn('method',['text','audio','image'])->middleware('throttle:10,1');
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
