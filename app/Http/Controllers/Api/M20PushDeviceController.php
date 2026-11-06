<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\M20FcmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class M20PushDeviceController extends Controller
{
    public function register(Request $request, M20FcmClient $fcm): JsonResponse
    {
        $values = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'platform' => ['required', 'in:android,ios'],
            'fid' => ['required', 'string', 'min:20', 'max:4096'],
        ]);
        if (DB::table('m22_user_preferences')->where('user_id', $request->user()->id)
            ->where('push_notifications', false)->exists()) {
            abort(422, 'Enable account-wide push consent before registering a device.');
        }
        abort_unless($fcm->available(), 503, 'Push notifications are not configured.');
        $company = $request->attributes->get('company')->id;
        $user = $request->user()->id;
        // A device installation can change accounts. Revoke other owners before
        // registering so a previously signed-in user cannot keep receiving pushes.
        DB::transaction(function () use ($values, $company, $user): void {
            DB::table('m20_push_devices')->where('installation_id', $values['installation_id'])
                ->where('user_id', '!=', $user)->where('enabled', true)
                ->update(['enabled' => false, 'revoked_at' => now(), 'updated_at' => now()]);
            DB::table('m20_push_devices')->updateOrInsert([
                'company_id' => $company, 'user_id' => $user,
                'installation_id' => $values['installation_id'],
            ], [
                'platform' => $values['platform'],
                'fid_hash' => hash('sha256', $values['fid']),
                'encrypted_fid' => Crypt::encryptString($values['fid']),
                'enabled' => true, 'revoked_at' => null, 'last_seen_at' => now(),
                'updated_at' => now(),
            ]);
        });
        return response()->json(['data' => ['registered' => true]], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $values = $request->validate(['installation_id' => ['required', 'uuid']]);
        // Revocation on logout covers all company contexts for THIS user only.
        $count = DB::table('m20_push_devices')
            ->where('user_id', $request->user()->id)
            ->where('installation_id', $values['installation_id'])
            ->where('enabled', true)->update([
                'enabled' => false, 'revoked_at' => now(), 'updated_at' => now(),
            ]);
        return response()->json(['data' => ['revoked' => $count]], 200, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
