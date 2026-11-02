<?php

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('sign:admin {email}', function (string $email): void {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email address.');
        return;
    }

    $user = User::query()->where('email', $email)->first();
    if (!$user) {
        $name = trim((string) $this->ask('Administrator full name'));
        if ($name === '' || mb_strlen($name) > 255) {
            $this->error('A valid name is required.');
            return;
        }
    }
    $password = (string) $this->secret('New administrator password (minimum 12 characters)');
    $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:12']]);
    if ($validator->fails()) {
        $this->error('Password must have at least 12 characters.');
        return;
    }
    DB::transaction(function () use ($user, $name, $email, $password): void {
        $account = $user ?? new User();
        if (!$user) {
            $account->name = $name;
            $account->email = $email;
        }
        $account->password = Hash::make($password);
        $account->is_active = true;
        $account->is_platform_admin = true;
        $account->save();
        AuditLogger::record('platform_admin.provisioned', subject: $account);
    });
    $this->info('Administrator access provisioned. Use /admin/login over HTTPS.');
})->purpose('Interactively create or enable the first platform administrator');
