<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m23_billing_settings', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedInteger('free_document_limit')->default(3);
            $t->boolean('enforcement_enabled')->default(false);
            $t->boolean('stripe_enabled')->default(false);
            $t->unsignedInteger('trial_days')->nullable();
            $t->string('currency', 3)->default('CHF');
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        DB::table('m23_billing_settings')->insert([
            'id'=>1, 'free_document_limit'=>3,
            'enforcement_enabled'=>false, 'stripe_enabled'=>false,
            'trial_days'=>null, 'currency'=>'CHF',
            'created_at'=>now(), 'updated_at'=>now(),
        ]);
        Schema::create('m23_plans', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 48);
            $t->string('name', 120);
            $t->string('period', 12);
            $t->string('currency', 3)->default('CHF');
            $t->unsignedBigInteger('amount_minor');
            $t->string('description', 500)->nullable();
            $t->json('features');
            $t->unsignedInteger('document_limit')->nullable();
            $t->string('stripe_price_id', 255)->nullable()->unique();
            $t->string('status', 16)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['code','period'], 'm23_plans_code_period_uq');
            $t->index(['status','period']);
        });
        Schema::create('m23_company_subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $t->foreignId('plan_id')->nullable()->constrained('m23_plans')->restrictOnDelete();
            $t->string('status', 32)->default('free');
            $t->string('stripe_customer_id', 255)->nullable()->unique();
            $t->string('stripe_subscription_id', 255)->nullable()->unique();
            $t->timestamp('period_starts_at')->nullable();
            $t->timestamp('period_ends_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('cancel_at')->nullable();
            $t->timestamp('canceled_at')->nullable();
            $t->timestamp('provider_synced_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status','period_ends_at'], 'm23_subscriptions_state_period_idx');
        });
        Schema::create('m23_document_usage', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('source_type', 36);
            $t->unsignedBigInteger('source_id');
            $t->string('issue_event', 32)->default('first_issuance');
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('issued_at');
            $t->timestamps();
            $t->unique(['company_id','source_type','source_id','issue_event'],
                'm23_usage_unique_issued_source');
            $t->index(['company_id','issued_at'], 'm23_usage_company_date_idx');
        });
        Schema::create('m23_subscription_grants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('label', 120);
            $t->text('reason');
            $t->timestamp('starts_at');
            $t->timestamp('expires_at');
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id','expires_at'], 'm23_grants_company_expiry_idx');
        });
        Schema::create('m23_subscription_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type', 100);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at');
            $t->index(['company_id','created_at'], 'm23_events_company_date_idx');
        });
        Schema::create('m23_provider_events', function (Blueprint $t): void {
            $t->id();
            $t->string('provider_event_id', 255)->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $t->string('event_type', 100);
            $t->string('status', 20)->default('pending');
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('m23_billing_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('stripe_invoice_id', 255)->unique();
            $t->string('status', 32);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_due_minor');
            $t->unsignedBigInteger('amount_paid_minor')->default(0);
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['company_id','issued_at'], 'm23_invoices_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m23_billing_invoices');
        Schema::dropIfExists('m23_provider_events');
        Schema::dropIfExists('m23_subscription_events');
        Schema::dropIfExists('m23_subscription_grants');
        Schema::dropIfExists('m23_document_usage');
        Schema::dropIfExists('m23_company_subscriptions');
        Schema::dropIfExists('m23_plans');
        Schema::dropIfExists('m23_billing_settings');
    }
};
