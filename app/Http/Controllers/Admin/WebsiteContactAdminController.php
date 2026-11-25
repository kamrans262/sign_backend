<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WebsiteContactAdminController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['new', 'in_progress', 'resolved'])],
        ]);
        $query = DB::table('website_contact_messages');
        if (!empty($filters['q'])) {
            $needle = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $needle)
                ->orWhere('email', 'like', $needle)
                ->orWhere('subject', 'like', $needle)
                ->orWhere('reference', 'like', $needle));
        }
        if (!empty($filters['status'])) $query->where('status', $filters['status']);

        return view('admin.support.contact-index', [
            'messages' => $query->orderByDesc('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(int $message): View
    {
        $item = DB::table('website_contact_messages')->find($message);
        abort_unless($item, 404);
        return view('admin.support.contact-show', compact('item'));
    }

    public function update(Request $request, int $message): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'in_progress', 'resolved'])]]);
        DB::transaction(function () use ($request, $message, $data): void {
            $row = DB::table('website_contact_messages')->where('id', $message)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('website_contact_messages')->where('id', $message)->update([
                'status' => $data['status'], 'updated_at' => now(),
            ]);
            AuditLogger::record('admin.website_contact.status_updated', $request->user(),
                metadata: ['message_id' => $message, 'status' => $data['status']], request: $request);
        });
        return back()->with('success', 'Contact message updated.');
    }

    public function destroy(Request $request, int $message): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        DB::transaction(function () use ($request, $message): void {
            abort_unless(DB::table('website_contact_messages')->where('id', $message)->delete(), 404);
            AuditLogger::record('admin.website_contact.deleted', $request->user(),
                metadata: ['message_id' => $message], request: $request);
        });
        return redirect()->route('admin.support.contacts.index')->with('success', 'Contact message deleted.');
    }
}
