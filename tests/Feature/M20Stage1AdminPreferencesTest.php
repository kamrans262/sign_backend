<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M20Stage1AdminPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function members(): array
    {
        $member = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'owner_user_id' => $member->id, 'name' => 'M20 Admin QA',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($member->id, [
            'role' => 'owner', 'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'is_active' => true, 'is_platform_admin' => true,
        ]);
        return [$member, $company, $admin];
    }

    public function test_admin_can_manage_reminders_and_audit_inbox_without_impersonation(): void
    {
        [$member, $company, $admin] = $this->members();
        $this->get('/admin/reminders')->assertRedirect();
        $this->actingAs($admin);
        $this->get('/admin/reminders')->assertOk()->assertSee('Reminders Management');
        $this->get('/admin/reminders/create')->assertOk()->assertSee('Private Notes');
        $this->post('/admin/reminders', [
            'company_id' => $company->id, 'owner_user_id' => $member->id,
            'title' => 'Admin-created reminder', 'type' => 'other',
            'due_at' => '2026-11-20T09:00', 'priority' => 'normal',
            'send_notification' => 1,
        ])->assertRedirect();
        $row = DB::table('m20_reminders')->where('title', 'Admin-created reminder')->first();
        $this->assertNotNull($row);
        $this->assertSame($member->id, $row->owner_user_id);
        $this->get('/admin/reminders/'.$row->id.'/edit')->assertOk()
            ->assertSee('Audit History');
        $this->patch('/admin/reminders/'.$row->id, [
            'company_id' => $company->id, 'owner_user_id' => $member->id,
            'lock_version' => 1, 'title' => 'Admin-updated reminder',
            'type' => 'other', 'due_at' => '2026-11-21T10:00',
            'priority' => 'high', 'send_notification' => 0,
            'private_notes' => 'Not for lock screen',
        ])->assertRedirect();
        $this->post('/admin/reminders/'.$row->id.'/status', [
            'lock_version' => 2, 'action' => 'complete',
        ])->assertRedirect();
        $this->get('/admin/notification-inbox')->assertOk()
            ->assertSee('In-app Notification Audit');
        $this->assertSame(3, DB::table('m20_reminder_events')
            ->where('reminder_id', $row->id)->count());
    }

    public function test_reminder_followup_uses_published_french_copy_and_preferences(): void
    {
        [$member, $company] = $this->members();
        Sanctum::actingAs($member);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $this->getJson('/api/v1/notification-preferences')->assertOk()
            ->assertJsonPath('data.preferences.push_available', false)
            ->assertJsonPath('data.preferences.locale', 'en');
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => true, 'locale' => 'fr', 'timezone' => 'UTC',
        ])->assertOk()->assertJsonPath('data.preferences.push_enabled', false);
        $this->postJson('/api/v1/reminders', [
            'title' => 'French Reminder', 'type' => 'other',
            'due_at' => now()->subHours(2)->toIso8601String(),
            'follow_up_at' => now()->subHour()->toIso8601String(),
            'send_notification' => true,
            'notes' => 'Secret do not display',
        ])->assertCreated();
        $result = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 2)->json('data.records');
        $this->assertCount(2, $result);
        $this->assertStringContainsString('Rappel', $result[0]['title']);
        $this->assertStringNotContainsString('Secret', json_encode($result));
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 2);
        $this->putJson('/api/v1/notification-preferences', [
            'in_app_enabled' => false, 'locale' => 'en', 'timezone' => 'UTC',
        ])->assertOk();
        $this->postJson('/api/v1/reminders', [
            'title' => 'No notification', 'type' => 'other',
            'due_at' => now()->subHour()->toIso8601String(),
            'send_notification' => true,
        ])->assertCreated();
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 2);
        $this->assertSame(0, DB::table('m20_notification_deliveries')->count());
    }
}