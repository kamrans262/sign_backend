<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 255);
            $table->string('company_name', 255)->nullable();
            $table->text('address')->nullable();
            $table->char('country_iso_code', 2)->nullable()->default('CH');
            $table->string('dialing_code', 8)->nullable();
            $table->string('phone_number', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 24)->default('manual');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'full_name']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
