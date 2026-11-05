<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class M20ReminderAdminController extends Controller
{
    private function reminder(int $id): object
    {
        return DB::table('m20_reminders')->where('id', $id)->firstOrFail();
    }

    private function companies(): array
    {
        return [
            'companies' => Company::query()->where('status', 'active')->orderBy('name')
                ->get(['id', 'name']),
            'users' => DB::table('company_user as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->join('companies as c', 'c.id', '=', 'm.company_id')
                ->where('m.status', 'active')->where('u.is_active', true)
                ->where('c.status', 'active')->orderBy('u.name')
                ->get(['m.company_id', 'u.id', 'u.name', 'u.email'])->take(2000),
        ];
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'completed', 'archived'])],
            'q' => ['nullable', 'string', 'max:160'],
        ]);
        $query = DB::table('m20_reminders as r')
            ->join('companies as c', 'c.id', '=', 'r.company_id')
            ->join('users as u', 'u.id', '=', 'r.owner_user_id')
            ->select('r.*', 'c.name as company_name', 'u.name as recipient_name');
        if (!empty($filters['company_id'])) $query->where('r.company_id', $filters['company_id']);
        if (!empty($filters['status'])) $query->where('r.status', $filters['status']);
        if (!empty($filters['q'])) $query->where('r.title', 'like', '%'.$filters['q'].'%');
        return view('admin.notifications.reminders-index', array_merge($this->companies(), [
            'filters' => $filters, 'reminders' => $query->orderByDesc('r.id')->paginate(30)->withQueryString(),
        ]));
    }

    public function create()
    {
        return view('admin.notifications.reminder-form', array_merge($this->companies(), [
            'record' => null, 'events' => collect(),
        ]));
    }

    public function edit(int $reminder)
    {
        return view('admin.notifications.reminder-form', array_merge($this->companies(), [
            'record' => $this->reminder($reminder),
            'events' => DB::table('m20_reminder_events')->where('reminder_id', $reminder)
                ->orderByDesc('id')->limit(50)->get(),
        ]));
    }

    private function fields(Request $request): array
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['invoice', 'job', 'client', 'document', 'equipment', 'other'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'private_notes' => ['nullable', 'string', 'max:10000'],
            'related_id' => ['nullable', 'integer', 'min:1'],
            'due_at' => ['required', 'date'],
            'follow_up_at' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'send_notification' => ['required', 'boolean'],
        ]);
        $company = Company::query()->where('status', 'active')->findOrFail($validated['company_id']);
        if (!$company->users()->where('users.id', $validated['owner_user_id'])
            ->wherePivot('status', 'active')->where('users.is_active', true)->exists()) {
            throw ValidationException::withMessages(['owner_user_id' => 'Select an active member of this company.']);
        }
        $relatedId = $validated['related_id'] ?? null;
        $type = $validated['type'];
        if ($relatedId !== null) {
            if ($type === 'other') {
                throw ValidationException::withMessages(['related_id' => 'Custom reminders cannot link an unrelated record.']);
            }
            $tables = [
                'invoice' => 'service_invoices', 'job' => 'service_jobs',
                'client' => 'clients', 'document' => 'managed_documents',
                'equipment' => 'equipment_loans',
            ];
            if (!DB::table($tables[$type])->where('company_id', $company->id)->where('id', $relatedId)->exists()) {
                throw ValidationException::withMessages(['related_id' => 'Related record not found in this company.']);
            }
        }
        return [
            'company_id' => $company->id, 'owner_user_id' => $validated['owner_user_id'],
            'title' => trim($validated['title']), 'type' => $type,
            'description' => $validated['description'] ?? null,
            'private_notes' => $validated['private_notes'] ?? null,
            'related_type' => $relatedId === null ? null : $type,
            'related_id' => $relatedId,
            'due_at' => Carbon::parse($validated['due_at'], 'UTC')->utc()->toDateTimeString(),
            'follow_up_at' => empty($validated['follow_up_at'])
                ? null : Carbon::parse($validated['follow_up_at'], 'UTC')->utc()->toDateTimeString(),
            'priority' => $validated['priority'],
            'send_notification' => (bool) $validated['send_notification'],
            'timezone' => 'UTC',
        ];
    }

    private function event(Request $request, int $id, int $companyId, string $action): void
    {
        DB::table('m20_reminder_events')->insert([
            'company_id' => $companyId, 'reminder_id' => $id,
            'actor_user_id' => $request->user()->id, 'action' => 'admin.'.$action,
            'changes' => json_encode(['actor' => 'platform_admin']),
            'created_at' => now(),
        ]);
    }

    public function store(Request $request)
    {
        $fields = $this->fields($request);
        $id = DB::transaction(function () use ($fields, $request): int {
            $id = DB::table('m20_reminders')->insertGetId($fields + [
                'created_by' => $request->user()->id, 'status' => 'active',
                'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->event($request, $id, $fields['company_id'], 'created');
            return $id;
        });
        return redirect()->route('admin.reminders.edit', $id)->with('success', 'Reminder created.');
    }

    public function update(Request $request, int $reminder)
    {
        $values = $this->fields($request);
        $version = $request->validate(['lock_version' => ['required', 'integer', 'min:1']])['lock_version'];
        DB::transaction(function () use ($request, $reminder, $values, $version): void {
            $original = DB::table('m20_reminders')->where('id', $reminder)->lockForUpdate()->firstOrFail();
            abort_if($original->status === 'archived', 409, 'Archived reminder cannot be changed.');
            abort_if((int) $original->lock_version !== (int) $version, 409, 'This reminder has changed. Reload before saving.');
            DB::table('m20_reminders')->where('id', $reminder)->update($values + [
                'lock_version' => $version + 1, 'notified_at' => null,
                'updated_at' => now(),
            ]);
            $this->event($request, $reminder, $values['company_id'], 'updated');
        });
        return redirect()->route('admin.reminders.edit', $reminder)->with('success', 'Reminder updated.');
    }

    public function status(Request $request, int $reminder)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['complete', 'reopen', 'archive'])],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($request, $reminder, $data): void {
            $row = DB::table('m20_reminders')->where('id', $reminder)->lockForUpdate()->firstOrFail();
            abort_if((int) $row->lock_version !== (int) $data['lock_version'], 409, 'This reminder has changed.');
            abort_if($row->status === 'archived', 409, 'Archived reminders are immutable.');
            $update = ['lock_version' => $row->lock_version + 1, 'updated_at' => now()];
            if ($data['action'] === 'complete') {
                $update['status'] = 'completed'; $update['completed_at'] = now();
            } elseif ($data['action'] === 'reopen') {
                $update['status'] = 'active'; $update['completed_at'] = null;
            } else $update['status'] = 'archived';
            DB::table('m20_reminders')->where('id', $reminder)->update($update);
            $this->event($request, $reminder, $row->company_id, $data['action']);
        });
        return redirect()->route('admin.reminders.edit', $reminder)->with('success', 'Reminder status changed.');
    }

    public function inbox(Request $request)
    {
        $filters = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:30'],
            'delivery_status' => ['nullable', 'in:pending,retry,sending,submitted,failed,unknown,invalid,revoked'],
        ]);
        $query = DB::table('m20_notifications as n')
            ->join('companies as c', 'c.id', '=', 'n.company_id')
            ->join('users as u', 'u.id', '=', 'n.recipient_user_id')
            ->select('n.id', 'n.company_id', 'c.name as company_name',
                'u.name as recipient_name', 'n.category', 'n.title',
                'n.read_at', 'n.dismissed_at', 'n.created_at', 'n.event_key');
        if (isset($filters['company_id'])) $query->where('n.company_id', $filters['company_id']);
        if (isset($filters['category'])) $query->where('n.category', $filters['category']);
        $push = DB::table('m20_push_deliveries as pd')
            ->join('m20_notifications as n', 'n.id', '=', 'pd.notification_id')
            ->join('companies as c', 'c.id', '=', 'n.company_id')
            ->join('users as u', 'u.id', '=', 'n.recipient_user_id')
            ->join('m20_push_devices as d', 'd.id', '=', 'pd.device_id')
            ->select('pd.id', 'pd.status', 'pd.attempts', 'pd.failure_code',
                'pd.provider_reference', 'pd.template_revision_id',
                'pd.created_at', 'pd.attempted_at', 'n.event_key', 'n.category',
                'c.name as company_name', 'u.name as recipient_name',
                'd.platform', 'd.enabled as device_active');
        if (isset($filters['company_id'])) $push->where('n.company_id', $filters['company_id']);
        if (isset($filters['category'])) $push->where('n.category', $filters['category']);
        if (isset($filters['delivery_status'])) $push->where('pd.status', $filters['delivery_status']);
        $pushStats = DB::table('m20_push_deliveries')->select('status')
            ->selectRaw('count(*) as count')->groupBy('status')->pluck('count', 'status');
        return view('admin.notifications.inbox', array_merge($this->companies(), [
            'filters' => $filters,
            'notifications' => $query->orderByDesc('n.id')->paginate(40)->withQueryString(),
            'deliveryStats' => DB::table('m20_notification_deliveries')->select('status')
                ->selectRaw('count(*) as count')->groupBy('status')->pluck('count', 'status'),
            'pushStats' => $pushStats,
            'pushDeliveries' => $push->orderByDesc('pd.id')->paginate(30, ['*'], 'push_page')
                ->withQueryString(),
        ]));
    }
}