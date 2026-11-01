<?php

namespace Tests\Feature;

use App\Models\SyncSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();
    }

    private function configureOffline(string $bootstrapUrl = 'https://central.test'): void
    {
        config([
            'sync.instance_mode' => 'offline',
            'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'sync.bootstrap_url' => $bootstrapUrl,
        ]);

        SyncSetting::flushCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enrollment_token' => 'one-time-token',
            'manager_name' => 'Area Manager',
            'manager_username' => 'areamanager',
            'manager_password' => 'correct-horse-battery',
            'manager_password_confirmation' => 'correct-horse-battery',
            'area_name' => 'Isabela City',
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ], $overrides);
    }

    private function fakeCentral(string $serverUuid = '33333333-3333-4333-8333-333333333333'): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => [
                    'protocol_version' => 1,
                    'minimum_protocol_version' => 1,
                    'schema_version' => 1,
                    'minimum_schema_version' => 1,
                ],
            ]),
            'https://central.test/api/sync/v1/enroll' => Http::response([
                'data' => [
                    'server_uuid' => $serverUuid,
                    'server_token' => 'tims_sync_enrolled_token',
                    'central_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'enrolled_at' => '2026-10-25T12:00:00+08:00',
                    'authorized_at' => '2026-10-25T12:00:00+08:00',
                    'authorization_expires_at' => '2027-10-25T12:00:00+08:00',
                    'authorization_status' => 'authorized',
                ],
            ], 201),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => '55555555-5555-4555-8555-555555555555',
                    'revision_to' => 1,
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/*/reference' => Http::response([
                'data' => [
                    'locations' => [],
                    'batches' => [],
                    'trainings' => [],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/*/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['has_more' => false, 'next_cursor' => null],
            ]),
        ]);
    }

    public function test_a_fresh_offline_installation_shows_the_onboarding_page(): void
    {
        $this->configureOffline();

        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('First-time setup')
            ->assertSee('https://central.test');
    }

    public function test_a_fresh_offline_installation_redirects_every_page_to_onboarding(): void
    {
        $this->configureOffline();

        $this->get('/beneficiaries')->assertRedirect(route('onboarding.show'));
        $this->get('/login')->assertRedirect(route('onboarding.show'));
    }

    public function test_the_onboarding_page_is_not_available_on_the_central_server(): void
    {
        $this->get('/onboarding')->assertRedirect(route('dashboard'));
    }

    public function test_onboarding_creates_the_manager_and_enrolls_the_device(): void
    {
        $this->configureOffline();
        $this->fakeCentral();

        $this->seededLocation([
            'psgc_code' => '1900702000',
            'province' => 'TEST PROVINCE',
            'city_municipality' => 'TEST CITY',
            'barangay' => 'TEST BARANGAY',
        ]);

        $this->post('/onboarding', $this->payload())
            ->assertRedirect(route('login'));

        $manager = User::query()->where('username', 'areamanager')->firstOrFail();

        $this->assertSame(User::ROLE_MANAGER, $manager->role);
        $this->assertSame('Area Manager', $manager->name);
        $this->assertSame('areamanager@managers.local', $manager->email);
        $this->assertTrue($manager->is_active);

        $this->assertSame('33333333-3333-4333-8333-333333333333', SyncSetting::get('server_uuid'));
        $this->assertSame('https://central.test', SyncSetting::get('central_url'));
        $this->assertSame('tims_sync_enrolled_token', SyncSetting::getEncrypted('server_token'));
        $this->assertSame('city_municipality', SyncSetting::get('scope_type'));
        $this->assertSame('1900702000', SyncSetting::get('scope_code'));
        $this->assertSame('Isabela City', SyncSetting::get('area_name'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://central.test/api/sync/v1/enroll'
            && $request['enrollment_token'] === 'one-time-token'
            && $request['name'] === 'Isabela City');
    }

    public function test_onboarding_does_not_run_a_second_time(): void
    {
        $this->configureOffline();
        $this->fakeCentral();

        $this->post('/onboarding', $this->payload())->assertRedirect(route('login'));

        $this->get('/onboarding')->assertRedirect(route('login'));
    }

    public function test_onboarding_requires_a_trusted_central_server_address(): void
    {
        $this->configureOffline('');
        $this->fakeCentral();

        $this->post('/onboarding', $this->payload())
            ->assertSessionHasErrors('enrollment_token');

        $this->assertSame(0, User::query()->count());
        $this->assertNull(SyncSetting::get('server_uuid'));
    }

    public function test_onboarding_rejects_a_scope_that_matches_no_location(): void
    {
        $this->configureOffline();
        $this->fakeCentral();

        $this->seededLocation(['psgc_code' => '1999905999']);

        $this->post('/onboarding', $this->payload([
            'scope_type' => 'province',
            'scope_code' => '9999',
        ]))->assertSessionHasErrors('scope_code');

        $this->assertSame(0, User::query()->count());
        $this->assertNull(SyncSetting::get('server_uuid'));
    }

    public function test_onboarding_enforces_the_password_policy(): void
    {
        $this->configureOffline();
        $this->fakeCentral();

        $this->post('/onboarding', $this->payload([
            'manager_password' => 'short',
            'manager_password_confirmation' => 'short',
        ]))->assertSessionHasErrors('manager_password');

        $this->assertSame(0, User::query()->count());
    }

    public function test_onboarding_rolls_back_the_manager_when_enrollment_is_rejected(): void
    {
        $this->configureOffline();

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/enroll' => Http::response([
                'error' => ['message' => 'Invalid enrollment token.'],
            ], 422),
        ]);

        $this->post('/onboarding', $this->payload())
            ->assertSessionHasErrors('enrollment_token');

        $this->assertSame(0, User::query()->count());
        $this->assertNull(SyncSetting::get('server_uuid'));
        $this->assertNull(SyncSetting::getEncrypted('server_token'));
    }

    public function test_an_existing_account_stops_the_onboarding_redirect(): void
    {
        $this->configureOffline();

        User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->get('/beneficiaries')->assertRedirect(route('login'));
    }
}
