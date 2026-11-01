<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * Laravel skips the token comparison while the suite runs, so the
     * environment is switched to make the browser's check happen.
     */
    private function enforceCsrf(): void
    {
        $this->app->instance('env', 'local');
    }

    public function test_a_post_with_a_stale_csrf_token_returns_to_the_form_instead_of_a_419_page(): void
    {
        $this->enforceCsrf();

        $response = $this->from('/login')->post('/login', [
            '_token' => 'stale-token',
            'username' => 'admin',
            'password' => 'secret-value',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('session expired', strtolower((string) session('error')));
    }

    public function test_the_recovered_page_keeps_the_input_but_never_the_password(): void
    {
        $this->enforceCsrf();

        $this->from('/login')->post('/login', [
            '_token' => 'stale-token',
            'username' => 'admin',
            'password' => 'secret-value',
        ]);

        $this->assertSame('admin', session('_old_input.username'));
        $this->assertNull(session('_old_input.password'));
        $this->assertNull(session('_old_input._token'));
    }

    public function test_a_stale_token_on_a_regular_form_keeps_the_user_signed_in(): void
    {
        $this->enforceCsrf();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->from('/sync-servers')
            ->post('/sync-servers/enrollment-token', [
                '_token' => 'stale-token',
                'note' => 'Basilan rollout',
                'authorization_days' => 45,
            ])
            ->assertRedirect('/sync-servers')
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_stale_token_on_logout_still_signs_the_user_out(): void
    {
        $this->enforceCsrf();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/logout', ['_token' => 'stale-token'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_stale_token_never_redirects_off_this_installation(): void
    {
        $this->enforceCsrf();

        $response = $this->withHeaders(['Referer' => 'https://evil.example.test/steal'])
            ->post('/login', [
                '_token' => 'stale-token',
                'username' => 'admin',
                'password' => 'secret-value',
            ]);

        $this->assertStringNotContainsString('evil.example.test', (string) $response->headers->get('Location'));
    }
}
