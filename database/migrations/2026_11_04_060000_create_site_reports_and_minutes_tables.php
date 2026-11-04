<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('managed_document_id')->nullable()->constrained('managed_documents')->restrictOnDelete();
            $table->string('number', 80)->unique();
            $table->string('kind', 24); // site_report or meeting_minutes
            $table->string('title', 255);
            $table->date('report_date');
            $table->string('prepared_by', 255);
            $table->string('location', 500)->nullable();
            $table->string('weather', 100)->nullable();
            $table->json('content');
            $table->json('photo_ids')->nullable();
            $table->string('status', 20)->default('draft'); // draft/approved/sent/archived
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('last_sent_to', 255)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'kind', 'status', 'id'], 'm12_listing');
            $table->index(['company_id', 'service_job_id', 'id'], 'm12_job');
        });
        Schema::create('site_report_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_report_id')->constrained('site_reports')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['site_report_id', 'revision'], 'm12_revision_unique');
        });
        Schema::create('site_report_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_report_id')->constrained('site_reports')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 30);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'site_report_id', 'created_at'], 'm12_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_report_events');
        Schema::dropIfExists('site_report_revisions');
        Schema::dropIfExists('site_reports');
    }
};
