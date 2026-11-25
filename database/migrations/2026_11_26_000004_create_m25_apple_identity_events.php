<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('m25_apple_identity_events',function(Blueprint $t):void {
   $t->char('event_id',64)->primary(); $t->string('type',32); $t->timestamp('created_at');
  });
 }
 public function down(): void { Schema::dropIfExists('m25_apple_identity_events'); }
};
