<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('managed_document_id')->nullable()->constrained('managed_documents')->restrictOnDelete();
            $table->string('number', 80)->unique();
            $table->text('completed_work');
            $table->date('completion_date');
            $table->string('cleaning_status', 25);
            $table->text('outstanding_issues')->nullable();
            $table->text('remedial_work')->nullable();
            $table->json('photo_ids')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->string('approved_pdf_path', 512)->nullable()->unique();
            $table->char('approved_pdf_sha256', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('acceptance_result', 32)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->text('customer_reservation')->nullable();
            $table->text('customer_outstanding_issues')->nullable();
            $table->unsignedTinyInteger('customer_rating')->nullable();
            $table->text('customer_comment')->nullable();
            $table->string('signed_pdf_path', 512)->nullable()->unique();
            $table->char('signed_pdf_sha256', 64)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'id'], 'm13_acceptance_listing');
            $table->index(['company_id', 'service_job_id', 'id'], 'm13_acceptance_job');
        });
        Schema::create('work_acceptance_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_acceptance_id')->constrained('work_acceptances')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['work_acceptance_id','revision'], 'm13_revision_unique');
        });
        Schema::create('work_acceptance_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_acceptance_id')->constrained('work_acceptances')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->uuid('document_uuid')->unique();
            $table->char('token_sha256',64)->unique();
            $table->char('consent_reference',64);
            $table->unsignedInteger('approved_revision');
            $table->char('approved_pdf_sha256',64);
            $table->string('signer_name',255);
            $table->string('signer_email',255);
            $table->string('status',20)->default('ready');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id','status','id'], 'm13_request_listing');
        });
        Schema::create('work_acceptance_customer_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_acceptance_id')->constrained('work_acceptances')->restrictOnDelete();
            $table->foreignId('work_acceptance_request_id')->constrained('work_acceptance_requests')->restrictOnDelete();
            $table->string('private_path',512)->unique();
            $table->string('mime_type',40);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256',64);
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('work_acceptance_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_acceptance_id')->unique()->constrained('work_acceptances')->restrictOnDelete();
            $table->foreignId('work_acceptance_request_id')->unique()->constrained('work_acceptance_requests')->restrictOnDelete();
            $table->string('signature_path',512)->unique();
            $table->char('signature_sha256',64);
            $table->string('signed_pdf_path',512)->unique();
            $table->char('signed_pdf_sha256',64);
            $table->json('evidence');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('work_acceptance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_acceptance_id')->constrained('work_acceptances')->restrictOnDelete();
            $table->foreignId('work_acceptance_request_id')->nullable()->constrained('work_acceptance_requests')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type',45);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','work_acceptance_id','id'], 'm13_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_acceptance_events');
        Schema::dropIfExists('work_acceptance_artifacts');
        Schema::dropIfExists('work_acceptance_customer_photos');
        Schema::dropIfExists('work_acceptance_requests');
        Schema::dropIfExists('work_acceptance_revisions');
        Schema::dropIfExists('work_acceptances');
    }
};
