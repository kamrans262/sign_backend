<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\M20NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class Stage3LanguagePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_preferences_accept_each_supported_language_without_changing_canonical_values(): void
    {
        $user=User::factory()->create(['is_active'=>true]);
        Sanctum::actingAs($user);
        foreach (['English','French','German','Italian'] as $language) {
            $response=$this->patchJson('/api/v1/me/preferences',[
                'language'=>$language,'dateFormat'=>'DD/MM/YYYY',
                'timeFormat'=>'24 hours','timeZone'=>'Europe/Zurich',
                'pushNotifications'=>false,'emailNotifications'=>true,
                'smsNotifications'=>false,
            ]);
            $response->assertOk()->assertJsonPath('data.language',$language);
            $this->assertDatabaseHas('m22_user_preferences',[
                'user_id'=>$user->id,'language'=>$language,
            ]);
        }
    }

    public function test_new_german_italian_templates_are_published_without_rewriting_english_french(): void
    {
        foreach (['en','fr','de','it'] as $locale) {
            foreach (['in_app','push'] as $channel) {
                $this->assertSame(7, DB::table('m20_notification_templates')
                    ->where('locale',$locale)->where('channel',$channel)->count());
                $row=DB::table('m20_notification_templates')
                    ->where('template_key','invoice.payment_due')
                    ->where('locale',$locale)->where('channel',$channel)->first();
                $this->assertNotNull($row);
                $this->assertNotNull($row->published_revision_id);
            }
        }

        $template=app(M20NotificationTemplates::class);
        foreach (['fr','de','it'] as $locale) {
            $rendered=$template->render('invoice.payment_due','in_app',$locale,[
                'invoice_number'=>'INV-003',
            ]);
            $this->assertNotNull($rendered);
            $this->assertStringContainsString('INV-003',$rendered['body']);
            $this->assertStringNotContainsString('{invoice_number}',$rendered['body']);
            $this->assertNotSame(
                $template->render('invoice.payment_due','in_app','en',['invoice_number'=>'INV-003'])['title'],
                $rendered['title']
            );
        }
    }
}
