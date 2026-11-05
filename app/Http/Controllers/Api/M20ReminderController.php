<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class M20ReminderController extends Controller
{
    private function scope(Request $request)
    {
        return DB::table('m20_reminders')
            ->where('company_id', $request->attributes->get('company')->id)
            ->where('owner_user_id', $request->user()->id);
    }

    private function record(Request $request, int $id, bool $locked = false): object
    {
        $query = $this->scope($request)->where('id', $id);
        return ($locked ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function details(object $row): array
    {
        return [
            'id' => $row->id, 'company_id' => $row->company_id,
            'owner_user_id' => $row->owner_user_id,
            'title' => $row->title, 'type' => $row->type,
            'description' => $row->description, 'notes' => $row->private_notes,
            'related_type' => $row->related_type, 'related_id' => $row->related_id,
            'due_at' => $row->due_at, 'follow_up_at' => $row->follow_up_at,
            'timezone' => $row->timezone, 'priority' => $row->priority,
            'send_notification' => (bool) $row->send_notification,
            'status' => $row->status, 'completed_at' => $row->completed_at,
            'lock_version' => $row->lock_version, 'created_at' => $row->created_at,
        ];
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status, ['Cache-Control' => 'private, no-store']);
    }

    private function input(Request $request): array
    {
        $values = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:160'],
            'type' => ['required', Rule::in(['invoice', 'job', 'client', 'document', 'equipment', 'other'])],
            'description' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'related_type' => ['nullable', Rule::in(['invoice', 'job', 'client', 'document', 'equipment'])],
            'related_id' => ['nullable', 'integer', 'min:1'],
            'due_at' => ['required', 'date'],
            'follow_up_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'timezone'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'send_notification' => ['required', 'boolean'],
        ]);
        $referenceType = $values['related_type'] ?? null;
        $referenceId = $values['related_id'] ?? null;
        if (($referenceType === null) !== ($referenceId === null)
            || ($referenceType && $values['type'] !== $referenceType)) {
            throw ValidationException::withMessages(['related_type' => 'The related item must match the reminder type.']);
        }
        if ($referenceType) {
            $tables = [
                'invoice' => 'service_invoices', 'job' => 'service_jobs',
                'client' => 'clients', 'document' => 'managed_documents',
                'equipment' => 'equipment_loans',
            ];
            if (!DB::table($tables[$referenceType])
                ->where('company_id', $request->attributes->get('company')->id)
                ->where('id', $referenceId)->exists()) {
                throw ValidationException::withMessages(['related_id' => 'Related item is not part of this company.']);
            }
        }
        return [
            'title' => trim($values['title']), 'type' => $values['type'],
            'description' => $values['description'] ?? null,
            'private_notes' => $values['notes'] ?? null,
            'related_type' => $referenceType, 'related_id' => $referenceId,
            'due_at' => \Illuminate\Support\Carbon::parse($values['due_at'])->utc()->toDateTimeString(),
            'follow_up_at' => isset($values['follow_up_at'])
                ? \Illuminate\Support\Carbon::parse($values['follow_up_at'])->utc()->toDateTimeString() : null,
            'timezone' => $values['timezone'] ?? 'UTC',
            'priority' => $values['priority'] ?? 'normal',
            'send_notification' => $values['send_notification'],
        ];
    }

    private function audit(Request $request, int $id, string $action, array $changes = []): void
    {
        DB::table('m20_reminder_events')->insert([
            'company_id' => $request->attributes->get('company')->id,
            'reminder_id' => $id, 'actor_user_id' => $request->user()->id,
            'action' => $action, 'changes' => json_encode($changes), 'created_at' => now(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'completed', 'archived'])],
            'type' => ['nullable', Rule::in(['invoice', 'job', 'client', 'document', 'equipment', 'other'])],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = $this->scope($request)->where('status', '<>', 'archived');
        if (isset($filters['status'])) {
            $query = $this->scope($request)->where('status', $filters['status']);
        }
        if (isset($filters['type'])) $query->where('type', $filters['type']);
        if (isset($filters['q'])) $query->where('title', 'like', '%'.$filters['q'].'%');
        $page = $query->orderBy('due_at')->paginate($filters['per_page'] ?? 50);
        return $this->response([
            'records' => array_map(fn ($row) => $this->details($row), $page->items()),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function show(Request $request, int $reminder): JsonResponse
    {
        $row = $this->record($request, $reminder);
        return $this->response(['reminder' => $this->details($row),
            'history' => DB::table('m20_reminder_events')
                ->where('company_id', $row->company_id)->where('reminder_id', $row->id)
                ->orderByDesc('id')->limit(100)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $values = $this->input($request);
        $id = DB::transaction(function () use ($request, $values): int {
            $id = DB::table('m20_reminders')->insertGetId($values + [
                'company_id' => $request->attributes->get('company')->id,
                'owner_user_id' => $request->user()->id, 'created_by' => $request->user()->id,
                'status' => 'active', 'lock_version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, $id, 'created');
            return $id;
        });
        return $this->response(['reminder' => $this->details($this->record($request, $id))], 201);
    }

    public function update(Request $request, int $reminder): JsonResponse
    {
        $version = $request->validate(['lock_version' => ['required', 'integer', 'min:1']])['lock_version'];
        $values = $this->input($request);
        DB::transaction(function () use ($request, $reminder, $version, $values): void {
            $row = $this->record($request, $reminder, true);
            abort_if($row->status === 'archived', 409, 'Archived reminders cannot be changed.');
            abort_if((int) $row->lock_version !== (int) $version, 409, 'Reminder changed. Refresh and retry.');
            $clearNotification = $row->due_at !== $values['due_at']
                || (bool) $row->send_notification !== (bool) $values['send_notification'];
            DB::table('m20_reminders')->where('id', $reminder)->update($values + [
                'lock_version' => $version + 1, 'updated_at' => now(),
                'notified_at' => $clearNotification ? null : $row->notified_at,
                'follow_up_notified_at' => $row->follow_up_at !== $values['follow_up_at']
                    ? null : $row->follow_up_notified_at,
            ]);
            $this->audit($request, $reminder, 'updated', ['version' => $version + 1]);
        });
        return $this->response(['reminder' => $this->details($this->record($request, $reminder))]);
    }

    public function status(Request $request, int $reminder): JsonResponse
    {
        $values = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['complete', 'reopen', 'archive', 'snooze'])],
            'due_at' => ['required_if:action,snooze', 'nullable', 'date', 'after:now'],
        ]);
        DB::transaction(function () use ($request, $reminder, $values): void {
            $row = $this->record($request, $reminder, true);
            abort_if((int) $row->lock_version !== (int) $values['lock_version'], 409, 'Reminder changed. Refresh and retry.');
            abort_if($row->status === 'archived', 409, 'Archived reminders cannot be reopened.');
            $action = $values['action'];
            $changes = [
                'lock_version' => (int) $row->lock_version + 1,
                'updated_at' => now(),
            ];
            if ($action === 'complete') {
                $changes['status'] = 'completed'; $changes['completed_at'] = now();
            } elseif ($action === 'reopen') {
                $changes['status'] = 'active'; $changes['completed_at'] = null;
            } elseif ($action === 'archive') {
                $changes['status'] = 'archived';
            } else {
                $changes['due_at'] = \Illuminate\Support\Carbon::parse($values['due_at'])->utc()->toDateTimeString();
                $changes['notified_at'] = null;
            }
            DB::table('m20_reminders')->where('id', $reminder)->update($changes);
            $this->audit($request, $reminder, $action);
        });
        return $this->response(['reminder' => $this->details($this->record($request, $reminder))]);
    }
}