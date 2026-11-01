<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ID Card feature was removed as obsolete. These guards make sure the
 * retired routes stay inaccessible and the navigation entry does not come
 * back. Historical audit records (action "id_card.printed") are retained and
 * deliberately not asserted here.
 */
class IdCardRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_the_id_card_routes_are_gone(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/id-cards')->assertNotFound();
        $this->actingAs($admin)->get('/id-cards/print')->assertNotFound();
    }

    public function test_the_beneficiary_card_route_is_gone(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries/1/card')->assertNotFound();
    }

    public function test_the_sidebar_no_longer_links_to_the_id_card_feature(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/beneficiaries')
            ->assertOk()
            ->assertDontSee('ID Cards')
            ->assertDontSee('/id-cards');
    }

    public function test_the_dashboard_no_longer_offers_id_card_generation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Generate ID cards')
            ->assertDontSee('/id-cards');
    }
}
