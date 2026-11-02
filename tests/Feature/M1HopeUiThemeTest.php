<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M1HopeUiThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hope_ui_adaptation_uses_approved_sign2digital_tokens(): void
    {
        $css = file_get_contents(public_path('admin-assets/admin.css'));
        $this->assertNotFalse($css);
        foreach ([
            '--primary:#166282',
            '--ink:#122536',
            '--hope-page:#f5f7fb',
            '.hope-welcome',
            '.hope-metric',
            '.hope-dashboard-columns',
            '.hope-auth-shell',
            '.hope-auth-hero',
            '@media(max-width:1000px)',
        ] as $token) {
            $this->assertStringContainsString($token, $css);
        }
    }

    public function test_login_keeps_working_form_and_uses_split_layout(): void
    {
        $this->get('/admin/login')->assertOk()
            ->assertSee('Welcome Back')
            ->assertSee('class="hope-auth-shell"', false)
            ->assertSee('class="hope-auth-hero"', false)
            ->assertSee('/admin/assets/admin.css?v=4', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_hope_dashboard_shows_real_counts_and_computed_percentages(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true, 'is_active' => true, 'name' => 'Platform Operator',
        ]);
        User::factory()->create(['is_active' => false]);
        Company::create(['owner_user_id' => $admin->id, 'name' => 'Current Workshop', 'status' => 'active']);
        Company::create(['owner_user_id' => $admin->id, 'name' => 'Paused Workshop', 'status' => 'suspended']);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Welcome back, Platform Operator!')
            ->assertSee('class="hope-welcome"', false)
            ->assertSee('class="metrics hope-metrics"', false)
            ->assertSee('50% of all users')
            ->assertSee('50% of all companies')
            ->assertSee('Current Workshop')
            ->assertSee('Paused Workshop')
            ->assertSee('Quick Actions')
            ->assertSee('class="app-shell"', false);
    }

    public function test_restyled_management_pages_keep_genuine_routes_and_controls(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->get('/admin/users')->assertOk()
            ->assertSee('User Management')
            ->assertSee('name="status"', false)
            ->assertSee('class="app-shell"', false);
        $this->get('/admin/companies')->assertOk()
            ->assertSee('Company Management')
            ->assertSee('name="status"', false);
        $this->get('/admin/audit')->assertOk()
            ->assertSee('Audit Trail')
            ->assertSee('name="action"', false);
    }
}
