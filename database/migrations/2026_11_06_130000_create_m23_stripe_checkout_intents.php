<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m23_checkout_intents',function(Blueprint $t):void {
            $t->id();
            $t->uuid('request_id');
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('plan_id')->constrained('m23_plans')->restrictOnDelete();
            $t->string('stripe_checkout_session_id',255)->nullable()->unique();
            $t->string('status',24)->default('pending');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','request_id'],'m23_checkout_retry_unique');
            $t->index(['company_id','status'],'m23_checkout_company_status');
        });
        Schema::table('m23_company_subscriptions',function(Blueprint $t):void {
            $t->string('stripe_subscription_item_id',255)->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestamp('last_provider_event_at')->nullable();
        });
        Schema::table('m23_provider_events',function(Blueprint $t):void {
            $t->string('error_code',70)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('provider_created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m23_provider_events',function(Blueprint $t):void {
            $t->dropColumn(['error_code','attempts','provider_created_at']);
        });
        Schema::table('m23_company_subscriptions',function(Blueprint $t):void {
            $t->dropColumn(['stripe_subscription_item_id','cancel_at_period_end','last_provider_event_at']);
        });
        Schema::dropIfExists('m23_checkout_intents');
    }
};
