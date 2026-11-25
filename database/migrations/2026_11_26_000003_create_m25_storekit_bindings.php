<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{
        Schema::create('m25_storekit_transaction_bindings',function(Blueprint $t):void{
            $t->string('original_transaction_id',64)->primary();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->timestamp('created_at');
        });
    }
    public function down():void{Schema::dropIfExists('m25_storekit_transaction_bindings');}
};
