<?php
namespace Tests\Feature;
use App\Models\User;
use App\Http\Middleware\M25AiConsent;
use App\Services\M25StoreKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
final class M25PrivacyComplianceTest extends TestCase {
 use RefreshDatabase;
 public function test_consent_is_explicit_versioned_account_bound_and_withdrawable():void {
  $u=User::factory()->create(['is_active'=>true,'email_verified_at'=>now()]);
  Sanctum::actingAs($u); Http::fake();
  $this->getJson('/api/v1/me/ai-consent')->assertOk()->assertJsonPath('data.allowed',false);
  $this->postJson('/api/v1/support/assistant',['message'=>'Help'])->assertForbidden()->assertJsonPath('code','ai_consent_required');
  Http::assertNothingSent();
  $this->putJson('/api/v1/me/ai-consent',['user_id'=>$u->id+1,'version'=>M25AiConsent::VERSION,'allowed'=>true])->assertStatus(409);
  $this->putJson('/api/v1/me/ai-consent',['user_id'=>$u->id,'version'=>'obsolete','allowed'=>true])->assertUnprocessable();
  $this->putJson('/api/v1/me/ai-consent',['user_id'=>$u->id,'version'=>M25AiConsent::VERSION,'allowed'=>true])->assertOk()->assertJsonPath('data.allowed',true);
  $this->putJson('/api/v1/me/ai-consent',['user_id'=>$u->id,'version'=>M25AiConsent::VERSION,'allowed'=>false])->assertOk()->assertJsonPath('data.allowed',false);
  $this->postJson('/api/v1/support/assistant',['message'=>'Help'])->assertForbidden();
  Http::assertNothingSent();
 }
 public function test_invalid_deletion_proof_does_not_erase_social_account():void {
  $u=User::factory()->create(['is_active'=>true,'email_verified_at'=>now(),'apple_subject'=>'apple-example-subject']);
  Sanctum::actingAs($u);
  $this->postJson('/api/v1/me/deletion/complete',['confirmation'=>'DELETE','code'=>'123456'])->assertUnprocessable();
  $this->assertTrue((bool)$u->fresh()->is_active);
 }
 public function test_storekit_starts_disabled_and_unsigned_event_is_rejected():void {
  config(['m25_apple.store_enabled'=>false]);
  $this->assertFalse(app(M25StoreKit::class)->ready());
  $this->postJson('/api/v1/auth/apple/events',['payload'=>'invalid'])->assertUnprocessable();
  $this->assertSame(0,DB::table('m25_apple_identity_events')->count());
 }
}
