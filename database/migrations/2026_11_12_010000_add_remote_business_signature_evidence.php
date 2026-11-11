<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional issuer signature for remote two-party quotations only. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table): void {
            $table->string('business_signature_path', 512)->nullable();
            $table->char('business_signature_sha256', 64)->nullable();
            $table->string('business_signature_method', 12)->nullable();
            $table->timestamp('business_signed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'business_signature_path', 'business_signature_sha256',
                'business_signature_method', 'business_signed_at',
            ]);
        });
    }
};
