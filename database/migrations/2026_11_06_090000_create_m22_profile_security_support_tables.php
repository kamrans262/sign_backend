<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
 public function up(): void {
  Schema::table('users', function(Blueprint $t): void {
   $t->string('job_title',120)->nullable();
   $t->string('profile_photo_path',255)->nullable();
   $t->string('profile_photo_mime',30)->nullable();
  });
  Schema::create('m22_user_preferences', function(Blueprint $t): void {
   $t->id(); $t->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
   $t->string('language',12)->default('English');
   $t->string('date_format',16)->default('DD/MM/YYYY');
   $t->string('time_format',16)->default('24 hours');
   $t->string('timezone',64)->default('Europe/Zurich');
   $t->boolean('push_notifications')->default(true);
   $t->boolean('email_notifications')->default(true);
   $t->boolean('sms_notifications')->default(false); $t->timestamps();
  });
  Schema::create('m22_email_change_challenges', function(Blueprint $t): void {
   $t->uuid('id')->primary(); $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $t->string('pending_email',255); $t->char('code_hash',64);
   $t->unsignedTinyInteger('attempts')->default(0);
   $t->timestamp('expires_at'); $t->timestamp('consumed_at')->nullable(); $t->timestamps();
   $t->index(['user_id','created_at']);
  });
  Schema::create('m22_support_faqs', function(Blueprint $t): void {
   $t->id(); $t->string('locale',8)->default('en');
   $t->string('category',60)->default('general');
   $t->string('question',250); $t->text('answer');
   $t->boolean('is_published')->default(false);
   $t->unsignedInteger('sort_order')->default(0);
   $t->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamps(); $t->index(['is_published','locale','sort_order']);
  });
  Schema::create('m22_support_tickets', function(Blueprint $t): void {
   $t->id(); $t->string('reference',32)->unique();
   $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
   $t->string('category',32); $t->string('subject',180); $t->text('description');
   $t->string('status',20)->default('open');
   $t->string('priority',16)->default('normal');
   $t->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
   $t->string('app_version',48)->nullable(); $t->timestamps();
   $t->index(['user_id','created_at']); $t->index(['company_id','status']);
   $t->index(['status','priority','created_at']);
  });
  Schema::create('m22_support_replies', function(Blueprint $t): void {
   $t->id(); $t->foreignId('ticket_id')->constrained('m22_support_tickets')->cascadeOnDelete();
   $t->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
   $t->text('body'); $t->boolean('internal')->default(false);
   $t->timestamps(); $t->index(['ticket_id','created_at']);
  });
  Schema::create('m22_support_attachments', function(Blueprint $t): void {
   $t->id(); $t->foreignId('ticket_id')->constrained('m22_support_tickets')->cascadeOnDelete();
   $t->foreignId('reply_id')->nullable()->constrained('m22_support_replies')->cascadeOnDelete();
   $t->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
   $t->string('private_path',255); $t->string('mime_type',30);
   $t->string('original_name',160); $t->unsignedInteger('byte_size');
   $t->char('sha256',64); $t->timestamps(); $t->index(['ticket_id','id']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('m22_support_attachments');
  Schema::dropIfExists('m22_support_replies');
  Schema::dropIfExists('m22_support_tickets');
  Schema::dropIfExists('m22_support_faqs');
  Schema::dropIfExists('m22_email_change_challenges');
  Schema::dropIfExists('m22_user_preferences');
  Schema::table('users', function(Blueprint $t): void {
   $t->dropColumn(['job_title','profile_photo_path','profile_photo_mime']);
  });
 }
};