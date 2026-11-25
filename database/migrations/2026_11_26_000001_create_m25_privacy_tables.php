<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('m25_ai_consents', function(Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->string('version',32);
            $t->boolean('allowed');
            $t->timestamps();
        });
        Schema::create('m25_apple_credentials', function(Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->text('refresh_token');
            $t->timestamps();
        });
        Schema::create('m25_apple_revocations', function(Blueprint $t): void {
            $t->id();
            $t->text('refresh_token');
            $t->timestamps();
        });
        Schema::create('m25_deletion_challenges', function(Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->char('proof_hash',64);
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
        });
    }
    public function down(): void {
        foreach(['m25_deletion_challenges','m25_apple_revocations','m25_apple_credentials','m25_ai_consents'] as $table) Schema::dropIfExists($table);
    }
};
