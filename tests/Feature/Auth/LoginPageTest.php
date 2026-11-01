<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_uses_the_redesigned_institutional_layout(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('login-container')
            ->assertSee('login-overlay')
            ->assertSee('login-form-panel')
            ->assertSee('Welcome Back')
            ->assertSee('Sign in to continue to TIMS-BARMM.')
            ->assertSee('RGB-DOLE-LOGO-WITH-BLACK-WORDMARK-PNG.png', false)
            ->assertSee('Department of Labor and Employment')
            ->assertSee('TUPAD INFORMATION MANAGEMENT SYSTEM OF BARMM.')
            ->assertSee('DOLE 12 - South Cotabato')
            ->assertSee('Username')
            ->assertSee('id="username"', false)
            ->assertSee('id="password"', false)
            ->assertSee('id="passwordToggle"', false)
            ->assertSee('type="password"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('Remember me')
            ->assertSee('Sign In')
            ->assertSee('Authorized Personnel Only')
            ->assertSee('TIMS-BARMM | Department of Labor and Employment')
            ->assertSee(route('login.attempt'), false)
            ->assertSee('name="_token"', false);
    }

    public function test_invalid_credentials_show_a_clear_generic_error(): void
    {
        User::factory()->create([
            'username' => 'staff_user',
            'password' => 'secret-password',
        ]);

        $this->from(route('login'))->post(route('login.attempt'), [
            'username' => 'staff_user',
            'password' => 'wrong-password',
        ])->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Invalid username or password. Please try again.');
    }

    public function test_remember_me_is_preserved_on_the_form(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post(route('login.attempt'), [
            'username' => $user->username,
            'password' => 'secret-password',
            'remember' => '1',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }
}
