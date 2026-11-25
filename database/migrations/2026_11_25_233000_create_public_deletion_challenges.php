<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_deletion_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->char('email_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
        });
        Schema::table('m22_support_tickets', function (Blueprint $table): void {
            $table->char('deletion_verified_email_hash', 64)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('m22_support_tickets', fn (Blueprint $table) => $table->dropColumn('deletion_verified_email_hash'));
        Schema::dropIfExists('public_deletion_challenges');
    }
};
