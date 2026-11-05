<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_invoices',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_job_id')->constrained('service_jobs')->restrictOnDelete();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->foreignId('service_quote_id')->nullable()->constrained('service_quotes')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('invoice_number',50);
            $t->string('invoice_type',16)->default('final'); // deposit / final
            $t->string('status',25)->default('draft'); // draft, approved, sent, void
            $t->char('currency',3)->default('CHF');
            $t->date('invoice_date');
            $t->date('due_date');
            $t->string('client_name_snapshot',255);
            $t->string('client_email_snapshot',255)->nullable();
            $t->string('client_phone_snapshot',60)->nullable();
            $t->text('billing_address')->nullable();
            $t->string('iban',64)->nullable();
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->unsignedSmallInteger('vat_basis_points')->default(0);
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('vat_minor');
            $t->unsignedBigInteger('total_minor');
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedInteger('revision')->default(1);
            $t->string('request_key',100)->nullable();
            $t->char('request_fingerprint',64)->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['company_id','invoice_number'],'m17_invoice_company_number');
            $t->unique(['company_id','request_key'],'m17_invoice_company_request');
            $t->index(['company_id','service_job_id','status'],'m17_job_invoice_status');
            $t->index(['company_id','invoice_date','id'],'m17_invoice_listing');
        });
        Schema::create('service_invoice_items',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->unsignedSmallInteger('position');
            $t->string('description',1000);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('unit_price_minor');
            $t->unsignedBigInteger('total_minor');
            $t->unique(['service_invoice_id','position'],'m17_invoice_item_position');
        });
        Schema::create('service_invoice_payments',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->string('method',40);
            $t->string('reference_number',120)->nullable();
            $t->date('payment_date');
            $t->string('status',20)->default('recorded'); // recorded/void
            $t->string('request_key',100)->nullable();
            $t->char('request_fingerprint',64)->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['company_id','request_key'],'m17_invoice_payment_retry');
            $t->index(['company_id','service_invoice_id','status'],'m17_invoice_payment_listing');
        });
        Schema::create('service_invoice_deposit_allocations',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->foreignId('job_deposit_id')->constrained('job_deposits')->restrictOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('created_at')->useCurrent();
            // Exactly one invoice can claim a confirmed deposit at once.
            $t->unique('job_deposit_id','m17_deposit_allocated_once');
            $t->index(['company_id','service_invoice_id'],'m17_invoice_allocations');
        });
        Schema::create('service_invoice_events',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type',40);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','service_invoice_id','id'],'m17_invoice_events');
        });
        Schema::create('service_invoice_attachments',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('original_name',255);
            $t->string('private_path',512)->unique();
            $t->char('sha256',64);
            $t->string('mime_type',40);
            $t->unsignedBigInteger('byte_size');
            $t->timestamp('removed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','service_invoice_id','removed_at'],'m17_invoice_attachments');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('service_invoice_attachments');
        Schema::dropIfExists('service_invoice_events');
        Schema::dropIfExists('service_invoice_deposit_allocations');
        Schema::dropIfExists('service_invoice_payments');
        Schema::dropIfExists('service_invoice_items');
        Schema::dropIfExists('service_invoices');
    }
};
