<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WebsiteContactTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name'=>'Example Visitor','email'=>'visitor@example.com',
            'subject'=>'Product question','message'=>'Could you explain the subscription plans?'];
    }

    public function test_public_contact_is_linked_and_persists_without_an_account(): void
    {
        $this->get('/')->assertOk()->assertSee(route('site.contact'))->assertSee('Contact Us');
        $this->get('/contact-us')->assertOk()->assertSee('Send Message')
            ->assertSee('name="_token"', false);
        $this->post('/contact-us', $this->payload())->assertRedirect(route('site.contact'))
            ->assertSessionHas('contact_reference');
        $this->get('/contact-us')->assertOk()->assertSee('Your message has been received');
        $this->assertDatabaseHas('website_contact_messages', array_merge($this->payload(), ['status'=>'new']));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('m22_support_tickets', 0);
    }

    public function test_invalid_and_bot_submissions_do_not_create_messages(): void
    {
        $this->from('/contact-us')->post('/contact-us', [])
            ->assertSessionHasErrors(['name','email','subject','message']);
        $this->from('/contact-us')->post('/contact-us', array_merge($this->payload(), ['email'=>'invalid','message'=>'x']))
            ->assertSessionHasErrors(['email','message']);
        $this->from('/contact-us')->post('/contact-us', array_merge($this->payload(), ['website'=>'bot']))
            ->assertSessionHasErrors('website');
        $this->assertDatabaseCount('website_contact_messages', 0);
    }

    public function test_contact_submission_is_rate_limited(): void
    {
        for ($i=0; $i<5; $i++) $this->post('/contact-us', $this->payload())->assertRedirect(route('site.contact'));
        $this->post('/contact-us', $this->payload())->assertStatus(429);
        $this->assertDatabaseCount('website_contact_messages', 5);
    }

    public function test_only_platform_admin_can_read_update_or_delete_messages(): void
    {
        $payload = array_merge($this->payload(), ['message'=>'<script>alert(1)</script>']);
        $this->post('/contact-us', $payload)->assertRedirect(route('site.contact'));
        $id = DB::table('website_contact_messages')->value('id');
        $url = '/admin/support/contact-messages/'.$id;
        $this->get($url)->assertRedirect(route('admin.login'));
        $member = User::factory()->create(['is_active'=>true,'is_platform_admin'=>false]);
        $this->actingAs($member)->get('/admin/support/contact-messages')->assertForbidden();
        $this->get($url)->assertForbidden();
        $this->patch($url,['status'=>'resolved'])->assertForbidden();
        $this->delete($url,['confirm'=>'1'])->assertForbidden();

        $admin = User::factory()->create(['is_active'=>true,'is_platform_admin'=>true]);
        $this->actingAs($admin)->get('/admin/support')->assertOk()->assertSee('Website Contact Messages');
        $this->get('/admin/support/contact-messages?q=visitor@example.com')->assertOk()->assertSee('Product question');
        $this->get($url)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)
            ->assertDontSee('<script>alert(1)</script>',false);
        $this->from($url)->patch($url,['status'=>'invalid'])->assertSessionHasErrors('status');
        $this->patch($url,['status'=>'resolved'])->assertRedirect();
        $this->assertDatabaseHas('website_contact_messages',['id'=>$id,'status'=>'resolved']);
        $this->from($url)->delete($url)->assertSessionHasErrors('confirm');
        $this->delete($url,['confirm'=>'1'])->assertRedirect(route('admin.support.contacts.index'));
        $this->assertDatabaseMissing('website_contact_messages',['id'=>$id]);
    }
}
