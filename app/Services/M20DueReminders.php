<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class M20DueReminders
{
    public function __construct(private readonly M20NotificationTemplates $templates) {}

    /** In-app only, no SMTP / Firebase / scheduler activation. */
    public function syncForUser(int $companyId, int $userId): int
    {
        $ids = DB::table('m20_reminders')
            ->where('company_id', $companyId)->where('owner_user_id', $userId)
            ->where('status', 'active')->where('send_notification', true)
            ->whereNull('notified_at')->where('due_at', '<=', now())
            ->orderBy('id')->limit(100)->pluck('id');
        $created = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($companyId, $userId, $id, &$created): void {
                $reminder = DB::table('m20_reminders')
                    ->where('company_id', $companyId)->where('owner_user_id', $userId)
                    ->where('id', $id)->lockForUpdate()->first();
                if (!$reminder || $reminder->status !== 'active'
                    || !$reminder->send_notification || $reminder->notified_at
                    || $reminder->due_at > now()->toDateTimeString()) return;
                $copy = $this->templates->render('reminder.due', 'in_app', 'en', [
                    'reminder_title' => $reminder->title,
                    'due_date' => $reminder->due_at,
                ]);
                if (!$copy) return;
                DB::table('m20_notifications')->insertOrIgnore([
                    'company_id' => $companyId, 'recipient_user_id' => $userId,
                    'reminder_id' => $id, 'template_revision_id' => $copy['revision_id'],
                    'event_key' => 'reminder.due.'.$id.'.'.$reminder->lock_version,
                    'category' => 'reminder', 'title' => $copy['title'],
                    'body' => $copy['body'], 'link_type' => 'reminder', 'link_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('m20_reminders')->where('id', $id)->update([
                    'notified_at' => now(), 'updated_at' => now(),
                ]);
                $created++;
            });
        }
        return $created;
    }
}