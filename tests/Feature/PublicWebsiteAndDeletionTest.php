<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicWebsiteAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_and_admin_session_navigation(): void
    {
        $this->get('/')->assertOk()->assertSee('Digitally connected')->assertSee('Admin login');
        $this->get('/terms-and-conditions')->assertOk()->assertSee('Terms and Conditions');
        $this->get('/privacy-policy')->assertOk()->assertSee('Privacy Policy');
        $this->get('/account-deletion')->assertOk()->assertSee('Send verification code');
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/admin/login')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Admin dashboard');
        $this->get('/admin/login')->assertRedirect(route('admin.dashboard'));
        $this->post('/admin/login')->assertRedirect(route('admin.dashboard'));
        $this->post('/admin/logout')->assertRedirect(route('site.home'));
        $this->assertGuest();
    }

    public function test_verified_deletion_request_requires_explicit_admin_processing(): void
    {
        $user = User::factory()->create(['email' => 'deletion-test@example.com', 'email_verified_at' => now(), 'is_active' => true]);
        $user->createToken('test-device');
        $code = '';
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) use (&$code) {
            preg_match('/\b([0-9]{6})\b/', $body, $matches);
            $code = $matches[1] ?? '';
        });
        $this->post('/account-deletion/request', ['email' => $user->email, 'confirm' => '1'])->assertRedirect(route('site.deletion'));
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->from('/account-deletion')->post('/account-deletion/verify', ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('m22_support_tickets', 0);
        $this->post('/account-deletion/verify', ['code' => $code])->assertRedirect(route('site.deletion'));
        $ticket = DB::table('m22_support_tickets')->where('user_id', $user->id)->first();
        $this->assertSame('account_deletion', $ticket->category);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotEmpty($ticket->deletion_verified_email_hash);
        $this->post('/account-deletion/verify', ['code' => $code])->assertRedirect(route('site.deletion'));
        $this->assertDatabaseCount('m22_support_tickets', 1);
        $this->post('/admin/support/'.$ticket->id.'/complete-deletion')->assertRedirect(route('admin.login'));
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true, 'password' => 'AdminTest!123']);
        $this->actingAs($admin)->get('/admin/support/'.$ticket->id)->assertOk()->assertSee('Process account deletion');
        $this->from('/admin/support/'.$ticket->id)->post('/admin/support/'.$ticket->id.'/complete-deletion', [
            'password' => 'wrong', 'confirmation' => 'DELETE', 'reviewed' => '1',
        ])->assertSessionHasErrors('password');
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $this->post('/admin/support/'.$ticket->id.'/complete-deletion', [
            'password' => 'AdminTest!123', 'confirmation' => 'DELETE', 'reviewed' => '1',
        ])->assertRedirect(route('admin.support.index'));
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('deleted-'.$user->id.'@accounts.invalid', $user->fresh()->email);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('m22_support_tickets', ['id' => $ticket->id]);
    }

    public function test_unknown_email_cannot_create_deletion_request(): void
    {
        Mail::shouldReceive('raw')->never();
        $this->post('/account-deletion/request', ['email' => 'unknown@example.com', 'confirm' => '1'])->assertRedirect(route('site.deletion'));
        $this->get('/account-deletion')->assertOk()->assertSee('Verify your email address');
        $this->from('/account-deletion')->post('/account-deletion/verify', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('m22_support_tickets', 0);
    }
}
