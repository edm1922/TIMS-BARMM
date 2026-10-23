<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_valid_admin_can_login_with_username_and_reaches_dashboard(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin_user',
            'email' => 'admin@tims.test',
            'password' => 'secret-password',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->post(route('login.attempt'), [
            'username' => 'admin_user',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);

        $this->get('/dashboard')->assertOk();
    }

    public function test_valid_admin_can_login_with_email_fallback(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin_user',
            'email' => 'admin@tims.test',
            'password' => 'secret-password',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->post(route('login.attempt'), [
            'username' => 'admin@tims.test',
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_page_renders_username_field(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Username')
            ->assertSee('name="username"', false)
            ->assertDontSee('name="email"', false);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create([
            'username' => 'staff_user',
            'email' => 'staff@tims.test',
            'password' => 'secret-password',
        ]);

        $response = $this->from(route('login'))->post(route('login.attempt'), [
            'username' => 'staff_user',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'off_user',
            'email' => 'off@tims.test',
            'password' => 'secret-password',
            'is_active' => false,
        ]);

        $this->post(route('login.attempt'), [
            'username' => 'off_user',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_logout_destroys_session(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post(route('login.attempt'), ['username' => $user->username, 'password' => 'secret-password']);
        $this->assertAuthenticated();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }

    public function test_role_middleware_blocks_non_admin(): void
    {
        Route::get('/test-admin-only', fn () => response('ok'))->middleware('role:admin');

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $this->actingAs($staff)->get('/test-admin-only')->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/test-admin-only')->assertOk();
    }
}
