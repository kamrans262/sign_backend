<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\M20FcmClient;

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
            'push_enabled' => $row ? (bool) $row->push_enabled : false,
            'push_available' => app(M20FcmClient::class)->available(),
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
            'push_enabled' => ['sometimes', 'boolean'],
        ]);
        if (($fields['push_enabled'] ?? false) &&
            DB::table('m22_user_preferences')->where('user_id', $request->user()->id)
                ->where('push_notifications', false)->exists()) {
            abort(422, 'Enable account-wide push notifications before opting in.');
        }
        if (($fields['push_enabled'] ?? false) && !app(M20FcmClient::class)->available()) {
            abort(422, 'Push delivery is not configured.');
        }
        $existing = $this->scope($request)->first();
        $fields['push_enabled'] = $fields['push_enabled'] ?? (bool) ($existing?->push_enabled ?? false);
        if (($fields['push_enabled'] ?? $existing?->push_enabled ?? false) === false
            || ($fields['push_enabled'] ?? $existing?->push_enabled ?? false) === 0) {
            // Opting out cancels this company's future queued device deliveries.
            DB::table('m20_push_devices')->where('company_id',
                $request->attributes->get('company')->id)
                ->where('user_id', $request->user()->id)->where('enabled', true)
                ->update(['enabled' => false, 'revoked_at' => now(), 'updated_at' => now()]);
        }
        DB::table('m20_notification_preferences')->updateOrInsert([
            'company_id' => $request->attributes->get('company')->id,
            'user_id' => $request->user()->id,
        ], $fields + ['updated_at' => now()]);
        return $this->response($this->current($request));
    }
}