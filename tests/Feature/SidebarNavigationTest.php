<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    private function encoder(): User
    {
        return User::factory()->create(['role' => User::ROLE_ENCODER]);
    }

    public function test_the_sidebar_renders_every_navigation_entry_for_central_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('app-sidebar')
            ->assertSee('Dashboard')
            ->assertSee('Beneficiaries')
            ->assertSee('Batches')
            ->assertSee('Excel Import')
            ->assertSee('Reports')
            ->assertSee('Audit log')
            ->assertSee('Enroll Server')
            ->assertSee('Offline Servers')
            ->assertSee('Review inbox')
            ->assertDontSee('Conflict Resolution</span>', false)
            ->assertDontSee('>Sync Requests<', false);
    }

    public function test_the_sidebar_exposes_correct_sections_for_offline_manager(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = $this->manager();

        $response = $this->actingAs($manager)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('Enroll Server')
            ->assertSee('Encoders')
            ->assertSee('Audit log')
            ->assertSee('Reports');

        $response->assertDontSee('Offline Servers');
        $response->assertDontSee('Conflict Resolution');
        $response->assertDontSee('Users');
        $response->assertDontSee('Excel Import');
        $response->assertDontSee('ID Cards');
    }

    public function test_the_enroll_server_entry_points_to_the_installation_hub_on_the_central_server(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertSee(route('enroll-server.index'), false);
    }

    public function test_the_enroll_server_entry_points_to_the_local_setup_page_on_an_offline_installation(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $manager = $this->manager();

        $this->actingAs($manager)->get('/beneficiaries')
            ->assertOk()
            ->assertSee(route('sync.local.index'), false);
    }

    public function test_the_sidebar_marks_the_current_section_as_active(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('nav-link active', false);
    }

    public function test_staff_does_not_see_the_admin_only_audit_link(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('Beneficiaries')
            ->assertDontSee('Audit log');
    }

    public function test_encoder_sees_only_dashboard_beneficiaries_batches(): void
    {
        config()->set('sync.instance_mode', 'offline');

        $encoder = $this->encoder();

        $response = $this->actingAs($encoder)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Beneficiaries')
            ->assertSee('Batches');

        $response->assertDontSee('Excel Import');
        $response->assertDontSee('ID Cards');
        $response->assertDontSee('Reports');
        $response->assertDontSee('Audit log');
        $response->assertDontSee('Enroll Server');
        $response->assertDontSee('Encoders');
    }

    public function test_the_logout_form_is_available_in_the_sidebar(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('logout'))
            ->assertSee('Log out');
    }

    public function test_the_mobile_drawer_toggle_is_present(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('app-topbar', false)
            ->assertSee('data-bs-target="#appSidebar"', false);
    }

    public function test_the_login_page_has_no_sidebar(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('app-sidebar', false);
    }

    public function test_the_sidebar_exposes_collapse_avatar_theme_and_active_route_controls(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('id="appSidebar"', false)
            ->assertSee('id="sidebarToggle"', false)
            ->assertSee('aria-controls="appSidebar"', false)
            ->assertSee('app-sidebar-edge-toggle', false)
            ->assertSee('app-sidebar-avatar', false)
            ->assertSee('app-sidebar-user-copy', false)
            ->assertSee('app-sidebar-brand-subtitle', false)
            ->assertSee('DOLE 12')
            ->assertSee('tims-sidebar')
            ->assertSee('data-theme-toggle', false)
            ->assertSee('nav-link active', false)
            ->assertSee(route('logout'));
    }

    public function test_the_browser_tab_uses_the_dole_logo_as_the_favicon(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('rel="icon"', false)
            ->assertSee('favicon.ico', false)
            ->assertSee('favicon-32x32.png', false)
            ->assertSee('favicon-16x16.png', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('<meta name="theme-color" content="#172337">', false);
    }
}
