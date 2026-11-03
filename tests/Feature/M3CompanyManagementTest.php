<?php

namespace Tests\Feature;

use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M3CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function account(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
    }

    private function createCompany(User $user, string $name): int
    {
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/companies', [
            'name' => $name,
            'country_iso_code' => 'CH',
            'address' => 'Sample Street, Zürich',
            'email' => 'office@example.test',
            'swiss_company_id' => 'CHE-123.456.789',
            'vat_number' => 'CHE-123.456.789 MWST',
            'iban' => 'CH9300762011623852957',
            'qr_iban' => 'CH9300762011623852957',
            'document_numbering' => 'Q-1000',
        ])->assertCreated();
        return $response->json('data.company.id');
    }

    public function test_company_creation_keeps_all_current_business_fields_and_owner_membership(): void
    {
        $owner = $this->account('owner@example.test');
        $id = $this->createCompany($owner, 'Swiss Workshop');
        $this->assertDatabaseHas('companies', [
            'id' => $id, 'owner_user_id' => $owner->id,
            'name' => 'Swiss Workshop', 'document_numbering' => 'Q-1000',
            'swiss_company_id' => 'CHE-123.456.789',
            'vat_number' => 'CHE-123.456.789 MWST',
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $owner->id,
            'role' => 'owner', 'status' => 'active',
        ]);
        $this->assertSame($id, (int) $owner->fresh()->active_company_id);
        $this->getJson('/api/v1/companies/'.$id)->assertOk()
            ->assertJsonPath('data.company.document_numbering', 'Q-1000')
            ->assertJsonPath('data.company.email', 'office@example.test');
    }

    public function test_user_can_create_multiple_companies_and_server_tracks_switch(): void
    {
        $owner = $this->account('owner@example.test');
        $first = $this->createCompany($owner, 'Company One');
        $second = $this->createCompany($owner, 'Company Two');

        $this->getJson('/api/v1/companies')->assertOk()
            ->assertJsonCount(2, 'data.companies')
            ->assertJsonPath('data.active_company_id', $second);

        $this->postJson('/api/v1/companies/'.$first.'/switch')->assertOk()
            ->assertJsonPath('data.active_company_id', $first);
        $this->assertSame($first, (int) $owner->fresh()->active_company_id);
    }

    public function test_unrelated_user_cannot_read_modify_switch_or_manage_foreign_company(): void
    {
        $owner = $this->account('owner@example.test');
        $foreign = $this->account('foreign@example.test');
        $id = $this->createCompany($owner, 'Secret Company');
        Sanctum::actingAs($foreign);

        $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(0, 'data.companies');
        $this->getJson('/api/v1/companies/'.$id)->assertForbidden();
        $this->patchJson('/api/v1/companies/'.$id, ['name' => 'Hacked'])->assertForbidden();
        $this->postJson('/api/v1/companies/'.$id.'/switch')->assertForbidden();
        $this->getJson('/api/v1/companies/'.$id.'/members')->assertForbidden();
        $this->postJson('/api/v1/companies/'.$id.'/invitations', [
            'email' => 'victim@example.test', 'role' => 'member',
        ])->assertForbidden();
        $this->assertDatabaseHas('companies', ['id' => $id, 'name' => 'Secret Company']);
    }

    public function test_company_member_can_read_but_cannot_edit_or_invite(): void
    {
        $owner = $this->account('owner@example.test');
        $member = $this->account('member@example.test');
        $id = $this->createCompany($owner, 'Workshop');
        Company::findOrFail($id)->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active', 'permissions' => '["clients.read"]',
        ]);
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/companies/'.$id)->assertOk();
        $this->getJson('/api/v1/companies/'.$id.'/members')->assertOk();
        $this->patchJson('/api/v1/companies/'.$id, ['name' => 'Not allowed'])->assertForbidden();
        $this->postJson('/api/v1/companies/'.$id.'/invitations', [
            'email' => 'someone@example.test', 'role' => 'member',
        ])->assertForbidden();
    }

    public function test_suspended_company_or_inactive_membership_cannot_be_selected(): void
    {
        $user = $this->account('owner@example.test');
        $id = $this->createCompany($user, 'Company');
        Company::findOrFail($id)->update(['status' => 'suspended']);
        $this->postJson('/api/v1/companies/'.$id.'/switch')->assertForbidden();
        $this->getJson('/api/v1/companies')->assertJsonCount(0, 'data.companies');
    }

    public function test_owner_can_invite_email_and_invited_account_only_can_accept(): void
    {
        $owner = $this->account('owner@example.test');
        $invitee = $this->account('invitee@example.test');
        $other = $this->account('wrong@example.test');
        $id = $this->createCompany($owner, 'Team Company');
        $result = $this->postJson('/api/v1/companies/'.$id.'/invitations', [
            'email' => 'invitee@example.test', 'role' => 'member',
            'permissions' => ['clients.read', 'jobs.read'],
        ])->assertCreated();
        $result->assertJsonMissingPath('data.invitation.token_hash')
            ->assertJsonMissingPath('data.invitation.token');
        $code = null;
        Mail::assertSent(CompanyInvitationMail::class,
            function (CompanyInvitationMail $mail) use (&$code): bool {
                $code = $mail->inviteCode;
                return true;
            });
        $this->assertSame(64, strlen($code));

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])->assertUnprocessable();
        $this->getJson('/api/v1/companies/'.$id)->assertForbidden();

        Sanctum::actingAs($invitee);
        $this->getJson('/api/v1/companies/invitations/mine')
            ->assertOk()->assertJsonCount(1, 'data.invitations');
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])
            ->assertOk()->assertJsonPath('data.active_company_id', $id);
        $this->getJson('/api/v1/companies/'.$id)->assertOk();
        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $invitee->id, 'role' => 'member',
        ]);
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])->assertUnprocessable();
    }

    public function test_owner_cannot_be_demoted_or_removed_and_admin_cannot_promote_admin(): void
    {
        $owner = $this->account('owner@example.test');
        $admin = $this->account('admin@example.test');
        $member = $this->account('member@example.test');
        $id = $this->createCompany($owner, 'Team Company');
        $company = Company::findOrFail($id);
        $company->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active']);
        $company->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
        $this->deleteJson('/api/v1/companies/'.$id.'/members/'.$owner->id)->assertForbidden();
        $this->patchJson('/api/v1/companies/'.$id.'/members/'.$owner->id, [
            'role' => 'member',
        ])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/companies/'.$id.'/members/'.$member->id, [
            'role' => 'admin',
        ])->assertUnprocessable();
        $this->deleteJson('/api/v1/companies/'.$id.'/members/'.$owner->id)->assertForbidden();
        $this->deleteJson('/api/v1/companies/'.$id.'/members/'.$member->id)->assertOk();
    }

    public function test_owner_can_revoke_invitation_and_code_cannot_be_replayed(): void
    {
        $owner = $this->account('owner@example.test');
        $invitee = $this->account('person@example.test');
        $id = $this->createCompany($owner, 'Team Company');
        $inviteId = $this->postJson('/api/v1/companies/'.$id.'/invitations', [
            'email' => 'person@example.test', 'role' => 'member',
        ])->assertCreated()->json('data.invitation.id');
        $code = null;
        Mail::assertSent(CompanyInvitationMail::class, function ($m) use (&$code) {
            $code = $m->inviteCode;
            return true;
        });
        $this->deleteJson('/api/v1/companies/'.$id.'/invitations/'.$inviteId)->assertOk();
        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])->assertUnprocessable();
        $this->assertDatabaseMissing('company_user', ['company_id' => $id, 'user_id' => $invitee->id]);
    }

    public function test_company_logo_and_terms_are_private_and_access_controlled(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner@example.test');
        $stranger = $this->account('stranger@example.test');
        $id = $this->createCompany($owner, 'File Company');
        $file = UploadedFile::fake()->create('terms.pdf', 20, 'application/pdf');

        $this->post('/api/v1/companies/'.$id.'/files/terms', [
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.company.has_terms', true);

        $this->get('/api/v1/companies/'.$id.'/files/terms')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        Sanctum::actingAs($stranger);
        $this->get('/api/v1/companies/'.$id.'/files/terms')->assertForbidden();
        $this->deleteJson('/api/v1/companies/'.$id.'/files/terms')->assertForbidden();
    }

    public function test_company_permission_guard_is_not_an_insecure_role_claim(): void
    {
        $owner = $this->account('owner@example.test');
        $member = $this->account('member@example.test');
        $id = $this->createCompany($owner, 'Permission Company');
        Company::findOrFail($id)->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active', 'permissions' => '["clients.read"]',
        ]);
        Sanctum::actingAs($member);
        $this->withHeader('X-Company-Id', (string) $id)
            ->getJson('/api/v1/company-context')->assertOk();
        $this->patchJson('/api/v1/companies/'.$id, [
            'name' => 'Not Admin', 'role' => 'owner', 'permissions' => ['*'],
        ])->assertForbidden();
        $this->assertDatabaseHas('companies', ['id' => $id, 'name' => 'Permission Company']);
    }

    public function test_admin_panel_company_forms_still_work_without_mobile_otp(): void
    {
        $admin = $this->account('platform@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/companies')->assertOk()->assertSee('Companies');
        $this->assertDatabaseCount('company_invitations', 0);
    }

    public function test_admin_can_issue_and_revoke_company_invitations_without_displaying_token(): void
    {
        $owner = $this->account('companyowner@example.test');
        $companyId = $this->createCompany($owner, 'Admin Managed Company');
        $admin = $this->account('platform-admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);

        $this->get('/admin/companies/'.$companyId.'/edit')->assertOk()
            ->assertSee('Email Invitations')
            ->assertSee('Company Files')
            ->assertSee('Team Members & Roles', false);

        $this->post('/admin/companies/'.$companyId.'/invitations', [
            'email' => 'new-team@example.test',
            'role' => 'member',
        ])->assertRedirect();

        $invitation = CompanyInvitation::firstOrFail();
        $code = null;
        Mail::assertSent(CompanyInvitationMail::class, function ($mail) use (&$code) {
            $code = $mail->inviteCode;
            return true;
        });
        $this->assertSame('new-team@example.test', $invitation->email);
        $this->get('/admin/companies/'.$companyId.'/edit')->assertOk()
            ->assertSee('new-team@example.test')
            ->assertDontSee($code)
            ->assertDontSee($invitation->token_hash);

        $this->delete('/admin/companies/'.$companyId.'/invitations/'.$invitation->id)
            ->assertRedirect();
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_admin_can_manage_private_company_terms_without_changing_owner(): void
    {
        Storage::fake('local');
        $owner = $this->account('companyowner@example.test');
        $companyId = $this->createCompany($owner, 'Files Managed');
        $admin = $this->account('platform-admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);

        $this->post('/admin/companies/'.$companyId.'/files/terms', [
            'file' => UploadedFile::fake()->create('general-terms.pdf', 22, 'application/pdf'),
        ])->assertRedirect();
        $company = Company::findOrFail($companyId);
        $this->assertSame('general-terms.pdf', $company->terms_original_name);
        $this->assertNotNull($company->terms_path);
        $this->get('/admin/companies/'.$companyId.'/files/terms')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->delete('/admin/companies/'.$companyId.'/files/terms')->assertRedirect();
        $this->assertNull(Company::findOrFail($companyId)->terms_path);
        $this->assertDatabaseHas('companies', [
            'id' => $companyId, 'owner_user_id' => $owner->id,
        ]);
    }

    public function test_non_platform_admin_cannot_open_administrator_file_or_invitation_routes(): void
    {
        $owner = $this->account('owner@example.test');
        $id = $this->createCompany($owner, 'Private Company');
        $member = $this->account('member@example.test');
        $this->actingAs($member);

        $this->post('/admin/companies/'.$id.'/invitations', [
            'email' => 'attacker@example.test', 'role' => 'admin',
        ])->assertForbidden();
        $this->post('/admin/companies/'.$id.'/files/terms', [
            'file' => UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
        $this->assertDatabaseCount('company_invitations', 0);
    }
}
