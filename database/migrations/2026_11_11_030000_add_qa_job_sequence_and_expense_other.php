<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical job IDs and document references remain untouched.
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('job_sequence')->default(0);
        });
        Schema::table('service_jobs', function (Blueprint $table): void {
            $table->dropUnique('service_jobs_reference_number_unique');
            // Two unrelated companies with the same initial can both use JOB-S-001.
            $table->unique(['company_id', 'reference_number'],
                'service_jobs_company_reference_unique');
        });
        Schema::table('job_expenses', function (Blueprint $table): void {
            $table->string('custom_category', 120)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('job_expenses', function (Blueprint $table): void {
            $table->dropColumn('custom_category');
        });
        Schema::table('service_jobs', function (Blueprint $table): void {
            $table->dropUnique('service_jobs_company_reference_unique');
            // Recreating the old global unique index is only safe if no two
            // tenants have since used the same reference_number.
            $table->unique('reference_number', 'service_jobs_reference_number_unique');
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('job_sequence');
        });
    }
};
