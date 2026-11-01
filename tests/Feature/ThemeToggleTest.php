<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_layout_ships_the_light_dark_theme_control_and_persistence_script(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-bs-theme="light"', false)
            ->assertSee('data-theme-toggle', false)
            ->assertSee('Switch to dark theme')
            ->assertSee('theme-icon-sun', false)
            ->assertSee('theme-icon-moon', false)
            ->assertSee('tims-theme')
            ->assertSee('prefers-color-scheme: dark');
    }

    public function test_the_theme_toggle_is_available_on_operational_pages(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach ([route('beneficiaries.index'), route('reports.index'), route('users.index')] as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertSee('data-theme-toggle', false);
        }
    }
}
