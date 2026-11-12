<?php

namespace Tests\Feature;

use App\Mail\CompanyInvitationMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyCustomRolesTest extends TestCase
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
            'email' => $email, 'email_verified_at' => now(), 'is_active' => true,
        ]);
    }

    private function company(User $owner, string $name): int
    {
        Sanctum::actingAs($owner);
        return (int) $this->postJson('/api/v1/companies', [
            'name' => $name, 'country_iso_code' => 'CH',
        ])->assertCreated()->json('data.company.id');
    }

    private function role(int $company, string $name, array $permissions): int
    {
        return (int) $this->postJson("/api/v1/companies/$company/roles", [
            'name' => $name, 'permissions' => $permissions,
        ])->assertCreated()->json('data.role.id');
    }

    public function test_only_owner_can_create_edit_and_remove_company_roles(): void
    {
        $owner = $this->account('roles-owner@example.test');
        $member = $this->account('roles-member@example.test');
        $admin = $this->account('roles-admin@example.test');
        $companyId = $this->company($owner, 'Role Company');
        $company = Company::findOrFail($companyId);
        $company->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active', 'permissions' => '[]',
        ]);
        $company->users()->attach($admin->id, [
            'role' => 'admin', 'status' => 'active', 'permissions' => '[]',
        ]);

        $roleId = $this->role($companyId, 'Project Supervisor',
            ['clients.read', 'jobs.read', 'jobs.write']);
        $this->getJson("/api/v1/companies/$companyId/roles")->assertOk()
            ->assertJsonCount(1, 'data.roles');
        $this->postJson("/api/v1/companies/$companyId/roles", [
            'name' => 'project supervisor', 'permissions' => [],
        ])->assertUnprocessable();
        $this->postJson("/api/v1/companies/$companyId/roles", [
            'name' => 'Owner', 'permissions' => [],
        ])->assertUnprocessable();
        $this->postJson("/api/v1/companies/$companyId/roles", [
            'name' => 'Incomplete', 'permissions' => ['jobs.write'],
        ])->assertUnprocessable();

        foreach ([$member, $admin] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson("/api/v1/companies/$companyId/roles", [
                'name' => 'Forbidden', 'permissions' => ['jobs.read'],
            ])->assertForbidden();
            $this->putJson("/api/v1/companies/$companyId/roles/$roleId", [
                'name' => 'Renamed', 'permissions' => [],
            ])->assertForbidden();
            $this->deleteJson("/api/v1/companies/$companyId/roles/$roleId")
                ->assertForbidden();
        }
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/companies/$companyId/roles/$roleId", [
            'name' => 'Senior Supervisor',
            'permissions' => ['clients.read', 'jobs.read'],
        ])->assertOk()->assertJsonPath('data.role.name', 'Senior Supervisor');
        $this->deleteJson("/api/v1/companies/$companyId/roles/$roleId")->assertOk();
        $this->assertDatabaseCount('company_roles', 0);
    }

    public function test_custom_role_grants_are_live_and_cross_company_role_ids_are_rejected(): void
    {
        $owner = $this->account('tenant-one-owner@example.test');
        $otherOwner = $this->account('tenant-two-owner@example.test');
        $member = $this->account('role-grants-member@example.test');
        $companyId = $this->company($owner, 'Tenant One');
        $target = Company::findOrFail($companyId);
        $target->users()->attach($member->id, [
            'role' => 'member', 'status' => 'active', 'permissions' => '[]',
        ]);
        $roleId = $this->role($companyId, 'Site Supervisor',
            ['clients.read', 'clients.write', 'jobs.read']);

        $this->patchJson("/api/v1/companies/$companyId/members/$member->id", [
            'role' => 'member', 'company_role_id' => $roleId,
        ])->assertOk()->assertJsonPath('data.member.company_role_id', $roleId)
            ->assertJsonPath('data.member.role_name', 'Site Supervisor');
        $this->getJson("/api/v1/companies/$companyId/roles")
            ->assertJsonPath('data.roles.0.assigned_members', 1);
        $this->deleteJson("/api/v1/companies/$companyId/roles/$roleId")
            ->assertUnprocessable();

        Sanctum::actingAs($member);
        $this->withHeader('X-Company-Id', (string) $companyId);
        $this->getJson('/api/v1/clients')->assertOk();
        $this->postJson('/api/v1/clients', [])->assertUnprocessable();
        $this->getJson('/api/v1/jobs')->assertOk();
        $this->postJson('/api/v1/jobs', [])->assertForbidden();
        $this->getJson('/api/v1/invoices')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/companies/$companyId/roles/$roleId", [
            'name' => 'Site Supervisor',
            'permissions' => ['jobs.read'],
        ])->assertOk();

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->getJson('/api/v1/jobs')->assertOk();
        $this->getJson("/api/v1/companies/$companyId")
            ->assertJsonPath('data.company.permissions', ['jobs.read']);
        $this->postJson("/api/v1/companies/$companyId/roles", [
            'name' => 'Unauthorized', 'permissions' => ['clients.read'],
        ])->assertForbidden();

        $otherCompany = $this->company($otherOwner, 'Tenant Two');
        $otherRole = $this->role($otherCompany, 'Other Company Role', ['jobs.read']);
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/companies/$companyId/members/$member->id", [
            'role' => 'member', 'company_role_id' => $otherRole,
        ])->assertNotFound();
        $this->getJson("/api/v1/companies/$otherCompany/roles")
            ->assertForbidden();
    }

    public function test_invitation_uses_latest_role_permissions_when_accepted(): void
    {
        $owner = $this->account('invite-role-owner@example.test');
        $invitee = $this->account('invite-role-member@example.test');
        $companyId = $this->company($owner, 'Role Invite Company');
        $roleId = $this->role($companyId, 'Estimator', ['quotes.read']);

        $this->postJson("/api/v1/companies/$companyId/invitations", [
            'email' => $invitee->email, 'role' => 'member',
            'company_role_id' => $roleId,
        ])->assertCreated()->assertJsonPath('data.invitation.role_name', 'Estimator');
        $this->deleteJson("/api/v1/companies/$companyId/roles/$roleId")
            ->assertUnprocessable();
        $code = null;
        Mail::assertSent(CompanyInvitationMail::class,
            function (CompanyInvitationMail $mail) use (&$code, $invitee): bool {
                if (!$mail->hasTo($invitee->email)) return false;
                $code = $mail->inviteCode;
                return true;
            });
        $this->assertNotNull($code);
        $this->putJson("/api/v1/companies/$companyId/roles/$roleId", [
            'name' => 'Lead Estimator',
            'permissions' => ['quotes.read', 'quotes.write'],
        ])->assertOk();

        Sanctum::actingAs($invitee);
        $this->getJson('/api/v1/companies/invitations/mine')
            ->assertJsonPath('data.invitations.0.role_name', 'Lead Estimator');
        $this->postJson('/api/v1/companies/invitations/accept', [
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.company.company_role_id', $roleId)
            ->assertJsonPath('data.company.permissions', ['quotes.read', 'quotes.write']);
        $this->postJson('/api/v1/companies/invitations/accept', [
            'code' => $code,
        ])->assertUnprocessable();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/companies/$companyId/roles/$roleId")
            ->assertUnprocessable();
        $this->patchJson("/api/v1/companies/$companyId/members/$invitee->id", [
            'role' => 'member', 'permissions' => ['clients.read'],
        ])->assertOk()->assertJsonPath('data.member.company_role_id', null);
        $this->deleteJson("/api/v1/companies/$companyId/roles/$roleId")
            ->assertOk();
    }
}
