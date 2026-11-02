<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailOtpChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MobileAuthController extends Controller
{
    public function __invoke(Request $request): View
    {
        $email = trim((string) $request->query('email', ''));
        $query = EmailOtpChallenge::query()->with('user:id,name,email')->latest();
        if ($email !== '') {
            $query->whereHas('user', fn ($q) => $q->where('email', 'like', '%'.$email.'%'));
        }

        return view('admin.mobile-auth', [
            'recentChallenges' => $query->paginate(15)->withQueryString(),
            'emailFilter' => $email,
            'activeUsers' => User::query()->where('is_active', true)->count(),
            'verifiedUsers' => User::query()->whereNotNull('email_verified_at')->count(),
            'pendingUsers' => User::query()->whereNull('email_verified_at')->count(),
        ]);
    }
}
