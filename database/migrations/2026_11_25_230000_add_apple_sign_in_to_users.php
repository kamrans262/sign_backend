<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('apple_subject', 255)->nullable()->unique();
        });
        Schema::create('apple_sign_in_challenges', function (Blueprint $table): void {
            $table->string('nonce_hash', 64)->primary();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_sign_in_challenges');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['apple_subject']);
            $table->dropColumn('apple_subject');
        });
    }
};
