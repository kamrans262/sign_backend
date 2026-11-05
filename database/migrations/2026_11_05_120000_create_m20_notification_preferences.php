<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('m20_reminders', function (Blueprint $t): void {
            $t->dateTime('follow_up_notified_at')->nullable();
        });
        Schema::create('m20_notification_preferences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->boolean('in_app_enabled')->default(true);
            $t->boolean('push_enabled')->default(false);
            $t->string('locale', 5)->default('en');
            $t->string('timezone', 64)->default('UTC');
            $t->timestamps();
            $t->unique(['company_id', 'user_id'], 'm20_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m20_notification_preferences');
        Schema::table('m20_reminders', function (Blueprint $t): void {
            $t->dropColumn('follow_up_notified_at');
        });
    }
};