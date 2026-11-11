<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m24_two_factor_credentials', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->text('secret_ciphertext')->nullable();
            $table->text('pending_secret_ciphertext')->nullable();
            $table->timestamp('pending_expires_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });
        Schema::create('m24_two_factor_recovery_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'code_hash']);
        });
        Schema::create('m24_two_factor_login_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('ticket_hash', 64)->unique();
            $table->string('device_name', 100);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m24_two_factor_login_challenges');
        Schema::dropIfExists('m24_two_factor_recovery_codes');
        Schema::dropIfExists('m24_two_factor_credentials');
    }
};
