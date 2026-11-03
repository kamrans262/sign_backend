<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel already uses "jobs" for the queue. Never change that table.
        Schema::create('service_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_number', 40)->unique();
            $table->string('title', 255);
            $table->text('description');
            $table->text('address')->nullable();
            $table->date('start_date');
            $table->date('due_date');
            $table->decimal('budget_chf', 14, 2)->nullable();
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->string('stage', 20)->default('planning'); // planning / in_progress / completed
            $table->string('category', 20)->default('active'); // active / completed / archived
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'category', 'due_date']);
            $table->index(['company_id', 'client_id']);
            $table->index(['company_id', 'stage']);
        });

        Schema::create('service_job_user', function (Blueprint $table): void {
            $table->foreignId('service_job_id')->constrained('service_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->primary(['service_job_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_job_user');
        Schema::dropIfExists('service_jobs');
    }
};
