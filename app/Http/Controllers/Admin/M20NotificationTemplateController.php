<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\M20NotificationTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class M20NotificationTemplateController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:90'],
            'channel' => ['nullable', 'in:in_app,push,email'],
            'locale' => ['nullable', 'in:en,fr'],
        ]);
        $query = DB::table('m20_notification_templates');
        if (isset($filters['q'])) $query->where('template_key', 'like', '%'.$filters['q'].'%');
        if (isset($filters['channel'])) $query->where('channel', $filters['channel']);
        if (isset($filters['locale'])) $query->where('locale', $filters['locale']);
        return view('admin.notifications.templates', [
            'templates' => $query->orderBy('template_key')->orderBy('channel')
                ->orderBy('locale')->paginate(30)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    private function template(int $template): object
    {
        return DB::table('m20_notification_templates')->where('id', $template)->firstOrFail();
    }

    public function edit(int $template)
    {
        $record = $this->template($template);
        return view('admin.notifications.template-edit', [
            'record' => $record,
            'revisions' => DB::table('m20_notification_template_revisions')
                ->where('template_id', $template)->orderByDesc('revision')->limit(50)->get(),
            'allowed' => json_decode((string) $record->allowed_variables, true) ?: [],
            'samples' => [
                'reminder_title' => 'Follow up with customer',
                'due_date' => '2026-11-10 09:00', 'follow_up_date' => '2026-11-12',
                'invoice_number' => 'INV-2026-0001',
                'equipment_name' => 'Equipment A',
                'company_name' => 'Example Company',
            ],
        ]);
    }

    public function draft(Request $request, int $template, M20NotificationTemplates $service)
    {
        $data = $request->validate([
            'draft_title' => ['required', 'string', 'max:180'],
            'draft_body' => ['required', 'string', 'max:1000'],
            'draft_revision' => ['required', 'integer', 'min:1'],
        ]);
        $record = $this->template($template);
        $service->validateCopy($record, $data['draft_title'], $data['draft_body']);
        DB::transaction(function () use ($request, $template, $data): void {
            $current = DB::table('m20_notification_templates')->where('id', $template)
                ->lockForUpdate()->firstOrFail();
            abort_if($current->draft_revision !== (int) $data['draft_revision'], 409, 'Template changed. Refresh first.');
            $revision = $current->draft_revision + 1;
            DB::table('m20_notification_template_revisions')->insert([
                'template_id' => $template, 'revision' => $revision,
                'title' => $data['draft_title'], 'body' => $data['draft_body'],
                'action' => 'draft', 'actor_user_id' => $request->user()->id, 'created_at' => now(),
            ]);
            DB::table('m20_notification_templates')->where('id', $template)->update([
                'draft_title' => $data['draft_title'], 'draft_body' => $data['draft_body'],
                'draft_revision' => $revision, 'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
        });
        return redirect()->route('admin.notifications.edit', $template)->with('success', 'Draft saved. Previously published copy is unchanged.');
    }

    public function publish(Request $request, int $template, M20NotificationTemplates $service)
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $template, $service): void {
            $record = DB::table('m20_notification_templates')->where('id', $template)
                ->lockForUpdate()->firstOrFail();
            $service->validateCopy($record, $record->draft_title, $record->draft_body);
            $revision = DB::table('m20_notification_template_revisions')
                ->where('template_id', $template)->where('revision', $record->draft_revision)->firstOrFail();
            DB::table('m20_notification_templates')->where('id', $template)->update([
                'published_revision_id' => $revision->id, 'enabled' => true,
                'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            // Publish audit revision without changing immutable content snapshots.
            DB::table('m20_notification_template_revisions')->insert([
                'template_id' => $template, 'revision' => $record->draft_revision + 1,
                'title' => $record->draft_title, 'body' => $record->draft_body,
                'action' => 'publish', 'actor_user_id' => $request->user()->id, 'created_at' => now(),
            ]);
            DB::table('m20_notification_templates')->where('id', $template)->update([
                'draft_revision' => $record->draft_revision + 1,
            ]);
        });
        return redirect()->route('admin.notifications.edit', $template)->with('success', 'Template published for future notifications.');
    }

    public function toggle(Request $request, int $template)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $template, $data): void {
            $record = DB::table('m20_notification_templates')->where('id', $template)
                ->lockForUpdate()->firstOrFail();
            $revision = $record->draft_revision + 1;
            DB::table('m20_notification_template_revisions')->insert([
                'template_id' => $template, 'revision' => $revision,
                'title' => $record->draft_title, 'body' => $record->draft_body,
                'action' => $data['enabled'] ? 'enable' : 'disable',
                'actor_user_id' => $request->user()->id, 'created_at' => now(),
            ]);
            DB::table('m20_notification_templates')->where('id', $template)->update([
                'enabled' => $data['enabled'], 'draft_revision' => $revision,
                'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
        });
        return redirect()->route('admin.notifications.edit', $template)->with('success', 'Template status updated.');
    }

    public function restore(Request $request, int $template)
    {
        $values = $request->validate(['revision_id' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $template, $values): void {
            $record = DB::table('m20_notification_templates')->where('id', $template)
                ->lockForUpdate()->firstOrFail();
            $prior = DB::table('m20_notification_template_revisions')
                ->where('template_id', $template)->where('id', $values['revision_id'])->firstOrFail();
            $revision = $record->draft_revision + 1;
            DB::table('m20_notification_template_revisions')->insert([
                'template_id' => $template, 'revision' => $revision,
                'title' => $prior->title, 'body' => $prior->body,
                'action' => 'restore', 'actor_user_id' => $request->user()->id, 'created_at' => now(),
            ]);
            DB::table('m20_notification_templates')->where('id', $template)->update([
                'draft_title' => $prior->title, 'draft_body' => $prior->body,
                'draft_revision' => $revision, 'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
        });
        return redirect()->route('admin.notifications.edit', $template)->with('success', 'Earlier text restored as a draft. Publish to activate.');
    }
}