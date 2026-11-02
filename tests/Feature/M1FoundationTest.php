<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class M1FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function companyFor(User $owner): Company
    {
        $company = Company::create([
            'owner_user_id' => $owner->id,
            'name' => 'Northstar Renovations GmbH',
            'country_iso_code' => 'CH',
            'status' => 'active',
        ]);
        $company->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        return $company;
    }

    public function test_public_health_remains_available(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_account_and_company_context_require_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeader('X-Company-Id', '1')->getJson('/api/v1/company-context')->assertUnauthorized();
    }

    public function test_authorized_user_can_only_see_own_company_context(): void
    {
        $owner = User::factory()->create();
        $company = $this->companyFor($owner);
        $otherOwner = User::factory()->create();
        $otherCompany = $this->companyFor($otherOwner);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.companies.0.id', $company->id)
            ->assertJsonMissing(['id' => $otherCompany->id]);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/company-context')->assertOk()
            ->assertJsonPath('data.id', $company->id);
        $this->withHeader('X-Company-Id', (string) $otherCompany->id)
            ->getJson('/api/v1/company-context')->assertForbidden();
    }

    public function test_missing_invalid_or_suspended_company_is_rejected(): void
    {
        $owner = User::factory()->create();
        $company = $this->companyFor($owner);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/company-context')->assertUnprocessable();
        $this->withHeader('X-Company-Id', 'not-an-id')->getJson('/api/v1/company-context')->assertUnprocessable();
        $company->update(['status' => 'suspended']);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/company-context')->assertForbidden();
    }

    public function test_inactive_membership_and_inactive_user_are_rejected(): void
    {
        $owner = User::factory()->create();
        $company = $this->companyFor($owner);
        Sanctum::actingAs($owner);
        $company->users()->updateExistingPivot($owner->id, ['status' => 'inactive']);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/company-context')->assertForbidden();
        $company->users()->updateExistingPivot($owner->id, ['status' => 'active']);
        $owner->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_regular_accounts_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->post('/admin/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_platform_administrator_can_sign_in_and_view_dashboard(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'password' => Hash::make('password123456'),
        ]);
        $this->post('/admin/login', [
            'email' => $admin->email, 'password' => 'password123456',
        ])->assertRedirect(route('admin.dashboard'));
        $this->get('/admin')->assertOk()->assertSee('Platform overview');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform_admin.login', 'actor_user_id' => $admin->id]);
    }

    public function test_admin_can_create_company_with_owner_membership_and_audit(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create();
        $this->actingAs($admin)->post('/admin/companies', [
            'owner_user_id' => $owner->id,
            'name' => 'Test Workshop',
            'country_iso_code' => 'CH',
            'status' => 'active',
        ])->assertRedirect();
        $company = Company::where('name', 'Test Workshop')->firstOrFail();
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.company.created', 'company_id' => $company->id,
        ]);
    }

    public function test_admin_cannot_remove_company_owner_without_transferring_ownership(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create();
        $company = $this->companyFor($owner);
        $this->actingAs($admin)->delete('/admin/companies/'.$company->id.'/members/'.$owner->id)
            ->assertSessionHasErrors('membership');
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
    }

    public function test_admin_cannot_remove_own_administrator_status(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->put('/admin/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => 0,
            'is_platform_admin' => 0,
        ])->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_platform_admin);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_deactivated_accounts_lose_all_api_tokens(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $user = User::factory()->create();
        $user->createToken('test device');
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->actingAs($admin)->put('/admin/users/'.$user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => 0,
            'is_platform_admin' => 0,
        ])->assertRedirect();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_company_role_enforcement_blocks_regular_members(): void
    {
        $owner = User::factory()->create();
        $company = $this->companyFor($owner);
        $member = User::factory()->create();
        $company->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);

        \Illuminate\Support\Facades\Route::middleware([
            'auth:sanctum', 'active.user', 'company.access', 'company.role:owner,admin',
        ])->get('/api/m1-test-company-manage', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($member);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/m1-test-company-manage')->assertForbidden();

        Sanctum::actingAs($owner);
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/m1-test-company-manage')->assertOk();
    }

    public function test_every_m1_admin_page_renders_for_a_platform_administrator(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create();
        $company = $this->companyFor($owner);

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('User management');
        $this->get('/admin/users/create')->assertOk()->assertSee('Add a new account');
        $this->get('/admin/users/'.$owner->id.'/edit')->assertOk()->assertSee('Manage account');
        $this->get('/admin/companies')->assertOk()->assertSee('Company management');
        $this->get('/admin/companies/create')->assertOk()->assertSee('Create a company');
        $this->get('/admin/companies/'.$company->id.'/edit')->assertOk()->assertSee('Manage company');
        $this->get('/admin/audit')->assertOk()->assertSee('Audit trail');
    }

    public function test_admin_can_manage_members_and_suspended_companies_stay_inaccessible(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $company = $this->companyFor($owner);

        $this->actingAs($admin)->post('/admin/companies/'.$company->id.'/members', [
            'user_id' => $member->id, 'role' => 'admin',
        ])->assertRedirect();
        $this->assertDatabaseHas('company_user', [
            'company_id' => $company->id, 'user_id' => $member->id, 'role' => 'admin', 'status' => 'active',
        ]);

        $this->put('/admin/companies/'.$company->id, [
            'name' => 'Suspended workshop',
            'owner_user_id' => $owner->id,
            'country_iso_code' => 'CH',
            'status' => 'suspended',
        ])->assertRedirect();
        $this->assertDatabaseHas('companies', [
            'id' => $company->id, 'status' => 'suspended',
        ]);

        $this->delete('/admin/companies/'.$company->id.'/members/'.$member->id)->assertRedirect();
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $company->id, 'user_id' => $member->id,
        ]);
    }

    public function test_non_platform_administrator_cannot_access_any_admin_management_screen(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->get('/admin/companies')->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
    }

    public function test_an_inactive_platform_administrator_is_denied(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'is_active' => false,
        ]);
        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }
}
