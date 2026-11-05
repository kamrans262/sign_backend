<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\M20NotificationScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class M20ScheduledNotificationSyncTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'name' => 'M20 Scheduled Company',
            'owner_user_id' => $user->id,
            'country_iso_code' => 'CH', 'status' => 'active',
        ]);
        $company->users()->attach($user->id, [
            'role' => 'owner', 'status' => 'active',
        ]);
        return [$user, $company];
    }

    private function due(int $companyId, int $userId): void
    {
        DB::table('m20_reminders')->insert([
            'company_id' => $companyId,
            'owner_user_id' => $userId,
            'title' => 'Scheduled follow up', 'type' => 'other',
            'due_at' => now()->subMinutes(15),
            'status' => 'active', 'send_notification' => true,
            'timezone' => 'UTC', 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_scheduler_creates_due_inbox_when_no_preferences_exist_without_external_delivery(): void
    {
        Mail::fake();
        Http::fake();
        [$user, $company] = $this->member();
        $this->due($company->id, $user->id);
        $first = app(M20NotificationScheduler::class)->sync();
        $this->assertSame(1, $first['recipients']);
        $this->assertSame(1, $first['reminder_events']);
        $this->assertSame(1, DB::table('m20_notifications')->count());
        $this->assertSame(0, DB::table('m20_push_deliveries')->count());
        $this->assertSame(0, DB::table('m20_notification_deliveries')->count());
        $second = app(M20NotificationScheduler::class)->sync();
        $this->assertSame(0, $second['reminder_events']);
        $this->assertSame(0, $second['recipients']);
        $this->artisan('sign:m20-sync', ['--max' => 30])->assertSuccessful();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_scheduler_respects_opt_out_and_removed_company_membership(): void
    {
        Mail::fake();
        Http::fake();
        [$disabled, $companyA] = $this->member();
        $this->due($companyA->id, $disabled->id);
        DB::table('m20_notification_preferences')->insert([
            'company_id' => $companyA->id, 'user_id' => $disabled->id,
            'in_app_enabled' => false, 'push_enabled' => false,
            'locale' => 'en', 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        [$removed, $companyB] = $this->member();
        $this->due($companyB->id, $removed->id);
        DB::table('company_user')
            ->where('company_id', $companyB->id)
            ->where('user_id', $removed->id)
            ->update(['status' => 'removed']);
        $result = app(M20NotificationScheduler::class)->sync();
        $this->assertSame(0, $result['recipients']);
        $this->assertSame(0, $result['reminder_events']);
        $this->assertSame(0, DB::table('m20_notifications')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
