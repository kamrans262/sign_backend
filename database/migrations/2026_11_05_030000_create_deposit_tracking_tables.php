<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('service_quote_id')->nullable()->constrained('service_quotes')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_name_snapshot',255);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency',3)->default('CHF');
            $table->date('deposit_date');
            $table->string('payment_method',40);
            $table->string('reference_number',120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status',20)->default('pending'); // pending/received/refunded/void
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('refund_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id','service_job_id','status'],'m16_job_deposits');
            $table->index(['company_id','service_quote_id','status'],'m16_quote_deposits');
            $table->index(['company_id','deposit_date','id'],'m16_deposit_listing');
        });

        Schema::create('job_deposit_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_deposit_id')->constrained('job_deposits')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name',255);
            $table->string('private_path',512)->unique();
            $table->char('sha256',64);
            $table->string('mime_type',40);
            $table->unsignedBigInteger('byte_size');
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','job_deposit_id','removed_at'],'m16_receipt_scope');
        });

        Schema::create('job_deposit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_deposit_id')->constrained('job_deposits')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type',40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id','job_deposit_id','id'],'m16_deposit_audit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_deposit_events');
        Schema::dropIfExists('job_deposit_receipts');
        Schema::dropIfExists('job_deposits');
    }
};
