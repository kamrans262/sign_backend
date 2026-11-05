<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $query = User::query()->withCount('companies')->latest();
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('is_active', $status === 'active');
        }
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        return view('admin.users.index', [
            'users' => $query->paginate(15)->withQueryString(),
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = DB::transaction(function () use ($data, $request): User {
            $user = new User();
            $user->name = $data['name'];
            $user->email = strtolower($data['email']);
            $user->phone_number = $data['phone_number'] ?? null;
            $user->password = Hash::make($data['password']);
            $user->is_active = $request->boolean('is_active');
            $user->is_platform_admin = $request->boolean('is_platform_admin');
            $user->save();
            AuditLogger::record('admin.user.created', $request->user(), $user, metadata: ['platform_admin' => $user->is_platform_admin], request: $request);
            return $user;
        });
        return redirect()->route('admin.users.edit', $user)->with('success', 'Account created.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', compact('user'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        $active = $request->boolean('is_active');
        $admin = $request->boolean('is_platform_admin');

        if ($user->is($request->user()) && (!$active || !$admin)) {
            return back()->withErrors(['is_active' => 'You cannot deactivate or remove your own administrator access.']);
        }
        if ($user->is_platform_admin && (!$active || !$admin)
            && User::where('is_platform_admin', true)->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['is_active' => 'At least one active platform administrator is required.']);
        }
        DB::transaction(function () use ($user, $data, $active, $admin, $request): void {
            $old = ['is_active' => $user->is_active, 'is_platform_admin' => $user->is_platform_admin];
            $user->name = $data['name'];
            $user->email = strtolower($data['email']);
            $user->phone_number = $data['phone_number'] ?? null;
            if (!empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $user->is_active = $active;
            $user->is_platform_admin = $admin;
            $user->save();
            if (!$active) {
                $user->tokens()->delete();
            }
            AuditLogger::record('admin.user.updated', $request->user(), $user, metadata: [
                'previous' => $old, 'is_active' => $active, 'is_platform_admin' => $admin,
            ], request: $request);
        });
        return back()->with('success', 'Account updated.');
    }

    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        $data=$request->validate(['admin_password'=>['required','string']]);
        if (!Hash::check($data['admin_password'],$request->user()->password)) {
            return back()->withErrors(['admin_password'=>'Administrator password is incorrect.']);
        }
        $count=$user->tokens()->count();
        $user->tokens()->delete();
        AuditLogger::record('admin.user.sessions_revoked',$request->user(),$user,
            metadata:['count'=>$count],request:$request);
        return back()->with('success','User sessions revoked.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone_number' => ['nullable', 'string', 'max:40'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
            'is_platform_admin' => ['nullable', 'boolean'],
        ]);
    }
}
