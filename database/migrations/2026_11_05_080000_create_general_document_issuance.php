<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('general_document_issues',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_id')->constrained('general_documents')->restrictOnDelete();
            $t->unsignedInteger('approved_revision');
            $t->char('revision_sha256',64);
            $t->longText('snapshot');
            $t->char('snapshot_sha256',64);
            $t->string('private_path',512)->unique();
            $t->char('pdf_sha256',64);
            $t->unsignedBigInteger('byte_size');
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['general_document_id','approved_revision'],'m18_issue_once');
            $t->index(['company_id','general_document_id'],'m18_issue_company');
        });
        Schema::create('general_document_customer_links',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_issue_id')->constrained('general_document_issues')->restrictOnDelete();
            $t->char('token_sha256',64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','general_document_issue_id'],'m18_link_index');
        });
        Schema::create('general_document_signature_requests',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_issue_id')->constrained('general_document_issues')->restrictOnDelete();
            $t->char('token_sha256',64)->unique();
            $t->uuid('request_uuid')->unique();
            $t->uuid('document_uuid');
            $t->char('consent_reference',64);
            $t->string('signer_name',255);
            $t->string('signer_email',255);
            $t->string('status',20)->default('ready');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('signed_pdf_path',512)->nullable()->unique();
            $t->char('signed_pdf_sha256',64)->nullable();
            $t->longText('evidence_json')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->nullable();
            $t->index(['company_id','general_document_issue_id','status'],'m18_signature_list');
        });
        Schema::create('general_document_deliveries',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_issue_id')->constrained('general_document_issues')->restrictOnDelete();
            $t->foreignId('general_document_customer_link_id')->nullable()->constrained('general_document_customer_links')->restrictOnDelete();
            $t->foreignId('general_document_signature_request_id')->nullable()->constrained('general_document_signature_requests')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recipient_email',255);
            $t->string('mode',20);
            $t->string('status',20)->default('pending');
            $t->string('request_key',100);
            $t->char('request_fingerprint',64);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['company_id','request_key'],'m18_delivery_idempotency');
            $t->index(['company_id','general_document_issue_id','status'],'m18_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_document_deliveries');
        Schema::dropIfExists('general_document_signature_requests');
        Schema::dropIfExists('general_document_customer_links');
        Schema::dropIfExists('general_document_issues');
    }
};
