<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->is_active && $request->user()?->is_platform_admin) {
            return redirect()->route('admin.dashboard');
        }
        return response()->view('admin.login')->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function login(Request $request): RedirectResponse
    {
        if ($request->user()?->is_active && $request->user()?->is_platform_admin) {
            return redirect()->route('admin.dashboard');
        }
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials = [
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'is_active' => true,
            'is_platform_admin' => true,
        ];
        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Invalid administrator credentials.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        AuditLogger::record('platform_admin.login', Auth::user(), request: $request);
        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLogger::record('platform_admin.logout', $request->user(), request: $request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('site.home');
    }
}
