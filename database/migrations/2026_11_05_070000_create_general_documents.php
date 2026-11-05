<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('general_documents',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $t->foreignId('service_job_id')->nullable()->constrained('service_jobs')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('number',80);
            $t->string('document_type',35);
            $t->string('title',255);
            $t->string('locale',10)->default('en');
            $t->string('status',20)->default('draft');
            $t->unsignedInteger('current_revision')->default(1);
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedInteger('approved_revision')->nullable();
            $t->string('request_key',100)->nullable();
            $t->char('request_fingerprint',64)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','number'],'m18_company_number_unique');
            $t->unique(['company_id','request_key'],'m18_create_retry_unique');
            $t->index(['company_id','status','id'],'m18_company_status');
            $t->index(['company_id','service_job_id','id'],'m18_job_documents');
        });
        Schema::create('general_document_revisions',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_id')->constrained('general_documents')->restrictOnDelete();
            $t->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('revision');
            $t->longText('content_json');
            $t->char('content_sha256',64);
            $t->string('source',25)->default('manual');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['general_document_id','revision'],'m18_document_revision_once');
        });
        Schema::create('general_document_events',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_id')->constrained('general_documents')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type',50);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','general_document_id','id'],'m18_document_event_history');
        });
        Schema::create('general_document_attachments',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('general_document_id')->constrained('general_documents')->restrictOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('original_name',255);
            $t->string('private_path',512)->unique();
            $t->string('mime_type',40);
            $t->unsignedBigInteger('byte_size');
            $t->char('sha256',64);
            $t->timestamp('removed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','general_document_id','removed_at'],'m18_attachment_status');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('general_document_attachments');
        Schema::dropIfExists('general_document_events');
        Schema::dropIfExists('general_document_revisions');
        Schema::dropIfExists('general_documents');
    }
};
