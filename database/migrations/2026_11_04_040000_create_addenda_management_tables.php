<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // M10 is additive: NEVER mutate or overwrite M7/M8 frozen originals.
        Schema::create('addenda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_signed_artifact_id')
                ->constrained('signed_document_artifacts')->restrictOnDelete();
            $table->foreignId('service_quote_id')->constrained('service_quotes')->restrictOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number',80);
            $table->string('title',255);
            $table->text('change_description');
            $table->date('effective_date');
            $table->text('additional_work')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->unsignedInteger('vat_basis_points')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('vat_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->char('currency',3);
            $table->char('source_pdf_sha256',64);
            $table->unsignedInteger('source_revision');
            $table->string('status',20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->string('approved_pdf_path',512)->nullable()->unique();
            $table->char('approved_pdf_sha256',64)->nullable();
            $table->unsignedInteger('approved_revision')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('signed_pdf_path',512)->nullable()->unique();
            $table->char('signed_pdf_sha256',64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id','number'],'m10_company_addendum_number');
            $table->index(['company_id','status','id']);
            $table->index(['source_signed_artifact_id','id']);
            $table->index(['company_id','service_job_id']);
        });
        Schema::create('addendum_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addendum_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['addendum_id','revision']);
        });
        Schema::create('addendum_signing_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addendum_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->uuid('document_uuid')->unique();
            $table->uuid('signer_uuid')->unique();
            $table->char('token_sha256',64)->unique();
            $table->string('signer_name',255);
            $table->string('signer_email',255);
            $table->unsignedInteger('approved_revision');
            $table->char('approved_pdf_sha256',64);
            $table->char('consent_reference',64);
            $table->string('status',20)->default('ready');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id','status','id']);
            $table->index(['addendum_id','status','expires_at']);
        });
        Schema::create('addendum_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('addendum_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type',45);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','addendum_id','created_at']);
        });
    }

    public function down(): void
    {
        // Explicit rollback is for disposable test databases only;
        // production release script must NEVER run this down migration.
        Schema::dropIfExists('addendum_events');
        Schema::dropIfExists('addendum_signing_requests');
        Schema::dropIfExists('addendum_revisions');
        Schema::dropIfExists('addenda');
    }
};
