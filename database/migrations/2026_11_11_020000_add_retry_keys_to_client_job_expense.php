<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped retry keys. Nullable keys preserve all existing records.
 * Unique indexes serialize concurrent duplicate submissions at the DB layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients', 'service_jobs', 'job_expenses'] as $table) {
            Schema::table($table, function (Blueprint $schema) use ($table): void {
                $schema->string('request_key', 100)->nullable();
                $schema->char('request_fingerprint', 64)->nullable();
                $schema->unique(['company_id', 'request_key'],
                    's2d_'.$table.'_retry_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['job_expenses', 'service_jobs', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $schema) use ($table): void {
                $schema->dropUnique('s2d_'.$table.'_retry_unique');
                $schema->dropColumn(['request_key', 'request_fingerprint']);
            });
        }
    }
};
