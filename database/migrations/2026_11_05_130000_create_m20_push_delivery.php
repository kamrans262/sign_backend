<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m20_push_devices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('installation_id');
            $t->string('platform', 10);
            $t->char('token_hash', 64);
            $t->text('encrypted_token');
            $t->boolean('enabled')->default(true);
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'user_id', 'installation_id'], 'm20_device_installation_unique');
            $t->index(['company_id', 'user_id', 'enabled'], 'm20_device_active');
        });
        Schema::create('m20_push_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('notification_id')->constrained('m20_notifications')->restrictOnDelete();
            $t->foreignId('device_id')->constrained('m20_push_devices')->restrictOnDelete();
            $t->unsignedBigInteger('template_revision_id')->nullable();
            $t->string('title', 180);
            $t->text('body');
            $t->string('status', 20)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->string('provider_reference', 255)->nullable();
            $t->string('failure_code', 48)->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'device_id'], 'm20_push_device_idempotent');
            $t->index(['status', 'next_attempt_at', 'id'], 'm20_push_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m20_push_deliveries');
        Schema::dropIfExists('m20_push_devices');
    }
};
