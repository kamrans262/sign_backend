<?php
namespace Tests\Feature;

use App\Mail\M22EmailChangeCodeMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M22ProfileSecuritySupportTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $user=User::factory()->create([
            'is_active'=>true,
            'password'=>'Initial!123Pwd',
            'email_verified_at'=>now(),
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function company(User $user): Company
    {
        $company=Company::create([
            'owner_user_id'=>$user->id,'name'=>'M22 Tenant',
            'country_iso_code'=>'CH','status'=>'active',
        ]);
        $company->users()->attach($user->id,['role'=>'owner','status'=>'active']);
        return $company;
    }

    public function test_profile_and_preferences_persist_and_never_cross_accounts(): void
    {
        $user=$this->actor();
        $this->patchJson('/api/v1/me', [
            'name'=>'Maria Verified','email'=>$user->email,
            'phone_number'=>'0791001020','job_title'=>'Foreperson',
        ])->assertOk()->assertJsonPath('data.user.job_title','Foreperson');
        $this->patchJson('/api/v1/me', [
            'name'=>'Maria','email'=>'not-verified@example.test',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.user.job_title','Foreperson');
        $this->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('data.pushNotifications',true);
        $this->patchJson('/api/v1/me/preferences',[
            'language'=>'French','dateFormat'=>'DD/MM/YYYY','timeFormat'=>'24 hours',
            'timeZone'=>'Europe/Zurich','pushNotifications'=>false,
            'emailNotifications'=>true,'smsNotifications'=>false,
        ])->assertOk()->assertJsonPath('data.language','French')
            ->assertJsonPath('data.pushNotifications',false);
        $this->patchJson('/api/v1/me/preferences',[
            'language'=>'French','dateFormat'=>'DD/MM/YYYY','timeFormat'=>'24 hours',
            'timeZone'=>'Europe/Zurich','pushNotifications'=>false,
            'emailNotifications'=>true,'smsNotifications'=>true,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('m22_user_preferences',['user_id'=>$user->id,'language'=>'French']);
        $second=$this->actor();
        $this->getJson('/api/v1/me/preferences')->assertOk()
            ->assertJsonPath('data.language','English')
            ->assertJsonPath('data.pushNotifications',true);
        $this->assertNotEquals($user->id,$second->id);
    }

    public function test_verified_email_change_is_password_checked_timed_and_single_use(): void
    {
        Mail::fake();
        $user=$this->actor();
        $this->postJson('/api/v1/me/email-change',[
            'email'=>'new-address@example.test','password'=>'incorrect',
        ])->assertUnprocessable();
        $response=$this->postJson('/api/v1/me/email-change',[
            'email'=>'new-address@example.test','password'=>'Initial!123Pwd',
        ])->assertOk();
        $id=$response->json('data.challenge_id');
        $code=null;
        Mail::assertSent(M22EmailChangeCodeMail::class, function($mail)use(&$code){
            $code=$mail->code;
            return true;
        });
        $this->postJson('/api/v1/me/email-change/verify',[
            'challenge_id'=>$id,'code'=>'000000',
        ])->assertUnprocessable();
        $this->assertEquals($user->email,$user->fresh()->email);
        $this->postJson('/api/v1/me/email-change/verify',[
            'challenge_id'=>$id,'code'=>$code,
        ])->assertOk()->assertJsonPath('data.requires_login',true);
        $this->assertEquals('new-address@example.test',$user->fresh()->email);
        $this->postJson('/api/v1/me/email-change/verify',[
            'challenge_id'=>$id,'code'=>$code,
        ])->assertUnprocessable();
    }

    public function test_security_never_pretends_two_factor_is_enabled_and_validates_password(): void
    {
        $user=$this->actor();
        $other=$user->createToken('iPhone mobile session');
        $this->getJson('/api/v1/me/security')->assertOk()
            ->assertJsonPath('data.two_factor_available',false)
            ->assertJsonPath('data.two_factor_enabled',null);
        $id=$other->accessToken->id;
        $this->deleteJson('/api/v1/me/sessions/'.$id,['password'=>'bad'])
            ->assertUnprocessable();
        $this->deleteJson('/api/v1/me/sessions/'.$id,[
            'password'=>'Initial!123Pwd',
        ])->assertOk()->assertJsonPath('data.revoked',true);
        $this->assertDatabaseMissing('personal_access_tokens',['id'=>$id]);
        $this->postJson('/api/v1/me/password/change',[
            'current_password'=>'bad','password'=>'Changed!123Pwd',
            'password_confirmation'=>'Changed!123Pwd',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/me/password/change',[
            'current_password'=>'Initial!123Pwd','password'=>'Changed!123Pwd',
            'password_confirmation'=>'Changed!123Pwd',
        ])->assertOk()->assertJsonPath('data.requires_login',true);
        $this->assertTrue(Hash::check('Changed!123Pwd',$user->fresh()->password));
    }

    public function test_private_photo_is_stored_and_private_to_authenticated_account(): void
    {
        Storage::fake('local');
        $user=$this->actor();
        $this->post('/api/v1/me/photo',[
            'photo'=>UploadedFile::fake()->image('avatar.png',32,32),
        ])->assertOk()->assertJsonPath('data.has_photo',true);
        $this->get('/api/v1/me/photo')->assertOk()
            ->assertHeader('Content-Type','image/jpeg')
            ->assertHeader('Cache-Control','private, no-store');
        $path=$user->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->actor();
        $this->get('/api/v1/me/photo')->assertNotFound();
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/me/photo')->assertOk();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_support_ticket_company_auth_attachment_and_private_admin_notes(): void
    {
        Storage::fake('local');
        $owner=$this->actor();
        $company=$this->company($owner);
        $other=$this->actor();
        $this->postJson('/api/v1/support/tickets',[
            'company_id'=>$company->id,'category'=>'bug',
            'subject'=>'Cannot save work','description'=>'Saving fails for my draft',
        ])->assertForbidden();
        Sanctum::actingAs($owner);
        $response=$this->postJson('/api/v1/support/tickets',[
            'company_id'=>$company->id,'category'=>'bug',
            'subject'=>'Cannot save work','description'=>'Saving fails for my draft',
        ])->assertCreated();
        $id=$response->json('data.ticket.id');
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()
            ->assertJsonPath('data.ticket.status','open');
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies',[
            'body'=>'I can provide more details',
        ])->assertOk();
        $this->post('/api/v1/support/tickets/'.$id.'/attachments',[
            'file'=>UploadedFile::fake()->image('proof.png',16,16),
        ])->assertCreated();
        $attachment=DB::table('m22_support_attachments')->where('ticket_id',$id)->first();
        $this->get('/api/v1/support/tickets/'.$id.'/attachments/'.$attachment->id)->assertOk();
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertNotFound();
        $this->get('/api/v1/support/tickets/'.$id.'/attachments/'.$attachment->id)->assertNotFound();

        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin)->get('/admin/support/'.$id)->assertOk()
            ->assertSee('Cannot save work');
        $this->actingAs($admin)->post('/admin/support/'.$id.'/replies',[
            'body'=>'Internal investigation','internal'=>1,
        ])->assertRedirect();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()
            ->assertDontSee('Internal investigation');
    }

    public function test_faq_is_draft_until_published_by_platform_admin(): void
    {
        $owner=$this->actor();
        $this->getJson('/api/v1/support/faqs')->assertOk()
            ->assertJsonCount(0,'data.records');
        $admin=User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin)->post('/admin/support/faqs',[
            'locale'=>'en','category'=>'general','question'=>'How can I get help?',
            'answer'=>'Open the support section.','sort_order'=>1,
            'is_published'=>0,
        ])->assertRedirect();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/support/faqs')->assertJsonCount(0,'data.records');
        $faq=DB::table('m22_support_faqs')->first();
        $this->actingAs($admin)->patch('/admin/support/faqs/'.$faq->id,[
            'locale'=>'en','category'=>'general','question'=>'How can I get help?',
            'answer'=>'Open the support section.','sort_order'=>1,
            'is_published'=>1,
        ])->assertRedirect();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/support/faqs')->assertOk()
            ->assertJsonCount(1,'data.records');
    }
}
