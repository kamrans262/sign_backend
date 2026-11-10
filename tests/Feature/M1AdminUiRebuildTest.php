<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M1AdminUiRebuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_stylesheet_and_approved_logo_are_accessible_without_login(): void
    {
        $stylesheet = $this->get('/admin/assets/admin.css')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8');

        // BinaryFileResponse is deliberately streamed; assert its real file.
        $this->assertSame(
            realpath(public_path('admin-assets/admin.css')),
            realpath($stylesheet->baseResponse->getFile()->getPathname())
        );
        $css = file_get_contents($stylesheet->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('--primary:#166282;', $css);
        $this->assertStringContainsString('.app-shell', $css);

        $this->get('/admin/assets/logo.png')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_arbitrary_asset_names_are_not_exposed(): void
    {
        $this->get('/admin/assets/example.php')->assertNotFound();
        $this->get('/admin/assets/.env')->assertNotFound();
    }

    public function test_login_page_uses_route_backed_assets_and_app_styling(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Admin Panel')
            ->assertSee('/admin/assets/admin.css?v=6', false)
            ->assertSee('/admin/assets/logo.png', false)
            ->assertSee('class="login-panel"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_all_admin_pages_use_branded_shell_and_render_svg_icons(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $user = User::factory()->create();
        $company = Company::create([
            'owner_user_id' => $user->id,
            'name' => 'Test Business',
            'country_iso_code' => 'CH',
            'status' => 'active',
        ]);
        $company->users()->attach($user->id, [
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        foreach ([
            '/admin' => 'Welcome back,',
            '/admin/users' => 'User Management',
            '/admin/users/create' => 'Add New User',
            '/admin/users/'.$user->id.'/edit' => 'Manage User',
            '/admin/companies' => 'Company Management',
            '/admin/companies/create' => 'Add New Company',
            '/admin/companies/'.$company->id.'/edit' => 'Manage Company',
            '/admin/audit' => 'Audit Trail',
        ] as $url => $heading) {
            $this->get($url)
                ->assertOk()
                ->assertSee($heading)
                ->assertSee('/admin/assets/admin.css?v=6', false)
                ->assertSee('/admin/assets/logo.png', false)
                ->assertSee('class="app-shell"', false)
                ->assertSee('<svg', false)
                ->assertSee('class="mobile-menu"', false);
        }
    }

    public function test_company_form_retains_all_existing_required_fields(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);
        $response = $this->get('/admin/companies/create')->assertOk();

        foreach ([
            'owner_user_id', 'name', 'status', 'address',
            'email', 'phone_number', 'country_iso_code',
            'dialing_code', 'swiss_company_id', 'vat_number',
            'iban', 'qr_iban', 'document_numbering',
        ] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_user_form_retains_security_and_access_controls(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $response = $this->actingAs($admin)->get('/admin/users/create')->assertOk();
        foreach ([
            'name', 'email', 'phone_number', 'password',
            'password_confirmation', 'is_active', 'is_platform_admin',
        ] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_non_admin_user_cannot_access_new_management_shell(): void
    {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser)
            ->get('/admin')
            ->assertForbidden();
    }
}
