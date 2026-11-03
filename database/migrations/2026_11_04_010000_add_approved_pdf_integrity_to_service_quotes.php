<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_quotes', function (Blueprint $table): void {
            // An approved PDF lives on a private local disk and is bound to
            // exactly the revision approved by the company user.
            $table->string('approved_pdf_path', 255)->nullable();
            $table->char('approved_pdf_sha256', 64)->nullable();
            $table->unsignedInteger('approved_pdf_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_quotes', function (Blueprint $table): void {
            $table->dropColumn([
                'approved_pdf_path', 'approved_pdf_sha256', 'approved_pdf_revision',
            ]);
        });
    }
};
