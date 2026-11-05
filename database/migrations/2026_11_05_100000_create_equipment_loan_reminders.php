<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('equipment_loan_reminder_settings',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_loan_id');
            $t->foreign('equipment_loan_id','m19_setting_loan_fk')
                ->references('id')->on('equipment_loans')->restrictOnDelete();
            $t->boolean('automatic_enabled')->default(false);
            $t->boolean('recipient_confirmed')->default(false);
            $t->unsignedTinyInteger('frequency_days')->default(7);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique('equipment_loan_id','m19_setting_one_per_loan');
            $t->index(['company_id','automatic_enabled'],'m19_setting_company_auto');
        });
        Schema::create('equipment_loan_reminders',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_loan_id');
            $t->foreign('equipment_loan_id','m19_reminder_loan_fk')
                ->references('id')->on('equipment_loans')->restrictOnDelete();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind',16);
            $t->date('due_date');
            $t->unsignedInteger('loan_version');
            $t->string('recipient_email',255);
            $t->string('request_key',130);
            $t->char('request_fingerprint',64);
            $t->string('status',24)->default('pending');
            $t->unsignedTinyInteger('attempt_count')->default(0);
            $t->string('last_error_code',60)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','request_key'],'m19_reminder_idempotency');
            $t->index(['company_id','equipment_loan_id','id'],'m19_reminder_history');
            $t->index(['company_id','status','due_date'],'m19_reminder_pending');
        });
        Schema::create('equipment_loan_delivery_attempts',function(Blueprint $t):void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_loan_reminder_id');
            $t->foreign('equipment_loan_reminder_id','m19_attempt_reminder_fk')
                ->references('id')->on('equipment_loan_reminders')->restrictOnDelete();
            $t->unsignedTinyInteger('attempt_number');
            $t->string('status',24);
            $t->string('transport',30);
            $t->string('error_code',60)->nullable();
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('finished_at')->nullable();
            $t->unique(['equipment_loan_reminder_id','attempt_number'],'m19_attempt_once');
            $t->index(['company_id','id'],'m19_attempt_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_loan_delivery_attempts');
        Schema::dropIfExists('equipment_loan_reminders');
        Schema::dropIfExists('equipment_loan_reminder_settings');
    }
};
