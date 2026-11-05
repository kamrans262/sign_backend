<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M16 Stage 2 additive retry safety. Existing deposits remain valid.
 * Request keys are optional for older API clients and never contain secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_deposits', function (Blueprint $table): void {
            $table->string('request_key',100)->nullable()->after('service_quote_id');
            $table->char('request_fingerprint',64)->nullable()->after('request_key');
            $table->unique(['company_id','request_key'],'m16_company_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('job_deposits', function (Blueprint $table): void {
            $table->dropUnique('m16_company_request_unique');
            $table->dropColumn(['request_key','request_fingerprint']);
        });
    }
};
