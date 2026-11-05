<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * M20: scheduled inbox-event creation, independent of Firebase and M19 email.
 *
 * This service never performs network calls or sends messages. Every event is
 * idempotently generated from authoritative, current company and user state.
 */
final class M20NotificationScheduler
{
    public function __construct(
        private readonly M20DueReminders $due,
        private readonly M20BusinessNotifications $business,
    ) {}

    public function sync(int $limit = 80): array
    {
        $limit = max(1, min(100, $limit));
        $recipients = DB::table('m20_reminders as r')
            ->join('companies as c', 'c.id', '=', 'r.company_id')
            ->join('users as u', 'u.id', '=', 'r.owner_user_id')
            ->join('company_user as cu', function ($join): void {
                $join->on('cu.company_id', '=', 'r.company_id')
                    ->on('cu.user_id', '=', 'r.owner_user_id');
            })
            ->leftJoin('m20_notification_preferences as p', function ($join): void {
                $join->on('p.company_id', '=', 'r.company_id')
                    ->on('p.user_id', '=', 'r.owner_user_id');
            })
            ->where('c.status', 'active')
            ->where('u.is_active', true)
            ->where('cu.status', 'active')
            ->where('r.status', 'active')
            ->where('r.send_notification', true)
            ->where(function ($q): void {
                $q->where(function ($due): void {
                    $due->whereNotNull('r.due_at')->where('r.due_at', '<=', now())
                        ->whereNull('r.notified_at');
                })->orWhere(function ($follow): void {
                    $follow->whereNotNull('r.follow_up_at')
                        ->where('r.follow_up_at', '<=', now())
                        ->whereNull('r.follow_up_notified_at');
                });
            })
            ->where(function ($q): void {
                // Missing preferences mean the Stage 1 default: inbox enabled.
                $q->whereNull('p.id')->orWhere('p.in_app_enabled', true)
                    ->orWhere('p.push_enabled', true);
            })
            ->select(['r.company_id', 'r.owner_user_id'])
            ->distinct()
            ->orderBy('r.company_id')->orderBy('r.owner_user_id')
            ->limit($limit)->get();

        $created = 0;
        foreach ($recipients as $recipient) {
            $created += $this->due->syncForUser(
                (int) $recipient->company_id, (int) $recipient->owner_user_id,
            );
        }

        // Independently derived from paid/unpaid invoices, active equipment
        // loans and completed signatures. No email, SMS or Firebase send.
        $business = $this->business->sync($limit);
        return [
            'recipients' => $recipients->count(),
            'reminder_events' => $created,
            'business_events' => $business,
        ];
    }
}
