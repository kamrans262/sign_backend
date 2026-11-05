<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M20RemindersNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(?User $owner = null): array
    {
        $owner ??= User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'owner_user_id' => $owner->id, 'name' => 'M20 Test Company',
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        return [$owner, $company];
    }

    private function reminderInput(array $changes = []): array
    {
        return array_merge([
            'title' => 'Follow up on quotation', 'type' => 'other',
            'notes' => 'PRIVATE: do not put this into push or inbox',
            'due_at' => now()->subHour()->toIso8601String(),
            'timezone' => 'UTC', 'send_notification' => true,
        ], $changes);
    }

    public function test_reminders_are_tenant_and_owner_scoped_with_optimistic_locking(): void
    {
        [$owner, $company] = $this->tenant();
        [$other, $foreign] = $this->tenant();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/reminders')->assertStatus(422);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $created = $this->postJson('/api/v1/reminders', $this->reminderInput())
            ->assertCreated()->json('data.reminder');
        $this->assertSame(1, $created['lock_version']);
        $this->getJson('/api/v1/reminders')->assertOk()
            ->assertJsonCount(1, 'data.records');
        $this->patchJson('/api/v1/reminders/'.$created['id'],
            $this->reminderInput(['lock_version' => 99]))->assertStatus(409);
        $this->patchJson('/api/v1/reminders/'.$created['id'],
            $this->reminderInput(['lock_version' => 1, 'title' => 'Updated']))
            ->assertOk()->assertJsonPath('data.reminder.lock_version', 2);
        $this->postJson('/api/v1/reminders/'.$created['id'].'/status', [
            'lock_version' => 2, 'action' => 'complete',
        ])->assertOk()->assertJsonPath('data.reminder.status', 'completed');
        $this->assertSame(3, DB::table('m20_reminder_events')->count());
        $this->withHeader('X-Company-Id', (string) $foreign->id)
            ->getJson('/api/v1/reminders/'.$created['id'])->assertForbidden();
        Sanctum::actingAs($other);
        $this->withHeader('X-Company-Id', (string) $foreign->id)
            ->getJson('/api/v1/reminders/'.$created['id'])->assertNotFound();
    }

    public function test_due_sync_creates_one_private_in_app_notification_without_external_send(): void
    {
        [$user, $company] = $this->tenant();
        Sanctum::actingAs($user);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $reminder = $this->postJson('/api/v1/reminders',
            $this->reminderInput())->assertCreated()->json('data.reminder');
        $response = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.records');
        $notification = $response->json('data.records.0');
        $this->assertStringNotContainsString('PRIVATE', $notification['body']);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data.records');
        $this->postJson('/api/v1/notifications/'.$notification['id'].'/read')
            ->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->deleteJson('/api/v1/notifications/'.$notification['id'])
            ->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(0, 'data.records');
        $this->assertSame(0, DB::table('m20_notification_deliveries')->count());
        $this->assertSame($reminder['id'], $notification['reminder_id']);
    }

    public function test_disabled_notification_does_not_enter_inbox_and_reference_tenant_is_validated(): void
    {
        [$actor, $company] = $this->tenant();
        [$other, $foreign] = $this->tenant();
        Sanctum::actingAs($actor);
        $this->withHeader('X-Company-Id', (string) $company->id);
        $this->postJson('/api/v1/reminders', $this->reminderInput([
            'send_notification' => false,
        ]))->assertCreated();
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->postJson('/api/v1/reminders', $this->reminderInput([
            'type' => 'job', 'related_type' => 'job', 'related_id' => 123456,
        ]))->assertUnprocessable();
        $this->postJson('/api/v1/reminders', $this->reminderInput([
            'type' => 'client', 'related_type' => 'job', 'related_id' => 1,
        ]))->assertUnprocessable();
        $this->withHeader('X-Company-Id', (string) $foreign->id)
            ->getJson('/api/v1/notifications')->assertForbidden();
    }

    public function test_admin_notification_templates_have_live_edit_publish_restore_and_history(): void
    {
        [$user, $company] = $this->tenant();
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $template = DB::table('m20_notification_templates')
            ->where('template_key', 'reminder.due')->where('channel', 'in_app')
            ->where('locale', 'en')->first();
        $this->actingAs($admin);
        $this->get('/admin/notifications/templates')->assertOk()
            ->assertSee('Notification Text Templates');
        $this->get('/admin/notifications/templates/'.$template->id.'/edit')->assertOk()
            ->assertSee('Notification Title')->assertSee('Live Preview');
        $this->post('/admin/notifications/templates/'.$template->id.'/draft', [
            'draft_title' => 'Client copy: {reminder_title}',
            'draft_body' => 'Please review by {due_date}.',
            'draft_revision' => 1,
        ])->assertRedirect();
        $service = app(\App\Services\M20NotificationTemplates::class);
        $before = $service->render('reminder.due', 'in_app', 'en', [
            'reminder_title' => 'First', 'due_date' => 'Today',
        ]);
        $this->assertStringContainsString('Reminder due', $before['title']);
        $this->post('/admin/notifications/templates/'.$template->id.'/publish', [
            'confirm' => 1,
        ])->assertRedirect();
        $after = $service->render('reminder.due', 'in_app', 'en', [
            'reminder_title' => 'First', 'due_date' => 'Today',
        ]);
        $this->assertSame('Client copy: First', $after['title']);
        $this->assertNotEquals($before['revision_id'], $after['revision_id']);
        $this->post('/admin/notifications/templates/'.$template->id.'/draft', [
            'draft_title' => '{private_notes}',
            'draft_body' => 'bad copy',
            'draft_revision' => 3,
        ])->assertSessionHasErrors();
        $firstRevision = DB::table('m20_notification_template_revisions')
            ->where('template_id', $template->id)->where('revision', 1)->value('id');
        $this->post('/admin/notifications/templates/'.$template->id.'/restore', [
            'revision_id' => $firstRevision,
        ])->assertRedirect();
        $this->assertSame('Client copy: First',
            $service->render('reminder.due', 'in_app', 'en', [
                'reminder_title' => 'First', 'due_date' => 'Today',
            ])['title']);
        $this->assertSame(4, DB::table('m20_notification_template_revisions')
            ->where('template_id', $template->id)->count());
    }
}