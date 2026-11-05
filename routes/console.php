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
    $name = null;
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


// Provision the requested demo account without storing credentials in Git.
// Run: php artisan sign:demo-account <email>
// The password is requested interactively and is never echoed or committed.
Artisan::command('sign:demo-account {email}', function (string $email): void {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');
        return;
    }
    if (User::query()->where('email', $email)->exists()) {
        $this->error('Account already exists. Nothing was changed.');
        return;
    }

    $password = (string) $this->secret('Demo password (8+ chars, upper/lowercase, number, symbol)');
    $valid = Validator::make(['password' => $password], [
        'password' => ['required', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
    ]);
    if ($valid->fails()) {
        $this->error('Password must include mixed case, a number, and a symbol.');
        return;
    }

    DB::transaction(function () use ($email, $password): void {
        $user = User::create([
            'name' => 'Sign2Digital Demo',
            'email' => $email,
            'password' => $password,
        ]);
        // This is a securely provisioned demo account. Every MOBILE login still
        // requires fresh email OTP; no bypass or static OTP exists.
        $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
        AuditLogger::record('mobile.demo_account_provisioned', subject: $user);
    });
    $this->info('Demo account created; mobile sign-in requires email OTP.');
})->purpose('Securely provision a mobile test account with interactive password');


Artisan::command('sign:otp-preflight', function (): int {
    // Safe to run on production: never prints SMTP credentials, APP_KEY,
    // stored OTP values or any individual account information.
    $ok = true;
    $driver = DB::connection()->getDriverName();
    $mailer = (string) config('mail.default');
    $production = app()->isProduction();
    $tablePresent = \Illuminate\Support\Facades\Schema::hasTable('email_otp_challenges');
    $appKeyPresent = (string) config('app.key') !== '';
    $fromConfigured = filter_var((string) config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;

    $this->line('Database driver: '.$driver);
    $this->line('Mail transport: '.$mailer);
    $this->line('Application key: '.($appKeyPresent ? 'configured' : 'MISSING'));
    $this->line('Mail sender address: '.($fromConfigured ? 'configured' : 'INVALID'));
    $this->line('OTP database table: '.($tablePresent ? 'present' : 'MISSING'));

    if ($production && $driver !== 'mysql') {
        $this->error('Production is expected to use the configured MySQL database.');
        $ok = false;
    }
    if ($production && in_array($mailer, ['log', 'array'], true)) {
        $this->error('Production OTP emails cannot use a log or array mail driver.');
        $ok = false;
    }
    if (!$appKeyPresent || !$fromConfigured || !$tablePresent) {
        $ok = false;
    }

    if ($ok) {
        $this->info('OTP configuration preflight passed. Send a real test OTP after deployment.');
        return 0;
    }

    $this->error('OTP preflight failed; do not enable mobile authentication yet.');
    return 1;
})->purpose('Read-only M2 production mail, key and database readiness check');

Artisan::command('sign:otp-prune', function (): void {
    // Keep recent challenges for administrator monitoring, remove expired
    // OTP material beyond 24 hours; audit records remain unchanged.
    $deleted = \App\Models\EmailOtpChallenge::query()
        ->where('expires_at', '<', now()->subDay())
        ->delete();

    $this->info('Expired OTP challenges pruned: '.$deleted);
})->purpose('Delete expired M2 OTP challenges older than 24 hours');


// M19 overdue follow-up. Default is read-only; --send also requires an
// explicit business opt-in AND EQUIPMENT_LOAN_AUTO_REMINDERS_ENABLED=true.
// Never enable the sender flag while running test or demo fixtures.
Artisan::command('sign:equipment-overdue-check {--send} {--max=40}',
    function (\App\Services\EquipmentLoanReminderService $reminders): int {
        $send=(bool)$this->option('send');
        $max=max(1,min(100,(int)$this->option('max')));
        $result=$reminders->processAutomatic($max,$send);
        $this->line(json_encode($result,JSON_THROW_ON_ERROR));
        return 0;
    })->purpose('Inspect opted-in overdue equipment loans; explicitly dispatch only if configured');
