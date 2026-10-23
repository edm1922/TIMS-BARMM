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

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF]);
    }

    public function test_the_sidebar_renders_every_navigation_entry(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertSee('app-sidebar')
            ->assertSee('Dashboard')
            ->assertSee('Beneficiaries')
            ->assertSee('Batches')
            ->assertSee('Excel Import')
            ->assertSee('ID Cards')
            ->assertSee('Reports')
            ->assertSee('Audit log');
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
}
