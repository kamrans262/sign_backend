<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_labour_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('worker_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('worker_name_snapshot',255);
            $table->string('role',120)->nullable();
            $table->text('task')->nullable();
            $table->string('kind',20)->default('labour');
            $table->string('source',20)->default('manual');
            $table->date('work_date');
            $table->string('timezone',64)->default('Europe/Zurich');
            $table->unsignedSmallInteger('start_minutes');
            $table->unsignedSmallInteger('end_minutes');
            $table->dateTime('work_started_at');
            $table->dateTime('work_ended_at');
            $table->unsignedInteger('duration_minutes');
            // NULL means rate/cost unknown; 0 is a confirmed zero rate.
            $table->unsignedBigInteger('hourly_rate_minor')->nullable();
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->char('currency',3)->default('CHF');
            $table->string('status',20)->default('pending');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id','service_job_id','status','work_date'],'m15_job_labour_summary');
            $table->index(['company_id','worker_user_id','work_started_at','work_ended_at'],'m15_worker_time_overlap');
            $table->index(['company_id','status','work_date','id'],'m15_labour_listing');
        });

        // A single persisted row per worker per company serializes state transitions.
        // Subsequent starts reuse the row; no duplicated running sessions.
        Schema::create('job_labour_timers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->string('status',20)->default('stopped'); // running/paused/stopped
            $table->string('role',120)->nullable();
            $table->text('task')->nullable();
            $table->unsignedBigInteger('hourly_rate_minor')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('running_since')->nullable();
            $table->unsignedInteger('accumulated_seconds')->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['company_id','worker_user_id'],'m15_unique_company_worker_timer');
            $table->index(['company_id','status','service_job_id'],'m15_company_running_timers');
        });
        Schema::create('job_labour_timer_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_user_id')->constrained('users')->restrictOnDelete();
            $table->string('request_key',64);
            $table->string('action',16);
            $table->json('response');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['company_id','worker_user_id','request_key'],'m15_unique_timer_request');
        });
        Schema::create('job_labour_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_labour_entry_id')->nullable()->constrained('job_labour_entries')->restrictOnDelete();
            $table->foreignId('worker_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type',40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','job_labour_entry_id','id'],'m15_labour_audit_lookup');
        });
        Schema::create('job_labour_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_labour_entry_id')->constrained('job_labour_entries')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name',255);
            $table->string('private_path',512)->unique();
            $table->char('sha256',64);
            $table->string('mime_type',40);
            $table->unsignedBigInteger('byte_size');
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','job_labour_entry_id','removed_at'],'m15_labour_evidence_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_labour_evidence');
        Schema::dropIfExists('job_labour_events');
        Schema::dropIfExists('job_labour_timer_requests');
        Schema::dropIfExists('job_labour_timers');
        Schema::dropIfExists('job_labour_entries');
    }
};
