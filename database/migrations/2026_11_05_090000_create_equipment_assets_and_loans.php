<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('equipment_assets',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code',80);
            $t->string('name',255);
            $t->string('category',120);
            $t->string('brand',160);
            $t->string('model',160);
            $t->date('purchase_date');
            $t->string('status',24)->default('available');
            $t->string('location',255)->default('');
            $t->text('notes')->nullable();
            $t->decimal('initial_hours',12,2)->default(0);
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','code'],'m19_asset_company_code');
            $t->index(['company_id','status','id'],'m19_asset_listing');
        });
        Schema::create('equipment_asset_events',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_asset_id')->constrained('equipment_assets')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type',60);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','equipment_asset_id','id'],'m19_asset_events');
        });
        Schema::create('equipment_usage_entries',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_asset_id')->constrained('equipment_assets')->restrictOnDelete();
            $t->foreignId('service_job_id')->nullable()->constrained('service_jobs')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('usage_date');
            $t->decimal('hours',10,2);
            $t->text('notes')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','equipment_asset_id','id'],'m19_asset_usage');
        });
        Schema::create('equipment_maintenance_entries',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_asset_id')->constrained('equipment_assets')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('maintenance_date');
            $t->string('description',2000);
            $t->boolean('completed')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','equipment_asset_id','id'],'m19_asset_maintenance');
        });
        Schema::create('equipment_asset_photos',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_asset_id')->constrained('equipment_assets')->restrictOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('original_name',255);
            $t->string('private_path',512)->unique();
            $t->string('mime_type',40);
            $t->unsignedBigInteger('byte_size');
            $t->char('sha256',64);
            $t->timestamp('removed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','equipment_asset_id','removed_at'],'m19_asset_photos');
        });
        Schema::create('equipment_loans',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_asset_id')->constrained('equipment_assets')->restrictOnDelete();
            $t->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $t->foreignId('service_job_id')->nullable()->constrained('service_jobs')->restrictOnDelete();
            $t->string('borrower_type',24);
            $t->string('borrower_name',255);
            $t->string('borrower_email',255)->nullable();
            $t->date('loan_date');
            $t->date('expected_return_date');
            $t->date('actual_return_date')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->string('status',20)->default('active');
            // MySQL + SQLite: nullable unique slot prevents two concurrent active
            // loans while permitting unlimited historical returned loans.
            $t->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $t->unsignedInteger('lock_version')->default(1);
            $t->string('request_key',100)->nullable();
            $t->char('request_fingerprint',64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id','equipment_asset_id','active_slot'],'m19_loan_one_active');
            $t->unique(['company_id','request_key'],'m19_loan_retry');
            $t->index(['company_id','status','expected_return_date'],'m19_loan_status_due');
            $t->index(['company_id','client_id','id'],'m19_loan_clients');
        });
        Schema::create('equipment_loan_events',function(Blueprint $t):void{
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('equipment_loan_id')->constrained('equipment_loans')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type',60);
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','equipment_loan_id','id'],'m19_loan_history');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('equipment_loan_events');
        Schema::dropIfExists('equipment_loans');
        Schema::dropIfExists('equipment_asset_photos');
        Schema::dropIfExists('equipment_maintenance_entries');
        Schema::dropIfExists('equipment_usage_entries');
        Schema::dropIfExists('equipment_asset_events');
        Schema::dropIfExists('equipment_assets');
    }
};
