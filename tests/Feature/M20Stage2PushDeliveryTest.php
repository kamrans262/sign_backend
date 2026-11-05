<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\M20FcmClient;
use App\Services\M20PushDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M20Stage2PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private ?string $credentials = null;

    protected function tearDown(): void
    {
        if ($this->credentials && is_file($this->credentials)) unlink($this->credentials);
        parent::tearDown();
    }

    private function ready(bool $on): void
    {
        $this->credentials ??= tempnam(sys_get_temp_dir(), 's2dfcm');
        $key = openssl_pkey_new(['private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        file_put_contents($this->credentials, json_encode([
            'project_id' => 'sign-kamran',
            'client_email' => 'sender@sign-kamran.iam.gserviceaccount.com',
            'private_key' => $private,
        ]));
        config()->set('services.m20_fcm', [
            'enabled' => $on, 'project_id' => 'sign-kamran',
            'credentials_path' => $this->credentials,
        ]);
    }

    private function tenant(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create(['name' => 'FCM Test Company',
            'owner_user_id' => $user->id, 'country_iso_code' => 'CH',
            'status' => 'active']);
        $company->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
        return [$user, $company];
    }

    private function register(string $installation = '56c74f2a-d7f7-4d34-b7cb-9e17f7d319f2'): void
    {
        $this->postJson('/api/v1/push-devices', [
            'installation_id' => $installation, 'platform' => 'android',
            'fid' => str_repeat('fcm-token-', 12),
        ])->assertOk()->assertJsonPath('data.registered', true);
    }

    public function test_registration_is_authenticated_company_scoped_encrypted_and_reversible(): void
    {
        $this->postJson('/api/v1/push-devices', [
            'installation_id' => '56c74f2a-d7f7-4d34-b7cb-9e17f7d319f2',
            'platform' => 'android', 'fid' => str_repeat('a', 60),
        ])->assertUnauthorized();
        [$user, $company] = $this->tenant();
        $this->postJson('/api/v1/push-devices', [
            'installation_id' => '56c74f2a-d7f7-4d34-b7cb-9e17f7d319f2',
            'platform' => 'android', 'fid' => str_repeat('a', 60),
        ])->assertStatus(503);
        $this->ready(true);
        $this->register();
        $row = DB::table('m20_push_devices')->first();
        $this->assertSame($company->id, $row->company_id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotEquals(str_repeat('fcm-token-', 12), $row->encrypted_fid);
        $this->assertSame(str_repeat('fcm-token-', 12), Crypt::decryptString($row->encrypted_fid));
        $this->register();
        $this->assertSame(1, DB::table('m20_push_devices')->count());
        $this->deleteJson('/api/v1/push-devices', [
            'installation_id' => '56c74f2a-d7f7-4d34-b7cb-9e17f7d319f2',
        ])->assertOk()->assertJsonPath('data.revoked', 1);
        $this->assertSame(0, DB::table('m20_push_devices')->where('enabled', true)->count());
    }

    public function test_push_delivery_is_opt_in_published_generic_idempotent_and_queued(): void
    {
        $this->ready(true);
        [$user, $company] = $this->tenant();
        $this->register();
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => false, 'push_enabled' => true,
            'locale' => 'en', 'timezone' => 'UTC',
        ])->assertOk()->assertJsonPath('data.preferences.push_enabled', true)
            ->assertJsonPath('data.preferences.push_available', true);
        $this->postJson('/api/v1/reminders', [
            'title' => 'Sensitive client name', 'notes' => 'PRIVATE BANK PIN: 12345',
            'type' => 'other', 'due_at' => now()->subMinute()->toIso8601String(),
            'send_notification' => true, 'timezone' => 'UTC',
        ])->assertCreated();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-m20-access', 'expires_in' => 3600,
            ]),
            'https://fcm.googleapis.com/v1/projects/sign-kamran/messages:send' =>
                Http::response(['name' => 'projects/sign-kamran/messages/test-1'], 200),
        ]);
        $result = app(M20PushDispatcher::class)->process(40, true);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['queued']);
        $notification = DB::table('m20_notifications')->first();
        $delivery = DB::table('m20_push_deliveries')->first();
        $this->assertSame('submitted', $delivery->status);
        $this->assertSame('Sign2Digital reminder', $delivery->title);
        $this->assertStringNotContainsString('PRIVATE', $delivery->body);
        $this->assertStringNotContainsString('Sensitive', $delivery->body);
        $this->assertEquals($notification->id, $delivery->notification_id);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'messages:send')
            && $request['message']['notification']['title'] === 'Sign2Digital reminder'
            && $request['message']['fid'] === str_repeat('fcm-token-', 12)
            && $request['message']['data']['company_id'] === (string) $company->id
            && !str_contains(json_encode($request->data()), 'PRIVATE BANK PIN'));
        $second = app(M20PushDispatcher::class)->process(40, true);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(0, $second['queued']);
        $this->assertSame(1, DB::table('m20_push_deliveries')->count());
    }

    public function test_disabled_global_gate_has_no_external_delivery_and_push_optin_rejected(): void
    {
        $this->ready(false);
        [$user, $company] = $this->tenant();
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => true, 'push_enabled' => true,
            'locale' => 'en', 'timezone' => 'UTC',
        ])->assertUnprocessable();
        $this->assertFalse(app(M20FcmClient::class)->available());
        Http::fake();
        $result = app(M20PushDispatcher::class)->process(10, true);
        $this->assertFalse($result['enabled']);
        Http::assertNothingSent();
    }

    public function test_device_registration_cannot_stay_active_for_a_previous_account(): void
    {
        $this->ready(true);
        [$userA, $companyA] = $this->tenant();
        $this->register();
        [$userB, $companyB] = $this->tenant();
        $this->register();
        $this->assertSame(1, DB::table('m20_push_devices')->where('enabled', true)->count());
        $this->assertSame($userB->id,
            DB::table('m20_push_devices')->where('enabled', true)->value('user_id'));
        $this->assertSame($companyA->id,
            DB::table('m20_push_devices')->where('user_id', $userA->id)->value('company_id'));
    }

    public function test_completed_reminder_is_not_pushed_after_it_was_enqueued(): void
    {
        $this->ready(true);
        [$user, $company] = $this->tenant();
        $this->register();
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => true, 'push_enabled' => true,
            'locale' => 'en', 'timezone' => 'UTC',
        ])->assertOk();
        $reminder = $this->postJson('/api/v1/reminders', [
            'title' => 'Cancel me', 'type' => 'other',
            'due_at' => now()->subHour()->toIso8601String(),
            'send_notification' => true,
        ])->assertCreated()->json('data.reminder');
        $this->getJson('/api/v1/notifications')->assertOk();
        $this->postJson('/api/v1/reminders/'.$reminder['id'].'/status', [
            'lock_version' => 1, 'action' => 'complete',
        ])->assertOk();
        Http::fake();
        $result = app(M20PushDispatcher::class)->process(20, true);
        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
        $this->assertSame('revoked',
            DB::table('m20_push_deliveries')->first()->status);
    }

    public function test_ambiguous_transport_error_is_unknown_and_not_retried(): void
    {
        $this->ready(true);
        [$user, $company] = $this->tenant();
        $this->register();
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => true, 'push_enabled' => true,
            'locale' => 'en', 'timezone' => 'UTC',
        ])->assertOk();
        $this->postJson('/api/v1/reminders', [
            'title' => 'Network uncertain', 'type' => 'other',
            'due_at' => now()->subHour()->toIso8601String(),
            'send_notification' => true,
        ])->assertCreated();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'm20-unknown-fake', 'expires_in' => 3600,
            ]),
            'https://fcm.googleapis.com/v1/projects/sign-kamran/messages:send' =>
                function () { throw new \RuntimeException('network dropped'); },
        ]);
        $first = app(M20PushDispatcher::class)->process(20, true);
        $this->assertSame(1, $first['unknown']);
        $this->assertSame('unknown', DB::table('m20_push_deliveries')->first()->status);
        $second = app(M20PushDispatcher::class)->process(20, true);
        $this->assertSame(0, $second['unknown']);
        $this->assertSame(1, DB::table('m20_push_deliveries')->first()->attempts);
    }

    public function test_admin_audits_push_without_exposing_device_identifiers(): void
    {
        $this->ready(true);
        [$user, $company] = $this->tenant();
        $this->register();
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => true, 'push_enabled' => true,
            'locale' => 'en', 'timezone' => 'UTC',
        ])->assertOk();
        $this->postJson('/api/v1/reminders', [
            'title' => 'Admin audit', 'type' => 'other',
            'due_at' => now()->subHour()->toIso8601String(),
            'send_notification' => true,
        ])->assertCreated();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-m20-audit', 'expires_in' => 3600,
            ]),
            'https://fcm.googleapis.com/v1/projects/sign-kamran/messages:send' =>
                Http::response(['name' => 'projects/sign-kamran/messages/audit'], 200),
        ]);
        $this->assertSame(1, app(M20PushDispatcher::class)->process(10, true)['sent']);
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($admin);
        $this->get('/admin/notification-inbox')->assertOk()
            ->assertSee('Firebase Push Delivery Audit')
            ->assertSee('Accepted by provider')
            ->assertSee('projects/sign-kamran/messages/audit')
            ->assertDontSee(str_repeat('fcm-token-', 12));
    }

}
