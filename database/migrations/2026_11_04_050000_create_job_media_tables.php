<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 10);
            $table->string('caption', 187)->default('');
            $table->string('original_name', 255);
            $table->string('private_path', 512)->unique();
            $table->string('mime_type', 40);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'service_job_id', 'archived_at'], 'm11_photo_parent');
        });
        Schema::create('job_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->longText('body');
            $table->string('note_type', 20);
            $table->string('visibility', 20)->default('private');
            $table->string('audio_private_path', 512)->nullable()->unique();
            $table->string('audio_mime_type', 60)->nullable();
            $table->unsignedBigInteger('audio_byte_size')->nullable();
            $table->char('audio_sha256', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'service_job_id', 'archived_at'], 'm11_note_parent');
        });
        Schema::create('job_note_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('job_note_id')->constrained('job_notes')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('managed_document_id')->nullable()->constrained('managed_documents')->restrictOnDelete();
            $table->string('document_type', 20);
            $table->string('title', 255);
            $table->longText('content');
            $table->string('status', 20)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'service_job_id', 'status'], 'm11_conversion_parent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_note_documents');
        Schema::dropIfExists('job_notes');
        Schema::dropIfExists('job_photos');
    }
};
