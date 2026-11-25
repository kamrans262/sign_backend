<?php
namespace Tests\Feature;
use App\Models\User;
use App\Http\Middleware\M25AiConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use App\Models\Company;
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
 public function test_unsigned_apple_identity_event_is_rejected():void {
  $this->postJson('/api/v1/auth/apple/events',['payload'=>'invalid'])->assertUnprocessable();
  $this->assertSame(0,DB::table('m25_apple_identity_events')->count());
 }

 public function test_verified_social_account_deletion_closes_sole_company_and_queues_revocation():void {
  $u=User::factory()->create(['is_active'=>true,'email_verified_at'=>now(),'apple_subject'=>'apple-existing-subject']);
  $company=Company::create(['name'=>'M25 sole business','owner_user_id'=>$u->id,'status'=>'active']);
  $company->users()->attach($u->id,['role'=>'owner','status'=>'active']);
  $u->createToken('test-device');
  DB::table('m25_apple_credentials')->insert(['user_id'=>$u->id,'refresh_token'=>Crypt::encryptString('test-refresh'),'created_at'=>now(),'updated_at'=>now()]);
  config(['m25_apple.signin_key_id'=>'','m25_apple.signin_key_path'=>'']);
  Sanctum::actingAs($u);
  $this->getJson('/api/v1/me/deletion/options')->assertOk()->assertJsonPath('data.companies.0.can_close',true);
  $code='';
  Mail::shouldReceive('raw')->once()->andReturnUsing(function($body,$callback)use(&$code){preg_match('/\\b([0-9]{6})\\b/',$body,$m);$code=$m[1]??'';});
  $this->postJson('/api/v1/me/deletion/challenge')->assertOk();
  $this->assertMatchesRegularExpression('/^[0-9]{6}$/D',$code);
  $this->postJson('/api/v1/me/deletion/complete',['confirmation'=>'DELETE','code'=>$code,'close_sole_owned'=>true])->assertOk()->assertJsonPath('data.deleted',true)->assertJsonPath('data.manual_apple_revocation',false);
  $this->assertFalse((bool)$u->fresh()->is_active);
  $this->assertSame('suspended',$company->fresh()->status);
  $this->assertDatabaseMissing('personal_access_tokens',['tokenable_id'=>$u->id]);
  $this->assertDatabaseMissing('m25_apple_credentials',['user_id'=>$u->id]);
  $this->assertSame('test-refresh',Crypt::decryptString(DB::table('m25_apple_revocations')->first()->refresh_token));
 }
 public function test_each_ai_route_is_consent_guarded():void {
  $paths=['support/assistant','support/assistant/transcribe','ai/companies/voice','ai/jobs/voice','jobs/1/notes/2/transcribe','jobs/1/notes/2/documents/prepare','quotes/prepare/text','quotes/prepare/audio','quotes/prepare/image','labour/ai/voice','invoices/ai/voice','deposits/ai/voice','expenses/ai/voice','expenses/ai/receipt','reminders/ai/voice','general-documents/ai/prepare','general-documents/voice/transcribe','reports/voice/transcribe','reports/1/prepare-ai','addenda/prepare/voice','equipment/voice/prepare','equipment-loans/voice/prepare'];
  foreach($paths as $path)$this->assertTrue(M25AiConsent::required('api/v1/'.$path),$path);
  $this->assertFalse(M25AiConsent::required('api/v1/jobs'));
  $this->assertFalse(M25AiConsent::required('api/v1/billing/checkout'));
 }
}
