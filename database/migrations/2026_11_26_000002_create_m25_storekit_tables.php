<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('m25_storekit_accounts',function(Blueprint $t):void{
            $t->foreignId('company_id')->primary()->constrained('companies')->restrictOnDelete();
            $t->uuid('account_token')->unique();$t->timestamp('pending_until')->nullable();$t->timestamps();
        });
        Schema::create('m25_storekit_subscriptions',function(Blueprint $t):void{
            $t->id();$t->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $t->foreignId('plan_id')->constrained('m23_plans')->restrictOnDelete();
            $t->string('original_transaction_id',64)->unique();$t->string('transaction_id',64);
            $t->string('environment',16);$t->string('status',32);
            $t->timestamp('period_starts_at');$t->timestamp('period_ends_at');
            $t->boolean('cancel_at_period_end')->default(false);$t->timestamp('provider_synced_at');$t->timestamps();
        });
    }
    public function down():void{
        Schema::dropIfExists('m25_storekit_subscriptions');Schema::dropIfExists('m25_storekit_accounts');
    }
};
