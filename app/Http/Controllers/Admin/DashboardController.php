<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'metrics' => [
                'users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'companies' => Company::count(),
                'active_companies' => Company::where('status', 'active')->count(),
            ],
            'recentCompanies' => Company::query()->with('owner:id,name,email')->latest()->limit(6)->get(),
            'recentLogs' => AuditLog::query()->with('actor:id,name,email')->latest('id')->limit(8)->get(),
        ]);
    }
}
