<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class M22Stage2SecurityReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Acceptance exercises authorization and state transitions; the
        // configured endpoint rate limits are covered by separate middleware.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function actor(): User
    {
        $user = User::factory()->create([
            'password' => 'Initial!123Pwd',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function ticket(): int
    {
        return (int) $this->postJson('/api/v1/support/tickets', [
            'category' => 'bug',
            'subject' => 'Stage two permission check',
            'description' => 'We need to verify access boundaries',
        ])->assertCreated()->json('data.ticket.id');
    }

    public function test_closed_support_ticket_rejects_reply_and_attachment_without_orphaned_file(): void
    {
        Storage::fake('local');
        $this->actor();
        $id = $this->ticket();
        DB::table('m22_support_tickets')->where('id', $id)->update(['status' => 'closed']);
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', [
            'body' => 'Should not reopen a closed ticket',
        ])->assertStatus(409);
        $this->post('/api/v1/support/tickets/'.$id.'/attachments', [
            'file' => UploadedFile::fake()->image('proof.png', 16, 16),
        ])->assertStatus(409);
        $this->assertDatabaseHas('m22_support_tickets', ['id' => $id, 'status' => 'closed']);
        $this->assertDatabaseCount('m22_support_replies', 0);
        $this->assertDatabaseCount('m22_support_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('m22/support/'.$id));
    }

    public function test_support_tickets_are_account_private_and_admin_notes_never_enter_customer_export(): void
    {
        Storage::fake('local');
        $owner = $this->actor();
        $id = $this->ticket();
        $this->post('/api/v1/support/tickets/'.$id.'/attachments', [
            'file' => UploadedFile::fake()->image('proof.png', 16, 16),
        ])->assertCreated();
        $attachmentId = DB::table('m22_support_attachments')->where('ticket_id',$id)->value('id');
        $outsider = $this->actor();
        $this->getJson('/api/v1/support/tickets/'.$id)->assertNotFound();
        $this->postJson('/api/v1/support/tickets/'.$id.'/replies', [
            'body' => 'Try to cross accounts',
        ])->assertNotFound();
        $this->get('/api/v1/support/tickets/'.$id.'/attachments/'.$attachmentId)
            ->assertNotFound();
        $this->getJson('/api/v1/me/export')->assertOk()
            ->assertJsonCount(0, 'data.support_tickets');

        $admin = User::factory()->create([
            'is_active' => true, 'is_platform_admin' => true,
        ]);
        $this->actingAs($admin)->post('/admin/support/'.$id.'/replies', [
            'body' => 'Confidential staff-only investigation',
            'internal' => 1,
        ])->assertRedirect();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/support/tickets/'.$id)->assertOk()
            ->assertDontSee('Confidential staff-only investigation');
        $this->getJson('/api/v1/me/export')->assertOk()
            ->assertJsonCount(1, 'data.support_tickets')
            ->assertDontSee('Confidential staff-only investigation')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_deletion_clears_private_file_and_reset_grants_but_keeps_company_integrity(): void
    {
        Storage::fake('local');
        $user = $this->actor();
        $originalEmail = $user->email;
        $id = $this->ticket();
        $this->post('/api/v1/support/tickets/'.$id.'/attachments', [
            'file' => UploadedFile::fake()->image('receipt.png', 16, 16),
        ])->assertCreated();
        $privatePath = DB::table('m22_support_attachments')->where('ticket_id', $id)
            ->value('private_path');
        Storage::disk('local')->assertExists($privatePath);

        $owner = User::factory()->create(['is_active'=>true]);
        $company = Company::create([
            'owner_user_id'=>$owner->id, 'name'=>'Retained Company',
            'country_iso_code'=>'CH', 'status'=>'active',
        ]);
        $company->users()->attach($user->id, [
            'role'=>'member', 'status'=>'active',
        ]);
        DB::table('password_reset_tokens')->insert([
            'email'=>$originalEmail, 'token'=>hash('sha256', 'test-reset'),
            'created_at'=>now(),
        ]);
        $this->postJson('/api/v1/me/delete', [
            'password'=>'Initial!123Pwd',
        ])->assertOk()->assertJsonPath('data.deleted', true);
        Storage::disk('local')->assertMissing($privatePath);
        $this->assertDatabaseMissing('password_reset_tokens', ['email'=>$originalEmail]);
        $this->assertDatabaseMissing('m22_support_tickets', ['id'=>$id]);
        $this->assertDatabaseHas('companies', [
            'id'=>$company->id, 'owner_user_id'=>$owner->id,
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id'=>$company->id, 'user_id'=>$user->id, 'status'=>'inactive',
        ]);
        $this->assertFalse($user->fresh()->is_active);
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_nonadministrator_cannot_open_admin_support_or_edit_faqs(): void
    {
        $user = $this->actor();
        $this->actingAs($user);
        $this->get('/admin/support')->assertForbidden();
        $this->post('/admin/support/faqs', [
            'locale'=>'en', 'category'=>'general', 'question'=>'Unauthorised FAQ',
            'answer'=>'Must not be written', 'sort_order'=>0, 'is_published'=>1,
        ])->assertForbidden();
        $this->assertDatabaseCount('m22_support_faqs', 0);
    }
}
