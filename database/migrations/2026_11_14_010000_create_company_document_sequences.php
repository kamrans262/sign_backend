<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Formerly global indexes prevented multiple companies having Q-001.
        Schema::table('service_quotes', function (Blueprint $t): void {
            $t->dropUnique('service_quotes_reference_number_unique');
            $t->unique(['company_id','reference_number'],'s2d_quote_company_ref');
        });
        Schema::table('site_reports', function (Blueprint $t): void {
            $t->dropUnique('site_reports_number_unique');
            $t->unique(['company_id','number'],'s2d_report_company_number');
        });
        Schema::table('work_acceptances', function (Blueprint $t): void {
            $t->dropUnique('work_acceptances_number_unique');
            $t->unique(['company_id','number'],'s2d_wa_company_number');
        });
        Schema::create('company_document_sequences', function (Blueprint $t): void {
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('document_type',32);
            $t->unsignedBigInteger('last_number')->default(0);
            $t->timestamps();
            $t->primary(['company_id','document_type'],'s2d_doc_sequence_pk');
        });
    }

    public function down(): void
    {
        // Undoing company-scoped uniqueness would break after two companies
        // have issued Q-001, and immutable PDFs must never be renumbered.
        throw new RuntimeException(
            'Document numbering is irreversible without a reviewed migration.'
        );
    }
};
