<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('active_company_id')->nullable()->index();
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('logo_original_name', 255)->nullable();
            $table->string('terms_original_name', 255)->nullable();
        });
        Schema::create('company_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 255);
            $table->string('role', 20)->default('member');
            $table->json('permissions')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'email']);
            $table->index(['email', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['logo_original_name', 'terms_original_name']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('active_company_id');
        });
    }
};
