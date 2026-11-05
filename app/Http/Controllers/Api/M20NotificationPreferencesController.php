<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class M20NotificationPreferencesController extends Controller
{
    private function scope(Request $request)
    {
        return DB::table('m20_notification_preferences')
            ->where('company_id', $request->attributes->get('company')->id)
            ->where('user_id', $request->user()->id);
    }

    private function current(Request $request): array
    {
        $row = $this->scope($request)->first();
        return [
            'in_app_enabled' => $row ? (bool) $row->in_app_enabled : true,
            'push_enabled' => false,
            'push_available' => false,
            'locale' => $row?->locale ?? 'en',
            'timezone' => $row?->timezone ?? 'UTC',
        ];
    }

    private function response(array $data): JsonResponse
    {
        return response()->json(['data' => ['preferences' => $data]], 200, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->response($this->current($request));
    }

    public function update(Request $request): JsonResponse
    {
        $fields = $request->validate([
            'in_app_enabled' => ['required', 'boolean'],
            'locale' => ['required', 'in:en,fr'],
            'timezone' => ['required', 'timezone'],
        ]);
        DB::table('m20_notification_preferences')->updateOrInsert([
            'company_id' => $request->attributes->get('company')->id,
            'user_id' => $request->user()->id,
        ], $fields + ['push_enabled' => false, 'updated_at' => now()]);
        return $this->response($this->current($request));
    }
}