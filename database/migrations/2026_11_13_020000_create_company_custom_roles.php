<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::table('company_user', function (Blueprint $table): void {
            $table->foreignId('company_role_id')->nullable()
                ->constrained('company_roles')->nullOnDelete();
        });
        Schema::table('company_invitations', function (Blueprint $table): void {
            $table->foreignId('company_role_id')->nullable()
                ->constrained('company_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_role_id');
        });
        Schema::table('company_user', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_role_id');
        });
        Schema::dropIfExists('company_roles');
    }
};
