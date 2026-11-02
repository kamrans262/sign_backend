<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_number', 40)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_platform_admin')->default(false)->index();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->char('country_iso_code', 2)->default('CH');
            $table->string('dialing_code', 8)->nullable();
            $table->string('phone_number', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('swiss_company_id')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('iban', 48)->nullable();
            $table->string('qr_iban', 48)->nullable();
            $table->string('document_numbering')->nullable();
            $table->string('terms_path')->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('company_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->string('status', 16)->default('active');
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone_number', 'is_active', 'is_platform_admin']);
        });
    }
};
