<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_invoice_issues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('private_path', 512)->unique();
            $t->char('pdf_sha256', 64);
            $t->char('snapshot_sha256', 64);
            $t->unsignedBigInteger('byte_size');
            $t->longText('snapshot'); // Retain byte-identical canonical JSON for SHA verification.
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['service_invoice_id', 'revision'], 'm17_issue_revision_once');
            $t->index(['company_id', 'service_invoice_id'], 'm17_issue_company_invoice');
        });
        Schema::create('service_invoice_customer_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_issue_id')->constrained('service_invoice_issues')->restrictOnDelete();
            $t->char('token_sha256', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'service_invoice_issue_id'], 'm17_customer_invoice_lookup');
        });
        Schema::create('service_invoice_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
            $t->foreignId('service_invoice_issue_id')->constrained('service_invoice_issues')->restrictOnDelete();
            $t->foreignId('customer_link_id')->nullable()->constrained('service_invoice_customer_links')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recipient_email', 255);
            $t->string('mode', 12);
            $t->string('status', 16)->default('pending');
            $t->string('request_key', 100);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['company_id', 'request_key'], 'm17_invoice_send_idempotency');
            $t->index(['company_id', 'service_invoice_id', 'status'], 'm17_invoice_delivery_audit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_invoice_deliveries');
        Schema::dropIfExists('service_invoice_customer_links');
        Schema::dropIfExists('service_invoice_issues');
    }
};
