<?php

namespace Tests\Feature;

use App\Mail\EmailOtpCodeMail;
use App\Models\User;
use App\Services\M24TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M24TwoFactorSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function actor(): User
    {
        $user=User::factory()->create([
            'password'=>'TestPass@262', 'email_verified_at'=>now(), 'is_active'=>true,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function enable(User $user): array
    {
        $setup=$this->postJson('/api/v1/me/2fa/setup',[
            'password'=>'TestPass@262',
        ])->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/',$setup['secret']);
        $this->assertStringStartsWith('otpauth://totp/',$setup['otpauth_uri']);
        $this->assertDatabaseMissing('m24_two_factor_credentials',[
            'user_id'=>$user->id,'pending_secret_ciphertext'=>$setup['secret'],
        ]);
        $row=DB::table('m24_two_factor_credentials')->where('user_id',$user->id)->first();
        $this->assertEquals($setup['secret'],Crypt::decryptString($row->pending_secret_ciphertext));
        $this->getJson('/api/v1/me/security')->assertJsonPath('data.two_factor_enabled',false)
            ->assertJsonPath('data.two_factor_available',true);
        $code=app(M24TwoFactorService::class)->codeAt(
            $setup['secret'],intdiv(time(),30));
        $enabled=$this->postJson('/api/v1/me/2fa/confirm',[
            'code'=>$code,
        ])->assertOk()->assertJsonPath('data.enabled',true)->json('data');
        $this->assertCount(10,$enabled['recovery_codes']);
        $this->assertCount(10,array_unique($enabled['recovery_codes']));
        $this->assertDatabaseCount('m24_two_factor_recovery_codes',10);
        $this->getJson('/api/v1/me/security')->assertJsonPath('data.two_factor_enabled',true);
        $this->postJson('/api/v1/me/2fa/confirm',['code'=>$code])->assertUnprocessable();
        return [$setup['secret'],$enabled['recovery_codes']];
    }

    public function test_totp_uses_rfc_6238_hotp_sha1_dynamic_truncation(): void
    {
        // RFC 6238 SHA-1 T=59 gives 94287082 (8 digits), i.e. 287082 (6).
        $this->assertSame('287082',app(M24TwoFactorService::class)
            ->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',1));
        $this->assertSame(32,strlen(app(M24TwoFactorService::class)->generateSecret()));
    }

    public function test_setup_needs_password_and_preserves_disabled_state_until_verified(): void
    {
        $user=$this->actor();
        $this->postJson('/api/v1/me/2fa/setup',['password'=>'wrong'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('m24_two_factor_credentials',0);
        $setup=$this->postJson('/api/v1/me/2fa/setup',[
            'password'=>'TestPass@262',
        ])->assertOk()->json('data');
        $this->postJson('/api/v1/me/2fa/confirm',['code'=>'000000'])
            ->assertUnprocessable();
        $this->assertFalse(app(M24TwoFactorService::class)->enabled($user->id));
        DB::table('m24_two_factor_credentials')->where('user_id',$user->id)
            ->update(['pending_expires_at'=>now()->subSecond()]);
        $code=app(M24TwoFactorService::class)->codeAt(
            $setup['secret'],intdiv(time(),30));
        $this->postJson('/api/v1/me/2fa/confirm',['code'=>$code])
            ->assertUnprocessable();
    }

    public function test_email_otp_cannot_issue_token_when_authenticator_enabled(): void
    {
        $user=$this->actor();
        [, $recovery]=$this->enable($user);
        // This records a real Sanctum API token only after all three steps.
        $challenge=$this->postJson('/api/v1/auth/login',[
            'email'=>$user->email,'password'=>'TestPass@262',
        ])->assertOk()->json('data.challenge_id');
        $emailCode='';
        Mail::assertSent(EmailOtpCodeMail::class,function($mail)use(&$emailCode){
            $emailCode=$mail->code;
            return true;
        });
        $result=$this->postJson('/api/v1/auth/otp/verify',[
            'challenge_id'=>$challenge,'code'=>$emailCode,
        ])->assertOk()->assertJsonPath('data.requires_two_factor',true)
            ->assertJsonMissingPath('data.access_token');
        $ticket=$result->json('data.two_factor_ticket');
        $this->assertSame(64,strlen($ticket));
        $this->assertDatabaseCount('personal_access_tokens',0);
        $this->postJson('/api/v1/auth/otp/verify',[
            'challenge_id'=>$challenge,'code'=>$emailCode,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/auth/2fa/verify',[
            'ticket'=>$ticket,'code'=>'000000',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('m24_two_factor_login_challenges',[
            'ticket_hash'=>hash('sha256',$ticket),'attempts'=>1,
        ]);
        $this->assertDatabaseCount('personal_access_tokens',0);
        $this->postJson('/api/v1/auth/2fa/verify',[
            'ticket'=>$ticket,'code'=>$recovery[0],
        ])->assertOk()->assertJsonPath('data.purpose','login')
            ->assertJsonStructure(['data'=>['access_token','token_type']]);
        $this->assertDatabaseCount('personal_access_tokens',1);
        $this->postJson('/api/v1/auth/2fa/verify',[
            'ticket'=>$ticket,'code'=>$recovery[1],
        ])->assertUnprocessable();
        $this->assertDatabaseHas('m24_two_factor_recovery_codes',[
            'user_id'=>$user->id,'code_hash'=>hash_hmac(
                'sha256',str_replace('-','',$recovery[0]),config('app.key')),
        ]);
    }

    public function test_expired_and_five_attempts_login_tickets_fail_closed(): void
    {
        $user=$this->actor();
        [, $recovery]=$this->enable($user);
        $ticket=app(M24TwoFactorService::class)->createLoginTicket($user,'Device');
        for($i=1;$i<=5;$i++){
            $this->postJson('/api/v1/auth/2fa/verify',[
                'ticket'=>$ticket,'code'=>'000000',
            ])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/2fa/verify',[
            'ticket'=>$ticket,'code'=>$recovery[0],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens',0);
        $expired=app(M24TwoFactorService::class)->createLoginTicket($user,'Device');
        DB::table('m24_two_factor_login_challenges')
            ->where('ticket_hash',hash('sha256',$expired))
            ->update(['expires_at'=>now()->subSecond()]);
        $this->postJson('/api/v1/auth/2fa/verify',[
            'ticket'=>$expired,'code'=>$recovery[0],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens',0);
    }

    public function test_recovery_rotation_and_disable_revoke_other_sessions(): void
    {
        $user=$this->actor();
        [, $recovery]=$this->enable($user);
        $user->createToken('Other device');
        $this->postJson('/api/v1/me/2fa/recovery/regenerate',[
            'password'=>'wrong','code'=>$recovery[0],
        ])->assertUnprocessable();
        $new=$this->postJson('/api/v1/me/2fa/recovery/regenerate',[
            'password'=>'TestPass@262','code'=>$recovery[0],
        ])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10,$new);
        $this->assertDatabaseCount('personal_access_tokens',0);
        $this->postJson('/api/v1/me/2fa/disable',[
            'password'=>'TestPass@262','code'=>$recovery[1],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/me/2fa/disable',[
            'password'=>'TestPass@262','code'=>$new[0],
        ])->assertOk()->assertJsonPath('data.enabled',false);
        $this->assertDatabaseCount('m24_two_factor_recovery_codes',0);
        $this->getJson('/api/v1/me/security')->assertJsonPath('data.two_factor_enabled',false);
    }
}
