<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m23_plan_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('m23_plans')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status',16);
            $table->json('snapshot');
            $table->foreignId('changed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['plan_id','revision'],'m23_plan_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m23_plan_revisions');
    }
};
