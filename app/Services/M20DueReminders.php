<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class M20DueReminders
{
    public function __construct(private readonly M20NotificationTemplates $templates) {}

    /**
     * Bounded in-app/outbox event creation. No SMTP or Firebase is run
     * here. A row lock and unique event key prevent duplicate inbox entries.
     */
    public function syncForUser(int $companyId, int $userId): int
    {
        $prefs = DB::table('m20_notification_preferences')
            ->where('company_id', $companyId)->where('user_id', $userId)->first();
        if ($prefs && !$prefs->in_app_enabled && !$prefs->push_enabled) return 0;
        $locale = $prefs?->locale ?? 'en';
        $created = 0;
        foreach ([
            ['due_at', 'notified_at', 'reminder.due'],
            ['follow_up_at', 'follow_up_notified_at', 'reminder.follow_up'],
        ] as [$dateColumn, $sentColumn, $key]) {
            $ids = DB::table('m20_reminders')
                ->where('company_id', $companyId)->where('owner_user_id', $userId)
                ->where('status', 'active')->where('send_notification', true)
                ->whereNull($sentColumn)->whereNotNull($dateColumn)
                ->where($dateColumn, '<=', now())
                ->orderBy('id')->limit(100)->pluck('id');
            foreach ($ids as $id) {
                DB::transaction(function () use (
                    $companyId, $userId, $id, $dateColumn,
                    $sentColumn, $key, $locale, &$created
                ): void {
                    $reminder = DB::table('m20_reminders')
                        ->where('company_id', $companyId)->where('owner_user_id', $userId)
                        ->where('id', $id)->lockForUpdate()->first();
                    if (!$reminder || $reminder->status !== 'active'
                        || !$reminder->send_notification || $reminder->{$sentColumn}
                        || !$reminder->{$dateColumn}
                        || $reminder->{$dateColumn} > now()->toDateTimeString()) return;
                    $copy = $this->templates->render($key, 'in_app', $locale, [
                        'reminder_title' => $reminder->title,
                        'due_date' => $reminder->due_at,
                        'follow_up_date' => $reminder->follow_up_at,
                    ]);
                    if (!$copy) return;
                    $inserted = DB::table('m20_notifications')->insertOrIgnore([
                        'company_id' => $companyId, 'recipient_user_id' => $userId,
                        'reminder_id' => $id, 'template_revision_id' => $copy['revision_id'],
                        'event_key' => $key.'.'.$id.'.'.$reminder->lock_version,
                        'category' => 'reminder', 'title' => $copy['title'],
                        'body' => $copy['body'], 'link_type' => 'reminder', 'link_id' => $id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('m20_reminders')->where('id', $id)->update([
                        $sentColumn => now(), 'updated_at' => now(),
                    ]);
                    $created += $inserted;
                });
            }
        }
        return $created;
    }
}