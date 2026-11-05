<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('m20_reminders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title', 160);
            $t->string('type', 30);
            $t->text('description')->nullable();
            $t->text('private_notes')->nullable();
            $t->string('related_type', 30)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->dateTime('due_at');
            $t->dateTime('follow_up_at')->nullable();
            $t->string('timezone', 64)->default('UTC');
            $t->string('priority', 12)->default('normal');
            $t->boolean('send_notification')->default(true);
            $t->string('status', 20)->default('active');
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('notified_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->index(['company_id', 'owner_user_id', 'status', 'due_at'], 'm20_reminder_inbox');
            $t->index(['status', 'send_notification', 'due_at'], 'm20_reminder_due');
        });

        Schema::create('m20_reminder_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('reminder_id')->constrained('m20_reminders')->restrictOnDelete();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 40);
            $t->json('changes')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'reminder_id', 'id'], 'm20_reminder_history');
        });

        Schema::create('m20_notification_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('template_key', 90);
            $t->string('category', 30);
            $t->string('channel', 20);
            $t->string('locale', 5);
            $t->json('allowed_variables');
            $t->string('draft_title', 180);
            $t->text('draft_body');
            $t->unsignedInteger('draft_revision')->default(1);
            $t->unsignedBigInteger('published_revision_id')->nullable();
            $t->boolean('enabled')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['template_key', 'channel', 'locale'], 'm20_template_identity');
        });

        Schema::create('m20_notification_template_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->constrained('m20_notification_templates')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('title', 180);
            $t->text('body');
            $t->string('action', 20);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['template_id', 'revision'], 'm20_template_revision_unique');
        });

        Schema::create('m20_notifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reminder_id')->nullable()->constrained('m20_reminders')->restrictOnDelete();
            $t->foreignId('template_revision_id')->nullable()->constrained('m20_notification_template_revisions')->nullOnDelete();
            $t->string('event_key', 90);
            $t->string('category', 30);
            $t->string('title', 180);
            $t->text('body');
            $t->string('link_type', 24)->nullable();
            $t->unsignedBigInteger('link_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'recipient_user_id', 'event_key'], 'm20_notification_idempotent');
            $t->index(['company_id', 'recipient_user_id', 'dismissed_at', 'id'], 'm20_notification_inbox');
        });

        // Delivery attempts are reserved for Stage 2. Stage 1 never queues push/email.
        Schema::create('m20_notification_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('notification_id')->constrained('m20_notifications')->restrictOnDelete();
            $t->string('channel', 20);
            $t->string('status', 30)->default('disabled');
            $t->string('provider_reference', 255)->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'channel'], 'm20_delivery_unique');
        });

        $examples = [
            ['reminder.due', 'reminder', ['reminder_title', 'due_date'],
                'Reminder due: {reminder_title}', 'Your reminder is due on {due_date}.',
                'Rappel : {reminder_title}', 'Votre rappel arrive à échéance le {due_date}.'],
            ['reminder.follow_up', 'reminder', ['reminder_title', 'follow_up_date'],
                'Follow-up reminder', 'Follow up on {reminder_title} by {follow_up_date}.',
                'Rappel de suivi', 'Effectuez le suivi de {reminder_title} avant le {follow_up_date}.'],
            ['invoice.payment_due', 'invoice', ['invoice_number'],
                'Invoice payment reminder', 'Invoice {invoice_number} requires your attention.',
                'Rappel de paiement', 'La facture {invoice_number} nécessite votre attention.'],
            ['equipment.loan_overdue', 'equipment', ['equipment_name'],
                'Equipment return overdue', 'A loan for {equipment_name} is past its expected return date.',
                'Retour de matériel en retard', 'Le prêt de {equipment_name} a dépassé sa date de retour prévue.'],
            ['document.signed', 'document', [],
                'Document signed', 'A signed document is ready for review.',
                'Document signé', 'Un document signé est prêt à être consulté.'],
            ['customer.action_required', 'customer', [],
                'Customer action needed', 'A customer action requires your review.',
                'Action client requise', 'Une action client nécessite votre attention.'],
            ['system.general', 'system', [],
                'Sign2Digital update', 'You have a new update in Sign2Digital.',
                'Actualité Sign2Digital', 'Vous avez une nouvelle notification dans Sign2Digital.'],
        ];
        foreach ($examples as [$key, $category, $vars, $enTitle, $enBody, $frTitle, $frBody]) {
            foreach (['en', 'fr'] as $locale) {
                foreach (['in_app', 'push'] as $channel) {
                    $isFrench = $locale === 'fr';
                    $title = $channel === 'push'
                        ? ($isFrench ? 'Notification Sign2Digital' : 'Sign2Digital reminder')
                        : ($isFrench ? $frTitle : $enTitle);
                    $body = $channel === 'push'
                        ? ($isFrench ? 'Consultez votre application pour les détails.' : 'Open Sign2Digital to view the details.')
                        : ($isFrench ? $frBody : $enBody);
                    $now = now();
                    $templateId = DB::table('m20_notification_templates')->insertGetId([
                        'template_key' => $key, 'category' => $category,
                        'channel' => $channel, 'locale' => $locale,
                        'allowed_variables' => json_encode($vars),
                        'draft_title' => $title, 'draft_body' => $body,
                        'draft_revision' => 1, 'enabled' => true,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $revisionId = DB::table('m20_notification_template_revisions')->insertGetId([
                        'template_id' => $templateId, 'revision' => 1,
                        'title' => $title, 'body' => $body,
                        'action' => 'seed', 'created_at' => $now,
                    ]);
                    DB::table('m20_notification_templates')->where('id', $templateId)->update([
                        'published_revision_id' => $revisionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m20_notification_deliveries');
        Schema::dropIfExists('m20_notifications');
        Schema::dropIfExists('m20_notification_template_revisions');
        Schema::dropIfExists('m20_notification_templates');
        Schema::dropIfExists('m20_reminder_events');
        Schema::dropIfExists('m20_reminders');
    }
};