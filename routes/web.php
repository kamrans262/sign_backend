<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.login'));

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthController::class, 'show'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');
    });
    Route::middleware(['auth', 'platform.admin'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
        Route::post('/companies/{company}/members', [CompanyController::class, 'addMember'])->name('companies.members.store');
        Route::delete('/companies/{company}/members/{user}', [CompanyController::class, 'removeMember'])->name('companies.members.destroy');
        Route::get('/audit', AuditController::class)->name('audit');
    });
});
