<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('supplier',255)->nullable();
            $table->string('category',32);
            $table->text('description')->nullable();
            $table->date('expense_date');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency',3)->default('CHF');
            $table->json('tags')->nullable();
            $table->string('status',24)->default('to_review');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reimbursement_requested_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->foreignId('reimbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id','status','expense_date','id'],'m14_company_expense_listing');
            $table->index(['company_id','service_job_id','status'],'m14_job_expense_totals');
        });
        Schema::create('job_expense_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_expense_id')->constrained('job_expenses')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name',255);
            $table->string('private_path',512)->unique();
            $table->string('mime_type',40);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256',64);
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','job_expense_id','removed_at'],'m14_receipt_lookup');
        });
        Schema::create('job_expense_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_expense_id')->constrained('job_expenses')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type',40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','job_expense_id','id'],'m14_expense_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_expense_events');
        Schema::dropIfExists('job_expense_receipts');
        Schema::dropIfExists('job_expenses');
    }
};
