<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // M9 never alters or migrates the M7 approval-bound and M8 signed PDFs.
        Schema::create('managed_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 24);
            $table->string('number', 80);
            $table->string('title', 255);
            $table->string('status', 24)->default('file');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'category', 'id']);
            $table->index(['company_id', 'service_job_id']);
            $table->index(['company_id', 'client_id']);
        });
        Schema::create('managed_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('managed_document_id')->constrained('managed_documents')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('original_name', 255);
            $table->string('private_path', 512)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['managed_document_id', 'version'], 'm9_document_version_unique');
            $table->index(['company_id', 'created_at']);
        });
        Schema::create('document_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('target_kind', 16); // quote, signed, managed
            $table->unsignedBigInteger('target_id');
            $table->string('original_name', 255);
            $table->string('private_path', 512)->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'target_kind', 'target_id'], 'm9_attachment_parent');
        });
        Schema::create('document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('target_kind', 16);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 45);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'target_kind', 'target_id', 'created_at'], 'm9_events_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_attachments');
        Schema::dropIfExists('managed_document_versions');
        Schema::dropIfExists('managed_documents');
    }
};
