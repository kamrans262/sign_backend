<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_quote_id')->constrained('service_quotes')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->uuid('document_uuid')->unique();
            $table->uuid('signer_uuid')->unique();
            $table->char('token_sha256', 64)->unique();
            $table->string('signer_name', 255);
            $table->string('signer_email', 255);
            $table->unsignedInteger('approved_revision');
            $table->char('approved_pdf_sha256', 64);
            $table->char('consent_reference', 64);
            $table->unsignedInteger('quote_link_version');
            $table->string('status', 20)->default('ready');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'id']);
            $table->index(['service_quote_id', 'status', 'expires_at'], 'sign_request_quote_status_expires');
        });

        Schema::create('signature_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signature_request_id')->constrained('signature_requests')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 45);
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['signature_request_id', 'created_at'], 'sign_events_request_created');
        });

        Schema::create('signed_document_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signature_request_id')->unique()->constrained('signature_requests')->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_quote_id')->constrained('service_quotes')->restrictOnDelete();
            $table->char('source_pdf_sha256', 64);
            $table->unsignedInteger('source_revision');
            $table->string('signed_pdf_path', 512)->unique();
            $table->char('signed_pdf_sha256', 64);
            $table->json('evidence');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'service_quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_document_artifacts');
        Schema::dropIfExists('signature_events');
        Schema::dropIfExists('signature_requests');
    }
};
