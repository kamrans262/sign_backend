<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('service_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained('service_jobs')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_number', 40)->unique();
            // The original Review Quote form supports a user-editable number,
            // distinct from the immutable server-generated audit reference.
            $table->string('custom_quote_number', 40)->nullable();
            $table->unique(['company_id','custom_quote_number']);
            $table->string('client_name', 255)->default('');
            $table->string('client_email', 255)->nullable();
            $table->string('client_phone', 60)->nullable();
            $table->text('billing_address')->nullable();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('CHF');
            $table->unsignedInteger('vat_basis_points')->default(0);
            $table->unsignedInteger('deposit_basis_points')->default(0);
            $table->longText('terms')->nullable();
            $table->json('payment_details')->nullable();
            $table->string('source_method', 30)->default('manual');
            $table->string('status', 20)->default('draft'); // draft, approved, sent, archived
            $table->string('status_before_archive', 20)->nullable();
            $table->unsignedInteger('revision')->default(1);
            // Rotated on archive so previously issued customer URLs cannot revive.
            $table->unsignedInteger('link_version')->default(1);
            $table->unsignedBigInteger('subtotal_minor')->nullable();
            $table->unsignedBigInteger('vat_minor')->nullable();
            $table->unsignedBigInteger('total_minor')->nullable();
            $table->unsignedBigInteger('deposit_minor')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'id']);
            $table->index(['company_id', 'client_id']);
            $table->index(['company_id', 'service_job_id']);
        });
        Schema::create('service_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_quote_id')->constrained('service_quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->unsignedBigInteger('unit_price_minor')->nullable();
            $table->json('other_fields')->nullable();
            $table->json('unresolved_fields')->nullable();
            $table->unique(['service_quote_id', 'position']);
        });
        Schema::create('service_quote_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_quote_id')->constrained('service_quotes')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['service_quote_id','revision']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('service_quote_revisions');
        Schema::dropIfExists('service_quote_items');
        Schema::dropIfExists('service_quotes');
    }
};
