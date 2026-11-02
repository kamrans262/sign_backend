<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'app' => 'Sign2Digital']));

Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
    // Existing route retained for older clients.
    Route::get('/user', fn (Request $request) => $request->user()->only(['id', 'name', 'email', 'phone_number']));

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
