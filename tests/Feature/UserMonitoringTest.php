<?php

namespace Tests\Feature;

use App\Models\OfflineServer;
use App\Models\SyncSetting;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The central per-server "User Monitoring" view replaces the old read-only
 * "Reported encoders" card. It shows the Manager and the Encoder accounts the
 * installation reported, plus the device identity, without exposing secrets.
 */
class UserMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollServer(): array
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Cotabato Offline',
            'app_version' => '1.2.3',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'province',
            'scope_code' => '1900702000',
        ])->assertCreated();

        return $response->json('data');
    }

    public function test_the_central_server_stores_a_manager_and_encoder_registry(): void
    {
        $data = $this->enrollServer();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', [
                'events' => [],
                'encoders' => [
                    [
                        'encoder_uuid' => 'aaaaaaaa-1111-4111-8111-111111111111',
                        'username' => 'the_manager',
                        'role' => 'manager',
                        'is_active' => true,
                    ],
                    [
                        'encoder_uuid' => 'bbbbbbbb-2222-4222-8222-222222222222',
                        'username' => 'an_encoder',
                        'role' => 'encoder',
                        'is_active' => false,
                    ],
                ],
            ])->assertOk();

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->assertDatabaseHas('offline_server_encoders', [
            'offline_server_id' => $server->id,
            'username' => 'the_manager',
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('offline_server_encoders', [
            'offline_server_id' => $server->id,
            'username' => 'an_encoder',
            'role' => 'encoder',
            'is_active' => false,
        ]);
    }

    public function test_the_user_monitoring_page_shows_manager_encoders_and_device_identity(): void
    {
        $data = $this->enrollServer();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', [
                'events' => [],
                'encoders' => [
                    ['encoder_uuid' => 'aaaaaaaa-1111-4111-8111-111111111111', 'username' => 'the_manager', 'role' => 'manager', 'is_active' => true],
                    ['encoder_uuid' => 'bbbbbbbb-2222-4222-8222-222222222222', 'username' => 'an_encoder', 'role' => 'encoder', 'is_active' => true],
                ],
            ])->assertOk();

        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->actingAs($this->admin())
            ->get(route('sync-servers.show', $server))
            ->assertOk()
            ->assertSee('User Monitoring')
            ->assertSee('the_manager')
            ->assertSee('an_encoder')
            ->assertSee('Manager')
            ->assertSee('Encoder')
            ->assertSee($server->server_uuid)
            ->assertDontSee($data['server_token']);
    }

    public function test_the_users_page_shows_the_offline_manager_and_encoder_registry(): void
    {
        $data = $this->enrollServer();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', [
                'events' => [],
                'encoders' => [
                    ['encoder_uuid' => 'aaaaaaaa-1111-4111-8111-111111111111', 'username' => 'the_manager', 'role' => 'manager', 'is_active' => true],
                    ['encoder_uuid' => 'bbbbbbbb-2222-4222-8222-222222222222', 'username' => 'an_encoder', 'role' => 'encoder', 'is_active' => true],
                ],
            ])->assertOk();

        $this->actingAs($this->admin())
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Offline installation accounts')
            ->assertSee('the_manager')
            ->assertSee('an_encoder')
            ->assertSee('Manager')
            ->assertSee('Encoder');
    }

    public function test_user_monitoring_is_admin_only(): void
    {
        $data = $this->enrollServer();
        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        foreach ([User::ROLE_MANAGER, User::ROLE_STAFF, User::ROLE_ENCODER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('sync-servers.show', $server))
                ->assertForbidden();
        }
    }

    public function test_the_users_page_uses_the_shared_management_workspace_layout(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => User::ROLE_STAFF, 'name' => 'STAFF ACCOUNT']);

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertSee('Users &amp; Roles', false)
            ->assertSee('Search &amp; filter users', false)
            ->assertSee('System users')
            ->assertSee('Total Users')
            ->assertSee('Administrators')
            ->assertSee('Offline installation accounts')
            ->assertSee('id="deleteUserModal"', false)
            ->assertSee('tims-modal--sm', false)
            ->assertSee('STAFF ACCOUNT')
            ->assertSee(route('users.edit', $staff), false);

        // Role filtering still scopes the central user list.
        $this->actingAs($admin)->get(route('users.index', ['role' => User::ROLE_STAFF]))
            ->assertOk()
            ->assertSee('STAFF ACCOUNT');
    }

    public function test_the_offline_account_server_link_opens_the_server_detail_page(): void
    {
        $data = $this->enrollServer();
        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/audit-events', [
                'events' => [],
                'encoders' => [
                    ['encoder_uuid' => 'cccccccc-3333-4333-8333-333333333333', 'username' => 'datu_paglas_manager', 'role' => 'manager', 'is_active' => true],
                ],
            ])->assertOk();

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()
            ->assertSee(route('sync-servers.show', $server), false)
            ->assertDontSee(route('sync-servers.show', $server->id), false);

        $this->actingAs($admin)->get(route('sync-servers.show', $server))->assertOk();
    }
}
