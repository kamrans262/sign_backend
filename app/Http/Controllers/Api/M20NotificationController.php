<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\M20DueReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class M20NotificationController extends Controller
{
    private function scope(Request $request)
    {
        return DB::table('m20_notifications')
            ->where('company_id', $request->attributes->get('company')->id)
            ->where('recipient_user_id', $request->user()->id);
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request, M20DueReminders $due): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'unread_only' => ['nullable', 'boolean'],
        ]);
        // Safe on-demand sync, stage 1 has no cron, SMS, email or FCM.
        $due->syncForUser($request->attributes->get('company')->id, $request->user()->id);
        $query = $this->scope($request)->whereNull('dismissed_at');
        if ($filters['unread_only'] ?? false) $query->whereNull('read_at');
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 50);
        return $this->response([
            'records' => $page->items(), 'total' => $page->total(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'unread_count' => $this->scope($request)->whereNull('dismissed_at')->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        abort_unless($this->scope($request)->where('id', $notification)
            ->whereNull('dismissed_at')->exists(), 404);
        $this->scope($request)->where('id', $notification)->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        return $this->response(['updated' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->scope($request)->whereNull('dismissed_at')->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
        return $this->response(['updated' => $count]);
    }

    public function dismiss(Request $request, int $notification): JsonResponse
    {
        abort_unless($this->scope($request)->where('id', $notification)->exists(), 404);
        $this->scope($request)->where('id', $notification)->whereNull('dismissed_at')
            ->update(['dismissed_at' => now(), 'updated_at' => now()]);
        return $this->response(['dismissed' => true]);
    }
}