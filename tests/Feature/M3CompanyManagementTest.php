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

    public function test_unverified_email_cannot_discover_or_accept_an_invitation(): void
    {
        $owner = $this->account('verified-owner@example.test');
        $invitee = $this->account('unverified-invitee@example.test');
        $id = $this->createCompany($owner, 'Verified Invitation Company');
        $this->postJson('/api/v1/companies/'.$id.'/invitations', [
            'email' => $invitee->email, 'role' => 'member',
        ])->assertCreated();

        $code = null;
        Mail::assertSent(CompanyInvitationMail::class, function ($m) use (&$code) {
            $code = $m->inviteCode;
            return true;
        });
        $invitee->forceFill(['email_verified_at' => null])->save();
        Sanctum::actingAs($invitee);
        $this->getJson('/api/v1/companies/invitations/mine')->assertForbidden();
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $id, 'user_id' => $invitee->id,
        ]);

        // A rejected attempt must not consume an otherwise valid invite.
        $invitee->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])
            ->assertOk()->assertJsonPath('data.active_company_id', $id);
        $this->postJson('/api/v1/companies/invitations/accept', ['code' => $code])
            ->assertUnprocessable();
    }

    public function test_permission_middleware_enforces_database_grants_and_revocation(): void
    {
        \Illuminate\Support\Facades\Route::middleware([
            'auth:sanctum', 'active.user', 'company.access',
            'company.permission:clients.read',
        ])->get('/api/v1/m3-permission-probe', fn () => response()->json(['ok' => true]));

        $owner = $this->account('permissions-owner@example.test');
        $member = $this->account('permissions-member@example.test');
        $otherOwner = $this->account('another-owner@example.test');
        $companyId = $this->createCompany($owner, 'Permission Guard Company');
        $otherCompany = $this->createCompany($otherOwner, 'Separate Company');
        $company = Company::findOrFail($companyId);
        $company->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active', 'permissions' => '[]',
        ]);

        Sanctum::actingAs($member);
        $path = '/api/v1/m3-permission-probe';
        $this->getJson($path)->assertUnprocessable();
        $this->withHeader('X-Company-Id', (string) $otherCompany)
            ->getJson($path)->assertForbidden();
        $this->withHeader('X-Company-Id', (string) $companyId)
            ->getJson($path)->assertForbidden();

        $company->users()->updateExistingPivot($member->id, [
            'permissions' => json_encode(['clients.read'], JSON_THROW_ON_ERROR),
        ]);
        $this->getJson($path)->assertOk()->assertJsonPath('ok', true);

        $company->users()->updateExistingPivot($member->id, ['status' => 'inactive']);
        $this->getJson($path)->assertForbidden();
        $company->users()->updateExistingPivot($member->id, ['status' => 'active']);
        $company->update(['status' => 'suspended']);
        $this->getJson($path)->assertForbidden();
    }

    public function test_company_upload_rejects_wrong_type_and_oversized_files(): void
    {
        Storage::fake('local');
        $owner = $this->account('uploads-owner@example.test');
        $companyId = $this->createCompany($owner, 'File Validation Company');

        $this->post('/api/v1/companies/'.$companyId.'/files/terms', [
            'file' => UploadedFile::fake()->create('invalid.png', 10, 'image/png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/companies/'.$companyId.'/files/logo', [
            'file' => UploadedFile::fake()->create('huge.png', 2200, 'image/png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertNull(Company::findOrFail($companyId)->logo_path);
        $this->assertNull(Company::findOrFail($companyId)->terms_path);
    }

    public function test_platform_admin_can_edit_member_role_grants_and_status_with_active_company_revocation(): void
    {
        $owner = $this->account('admin-member-owner@example.test');
        $member = $this->account('admin-member@example.test');
        $id = $this->createCompany($owner, 'Admin Team Company');
        Company::findOrFail($id)->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
            'permissions' => json_encode(['clients.read'], JSON_THROW_ON_ERROR),
        ]);
        $member->forceFill(['active_company_id' => $id])->save();

        $admin = $this->account('team-platform-admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);
        $url = '/admin/companies/'.$id.'/members/'.$member->id;

        $this->get($url.'/edit')->assertOk()
            ->assertSee('Manage Team Member')->assertSee('Save Member Access')
            ->assertSee('clients.read', false);
        $this->patch($url, [
            'role' => 'admin', 'status' => 'inactive',
            'permissions' => ['clients.read', 'jobs.write'],
        ])->assertRedirect('/admin/companies/'.$id.'/edit');

        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $member->id,
            'role' => 'admin', 'status' => 'inactive',
        ]);
        $this->assertNull($member->fresh()->active_company_id);
        $membership = Company::findOrFail($id)->users()
            ->where('users.id', $member->id)->firstOrFail();
        $this->assertSame(['clients.read', 'jobs.write'],
            json_decode($membership->pivot->permissions, true));

        $this->patch($url, ['role' => 'member', 'status' => 'active',
            'permissions' => ['quotes.read']])->assertRedirect();
        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active',
        ]);
    }

    public function test_admin_member_edit_cannot_change_owner_or_foreign_company_membership(): void
    {
        $owner = $this->account('owner-owner@example.test');
        $foreignOwner = $this->account('foreign-owner@example.test');
        $foreignMember = $this->account('foreign-member@example.test');
        $id = $this->createCompany($owner, 'Own Company');
        $foreign = $this->createCompany($foreignOwner, 'Another Company');
        Company::findOrFail($foreign)->users()->attach($foreignMember->id, [
            'role' => 'member', 'status' => 'active',
        ]);
        $admin = $this->account('strict-platform-admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);

        $ownerRoute = '/admin/companies/'.$id.'/members/'.$owner->id;
        $this->get($ownerRoute.'/edit')->assertForbidden();
        $this->patch($ownerRoute, [
            'role' => 'member', 'status' => 'inactive',
        ])->assertForbidden();
        $foreignRoute = '/admin/companies/'.$id.'/members/'.$foreignMember->id;
        $this->get($foreignRoute.'/edit')->assertNotFound();
        $this->patch($foreignRoute, [
            'role' => 'admin', 'status' => 'active',
        ])->assertNotFound();

        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $owner->id,
            'role' => 'owner', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $foreign, 'user_id' => $foreignMember->id,
            'role' => 'member',
        ]);
    }

    public function test_admin_membership_edits_require_valid_grants_and_platform_admin_session(): void
    {
        $owner = $this->account('permission-edit-owner@example.test');
        $member = $this->account('permission-edit-member@example.test');
        $id = $this->createCompany($owner, 'Permission Validation Company');
        Company::findOrFail($id)->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
        ]);
        $path = '/admin/companies/'.$id.'/members/'.$member->id;
        $this->actingAs($member);
        $this->get($path.'/edit')->assertForbidden();
        $this->patch($path, [
            'role' => 'admin', 'status' => 'active',
        ])->assertForbidden();

        $admin = $this->account('permission-validation-admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);
        $this->patch($path, [
            'role' => 'member', 'status' => 'active',
            'permissions' => ['clients.read', 'unrestricted.delete'],
        ])->assertSessionHasErrors('permissions.1');
        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active',
        ]);
    }

    public function test_admin_removing_member_clears_only_the_removed_company_selection(): void
    {
        $owner = $this->account('remove-admin-owner@example.test');
        $member = $this->account('remove-admin-member@example.test');
        $id = $this->createCompany($owner, 'Removal Company');
        $secondOwner = $this->account('second-admin-owner@example.test');
        $second = $this->createCompany($secondOwner, 'Unrelated Company');
        Company::findOrFail($id)->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
        ]);
        Company::findOrFail($second)->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active',
        ]);
        $member->forceFill(['active_company_id' => $id])->save();
        $admin = $this->account('remove-platform-admin@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);
        $this->delete('/admin/companies/'.$id.'/members/'.$member->id)
            ->assertRedirect();
        $this->assertNull($member->fresh()->active_company_id);
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $id, 'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $second, 'user_id' => $member->id,
        ]);
    }

    public function test_dashboard_has_one_icon_per_stat_and_no_redundant_overview_header(): void
    {
        $admin = $this->account('admin-ui-overview@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $response = $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Total Users')
            ->assertSee('Active Users')
            ->assertSee('Total Companies')
            ->assertSee('Active Companies')
            ->assertSee('Recent Companies')
            ->assertSee('Recent Activity')
            ->assertDontSee('Platform Console')
            ->assertDontSee('Secure Console')
            ->assertDontSee('Platform Overview')
            ->assertDontSee('Your Sign2Digital platform at a glance.')
            ->assertDontSee('Home <span class="eyebrow-separator">', false)
            ->assertDontSee('hope-ring');
        $html = $response->getContent();
        $this->assertSame(4, substr_count($html, 'class="hope-stat-icon"'));
        $this->assertSame(4, substr_count($html, 'class="metric hope-metric"'));
    }

    public function test_users_is_the_only_sidebar_account_module_and_retains_otp_history(): void
    {
        $admin = $this->account('admin-ui-users@example.test');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $users = $this->actingAs($admin)->get('/admin/users')->assertOk()
            ->assertSee('User Management')
            ->assertSee('Email OTP Activity')
            ->assertSee('Company Management')
            ->assertDontSee('Platform Console')
            ->assertDontSee('Secure Console');
        $content = $users->getContent();
        $this->assertSame(1, substr_count($content, '<span>Users</span>'));
        $this->assertSame(1, substr_count($content, '> Users</a>'));
        $this->assertStringNotContainsString('>Mobile Authentication</span>', $content);
        $this->assertStringNotContainsString('> Mobile Authentication</a>', $content);

        $history = $this->get('/admin/mobile-auth')->assertOk()
            ->assertSee('Email OTP Activity')
            ->assertSee('Mobile OTP Requests')
            ->assertSee('Back to Users');
        $this->assertSame(1, substr_count($history->getContent(), '<span>Users</span>'));
    }

    public function test_active_members_and_admins_cannot_create_extra_companies(): void
    {
        $owner = $this->account('qa-company-owner@example.test');
        $member = $this->account('qa-company-member@example.test');
        $id = $this->createCompany($owner, 'Original Company');
        $company = Company::findOrFail($id);
        $company->users()->attach($member->id, [
            'role' => 'admin', 'status' => 'active', 'permissions' => '[]',
        ]);
        $member->forceFill(['active_company_id' => $id])->save();
        Sanctum::actingAs($member);
        $this->postJson('/api/v1/companies', [
            'name' => 'Unauthorized Company',
        ])->assertForbidden();
        $this->assertDatabaseCount('companies', 1);
        $this->assertSame($id, (int) $member->fresh()->active_company_id);

        $company->users()->updateExistingPivot($member->id, ['role' => 'member']);
        $this->postJson('/api/v1/companies', [
            'name' => 'Unauthorized Member Company',
        ])->assertForbidden();
        $this->assertDatabaseCount('companies', 1);

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/companies', [
            'name' => 'Authorized Second Company',
        ])->assertCreated();
        $this->assertDatabaseCount('companies', 2);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
    }
}
