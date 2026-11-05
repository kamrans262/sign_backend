<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class M20PushDispatcher
{
    public function __construct(
        private readonly M20FcmClient $fcm,
        private readonly M20NotificationTemplates $templates,
        private readonly M20DueReminders $due,
    ) {}

    /** Finite company/user buckets. A scheduler is not allowed to send unless enabled. */
    public function process(int $limit = 40, bool $send = false): array
    {
        $limit = max(1, min(100, $limit));
        if (!$send || !$this->fcm->available()) {
            return ['enabled' => $this->fcm->available(), 'sent' => 0,
                'retried' => 0, 'invalid' => 0, 'queued' => 0, 'unknown' => 0];
        }
        $recipients = DB::table('m20_reminders as r')
            ->join('m20_notification_preferences as p', function ($join): void {
                $join->on('r.company_id', '=', 'p.company_id')
                    ->on('r.owner_user_id', '=', 'p.user_id');
            })
            ->where('r.status', 'active')->where('r.send_notification', true)
            ->where(function ($q): void {
                $q->where(fn ($due) => $due->whereNotNull('r.due_at')->where('r.due_at', '<=', now())
                    ->whereNull('r.notified_at'))
                  ->orWhere(fn ($follow) => $follow->whereNotNull('r.follow_up_at')
                    ->where('r.follow_up_at', '<=', now())->whereNull('r.follow_up_notified_at'));
            })
            ->where(fn ($q) => $q->where('p.in_app_enabled', true)->orWhere('p.push_enabled', true))
            ->select(['r.company_id', 'r.owner_user_id'])->distinct()
            ->orderBy('r.company_id')->orderBy('r.owner_user_id')->limit(100)->get();
        foreach ($recipients as $recipient) {
            $this->due->syncForUser($recipient->company_id, $recipient->owner_user_id);
        }
        $pending = DB::table('m20_notifications as n')
            ->join('m20_notification_preferences as p', function ($join): void {
                $join->on('n.company_id', '=', 'p.company_id')
                    ->on('n.recipient_user_id', '=', 'p.user_id');
            })
            ->where('p.push_enabled', true)
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('m20_push_devices as d')
                    ->whereColumn('d.company_id', 'n.company_id')
                    ->whereColumn('d.user_id', 'n.recipient_user_id')
                    ->where('d.enabled', true)
                    ->whereNotExists(function ($existing): void {
                        $existing->selectRaw('1')->from('m20_push_deliveries as pd')
                            ->whereColumn('pd.notification_id', 'n.id')
                            ->whereColumn('pd.device_id', 'd.id');
                    });
            })
            ->orderBy('n.id')->limit(100)->get([
                'n.id', 'n.company_id', 'n.recipient_user_id', 'n.event_key', 'p.locale',
            ]);
        $queued = 0;
        foreach ($pending as $notification) {
            $key = implode('.', array_slice(explode('.', $notification->event_key), 0, 2));
            // The published push variant is deliberately generic: no private
            // notes, borrower data or invoice amounts reach the lock screen.
            $copy = $this->templates->render($key, 'push', $notification->locale, []);
            if (!$copy) continue;
            $devices = DB::table('m20_push_devices')
                ->where('company_id', $notification->company_id)
                ->where('user_id', $notification->recipient_user_id)
                ->where('enabled', true)->limit(12)->pluck('id');
            foreach ($devices as $deviceId) {
                $queued += DB::table('m20_push_deliveries')->insertOrIgnore([
                    'notification_id' => $notification->id, 'device_id' => $deviceId,
                    'template_revision_id' => $copy['revision_id'],
                    'title' => $copy['title'], 'body' => $copy['body'],
                    'status' => 'pending', 'attempts' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $stats = ['enabled' => true, 'sent' => 0, 'retried' => 0, 'invalid' => 0, 'queued' => $queued, 'unknown' => 0];
        $ids = DB::table('m20_push_deliveries')->where(function ($q): void {
                $q->whereIn('status', ['pending', 'retry'])
                    ->where(fn ($due) => $due->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now()));
            })
            ->orderBy('id')->limit($limit)->pluck('id');
        // A lost HTTP result is not proof of failure; do not automatically
        // replay expired in-flight messages (which might already be delivered).
        DB::table('m20_push_deliveries')->where('status', 'sending')
            ->whereNotNull('locked_until')->where('locked_until', '<=', now())
            ->update(['status' => 'unknown', 'locked_until' => null,
                'failure_code' => 'OUTCOME_UNKNOWN', 'updated_at' => now()]);
        foreach ($ids as $id) {
            $claim = DB::transaction(function () use ($id): ?object {
                $row = DB::table('m20_push_deliveries')->where('id', $id)
                    ->lockForUpdate()->first();
                if (!$row || $row->attempts >= 5
                    || !in_array($row->status, ['pending', 'retry'], true)
                    || ($row->next_attempt_at && $row->next_attempt_at > now()->toDateTimeString()
                        )) return null;
                $notification = DB::table('m20_notifications')->where('id', $row->notification_id)->first();
                $device = DB::table('m20_push_devices')->where('id', $row->device_id)->first();
                $pref = $notification ? DB::table('m20_notification_preferences')
                    ->where('company_id', $notification->company_id)
                    ->where('user_id', $notification->recipient_user_id)->first() : null;
                $reminder = $notification?->reminder_id
                    ? DB::table('m20_reminders')->where('id', $notification->reminder_id)->first()
                    : null;
                $parts = $notification ? explode('.', $notification->event_key) : [];
                $when = ($parts[1] ?? '') === 'follow_up'
                    ? $reminder?->follow_up_at : $reminder?->due_at;
                $staleReminder = $notification?->reminder_id &&
                    (!$reminder || $reminder->status !== 'active'
                    || !$reminder->send_notification
                    || $reminder->company_id !== $notification->company_id
                    || $reminder->owner_user_id !== $notification->recipient_user_id
                    || (int) ($parts[3] ?? 0) !== (int) $reminder->lock_version
                    || !$when || $when > now()->toDateTimeString());
                if (!$notification || !$device || !$device->enabled || !$pref || !$pref->push_enabled
                    || $device->company_id !== $notification->company_id
                    || $device->user_id !== $notification->recipient_user_id
                    || $staleReminder) {
                    DB::table('m20_push_deliveries')->where('id', $id)->update([
                        'status' => 'revoked', 'locked_until' => null, 'updated_at' => now(),
                    ]);
                    return null;
                }
                DB::table('m20_push_deliveries')->where('id', $id)->update([
                    'status' => 'sending', 'attempts' => $row->attempts + 1,
                    'locked_until' => now()->addMinutes(2), 'attempted_at' => now(), 'updated_at' => now(),
                ]);
                return (object) ['row' => $row, 'notification' => $notification, 'device' => $device];
            });
            if (!$claim) continue;
            try {
                $result = $this->fcm->send(
                    Crypt::decryptString($claim->device->encrypted_fid),
                    $claim->row->title, $claim->row->body, [
                        'notification_id' => (string) $claim->notification->id,
                        'company_id' => (string) $claim->notification->company_id,
                        'event_key' => $claim->notification->event_key,
                        'link_type' => (string) ($claim->notification->link_type ?? ''),
                        'link_id' => (string) ($claim->notification->link_id ?? ''),
                    ]
                );
            } catch (Throwable) {
                $result = ['ok' => false, 'permanent' => false, 'code' => 'TOKEN_READ_ERROR'];
            }
            $permanent = (bool) ($result['permanent'] ?? false);
            $success = (bool) ($result['ok'] ?? false);
            $attempts = $claim->row->attempts + 1;
            $status = $success ? 'submitted_to_provider' : ($permanent ? 'invalid'
                : (($result['code'] ?? '') === 'TRANSPORT_ERROR' ? 'unknown'
                : ($attempts >= 5 ? 'failed' : 'retry')));
            DB::transaction(function () use ($id, $claim, $result, $status, $attempts, $success, $permanent): void {
                DB::table('m20_push_deliveries')->where('id', $id)->update([
                    'status' => $status,
                    'locked_until' => null,
                    'next_attempt_at' => $status === 'retry'
                        ? now()->addMinutes(min(60, 2 ** $attempts)) : null,
                    'provider_reference' => $success ? ($result['reference'] ?? null) : null,
                    'failure_code' => $success ? null : ($result['code'] ?? 'UNKNOWN'),
                    'updated_at' => now(),
                ]);
                if ($permanent) DB::table('m20_push_devices')
                    ->where('id', $claim->device->id)->update([
                        'enabled' => false, 'revoked_at' => now(), 'updated_at' => now(),
                    ]);
                DB::table('m20_notification_deliveries')->updateOrInsert([
                    'notification_id' => $claim->notification->id, 'channel' => 'push',
                ], [
                    'status' => $success ? 'sent' : $status,
                    'provider_reference' => $success ? ($result['reference'] ?? null) : null,
                    'failure_reason' => $success ? null : ($result['code'] ?? 'UNKNOWN'),
                    'attempted_at' => now(), 'updated_at' => now(),
                ]);
            });
            $stats[$success ? 'sent'
                : ($permanent ? 'invalid' : ($status === 'unknown' ? 'unknown' : 'retried'))]++;
        }
        return $stats;
    }
}
