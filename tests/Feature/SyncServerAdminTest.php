<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\OfflineServer;
use App\Models\OfflineServerEnrollmentToken;
use App\Models\SyncConflict;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncServerAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admins_can_view_the_sync_server_dashboard(): void
    {
        $server = OfflineServer::factory()->create();
        $server->scopes()->create([
            'scope_type' => 'city_municipality',
            'location_code' => '1900702000',
        ]);

        $this->actingAs($this->admin())
            ->get(route('sync-servers.index'))
            ->assertOk()
            ->assertSee($server->name)
            ->assertSee('1900702000');

        $this->actingAs($this->admin())
            ->get(route('sync-servers.show', $server))
            ->assertOk()
            ->assertSee($server->server_uuid);
    }

    public function test_non_admins_cannot_manage_sync_servers(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($staff)
            ->get(route('sync-servers.index'))
            ->assertForbidden();
    }

    public function test_the_show_page_renders_the_shared_dashboard_layout(): void
    {
        $admin = $this->admin();
        $server = OfflineServer::factory()->create();

        $this->actingAs($admin)
            ->get(route('sync-servers.show', $server))
            ->assertOk()
            ->assertSee('Reconciliation')
            ->assertSee('Status and authorization')
            ->assertSee('User Monitoring')
            ->assertSee('Assigned scopes')
            ->assertSee('Recent sync events')
            ->assertSee('Snapshots')
            ->assertSee('Checkpoints')
            ->assertSee('data-copy="'.$server->server_uuid.'"', false)
            ->assertSee(route('sync-servers.rotate-token', $server), false)
            ->assertSee(route('sync-servers.revoke', $server), false);
    }

    public function test_the_index_renders_the_redesigned_monitoring_layout(): void
    {
        $admin = $this->admin();
        $server = OfflineServer::factory()->create();

        $this->actingAs($admin)
            ->get(route('sync-servers.index'))
            ->assertOk()
            ->assertSee('Server registry')
            ->assertSee('Total servers')
            ->assertSee('Open conflicts')
            ->assertSee('Prepare an installation')
            ->assertSee('Search by server name or UUID')
            ->assertSee('data-copy="'.$server->server_uuid.'"', false)
            ->assertSee('href="'.route('sync-servers.index', ['status' => 'active']).'"', false)
            ->assertSee('href="'.route('sync-conflicts.index').'"', false);
    }

    public function test_the_index_reports_queue_metrics_and_open_conflicts_per_server(): void
    {
        $admin = $this->admin();
        $server = OfflineServer::factory()->create([
            'metadata' => [
                'last_reported' => [
                    'pending' => 3,
                    'failed' => 1,
                    'conflicts' => 2,
                    'awaiting_review' => 4,
                    'synced' => 50,
                    'reported_at' => '2026-10-30T09:00:00+08:00',
                ],
            ],
        ]);

        // Two open conflicts for this server, one for another server, and one
        // local conflict with no server — only this server's count shows.
        SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => $server->server_uuid,
            'local_payload' => [],
            'remote_payload' => [],
            'diff' => [],
            'status' => 'open',
        ]);
        SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => $server->server_uuid,
            'local_payload' => [],
            'remote_payload' => [],
            'diff' => [],
            'status' => 'open',
        ]);

        $this->actingAs($admin)->get(route('sync-servers.index'))
            ->assertOk()
            ->assertSee('Pending: 3')
            ->assertSee('Awaiting review: 4')
            ->assertSee('Conflicts: 2')
            ->assertSee('>2</span>', false)
            ->assertSee('Open conflicts', false);

        // Resolved conflicts must not inflate the badge.
        $this->assertSame(2, SyncConflict::query()->where('status', 'open')->count());
    }

    public function test_a_sync_heartbeat_stores_the_reported_queue_state(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken(null, 'Field Laptop', 30);

        $enrolled = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Field Laptop',
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated()->json('data');

        $server = OfflineServer::query()->latest('id')->firstOrFail();

        $this->withHeader((string) config('sync.server_token_header'), $enrolled['server_token'])
            ->postJson('/api/sync/v1/heartbeat', [
                'queue' => [
                    'pending' => 7,
                    'failed' => 0,
                    'conflicts' => 1,
                    'awaiting_review' => 2,
                    'synced' => 120,
                    'last_error' => null,
                    'reported_at' => now()->toIso8601String(),
                ],
            ])
            ->assertOk();

        $server->refresh();

        $reported = data_get($server->metadata, 'last_reported');

        $this->assertIsArray($reported);
        $this->assertSame(7, (int) ($reported['pending'] ?? -1));
        $this->assertSame(2, (int) ($reported['awaiting_review'] ?? -1));
        $this->assertNotNull($server->last_seen_at);
    }

    public function test_generating_an_enrollment_token_shows_it_once_and_stores_only_a_hash(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->post(route('sync-servers.enrollment-token'), [
                'note' => 'Basilan rollout',
                'device_label' => 'Basilan Laptop 1',
                'authorization_days' => 45,
            ])
            ->assertRedirect(route('sync-servers.index'))
            ->assertSessionHas('enrollment_token');

        $plainText = session('enrollment_token');
        $this->assertIsString($plainText);

        $token = OfflineServerEnrollmentToken::query()->firstOrFail();
        $this->assertSame(hash('sha256', $plainText), $token->token_hash);
        $this->assertSame($admin->id, $token->created_by);
        $this->assertNull($token->used_at);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertSame('Basilan Laptop 1', $token->device_label);
        $this->assertTrue($token->authorization_expires_at->isFuture());
    }

    public function test_generating_an_enrollment_token_requires_an_authorization_period(): void
    {
        $this->actingAs($this->admin())
            ->post(route('sync-servers.enrollment-token'), ['note' => 'No duration'])
            ->assertSessionHasErrors('authorization_days');

        $this->assertDatabaseCount('offline_server_enrollment_tokens', 0);
    }

    public function test_admin_can_add_and_remove_a_scope(): void
    {
        $server = OfflineServer::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('sync-servers.scopes.store', $server), [
                'scope_type' => 'city_municipality',
                'location_code' => '1900702000',
                'city_municipality' => 'Lamitan City',
            ])
            ->assertRedirect();

        $scope = $server->scopes()->firstOrFail();
        $this->assertSame('1900702000', $scope->location_code);

        $this->actingAs($this->admin())
            ->delete(route('sync-servers.scopes.destroy', [$server, $scope]))
            ->assertRedirect();

        $this->assertDatabaseMissing('offline_server_scopes', ['id' => $scope->id]);
    }

    public function test_the_scope_form_offers_a_region_option_and_province_reference_codes(): void
    {
        Location::query()->create([
            'psgc_code' => '1900702001',
            'barangay' => 'Poblacion',
            'city_municipality' => 'Lamitan City',
            'province' => 'BASILAN',
            'full_address' => 'Poblacion, Lamitan City, BASILAN',
            'is_city' => true,
        ]);

        $server = OfflineServer::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('sync-servers.show', $server))
            ->assertOk()
            ->assertSee('Entire BARMM region')
            ->assertSee('list="scopeCodes"', false)
            ->assertSee('value="1900"', false)
            ->assertSee('Examples: 1900 (BASILAN).');
    }

    public function test_admin_can_assign_a_region_scope_covering_the_entire_barm(): void
    {
        $server = OfflineServer::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('sync-servers.scopes.store', $server), [
                'scope_type' => 'region',
                'location_code' => 'BARMM',
            ])
            ->assertRedirect();

        $scope = $server->scopes()->firstOrFail();
        $this->assertSame('region', $scope->scope_type);
        $this->assertSame('BARMM', $scope->location_code);
    }

    public function test_admin_cannot_assign_a_scope_that_matches_no_barm_location(): void
    {
        Location::query()->create([
            'psgc_code' => '1900702001',
            'barangay' => 'Poblacion',
            'city_municipality' => 'Lamitan City',
            'province' => 'BASILAN',
            'full_address' => 'Poblacion, Lamitan City, BASILAN',
            'is_city' => true,
        ]);

        $server = OfflineServer::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('sync-servers.scopes.store', $server), [
                'scope_type' => 'province',
                'location_code' => '3453',
            ])
            ->assertSessionHasErrors('location_code');

        $this->assertDatabaseCount('offline_server_scopes', 0);
    }

    public function test_rotating_a_token_invalidates_the_previous_token(): void
    {
        $server = OfflineServer::factory()->create();
        $oldToken = app(TokenService::class)->issueServerToken($server);

        $this->actingAs($this->admin())
            ->post(route('sync-servers.rotate-token', $server))
            ->assertRedirect(route('sync-servers.show', $server))
            ->assertSessionHas('server_token');

        $newToken = session('server_token');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNull(app(TokenService::class)->findServerByToken($oldToken));
        $this->assertSame($server->id, app(TokenService::class)->findServerByToken($newToken)?->id);
    }

    public function test_revoking_a_server_clears_its_token_and_locks_status_changes(): void
    {
        $server = OfflineServer::factory()->create();
        app(TokenService::class)->issueServerToken($server);

        $this->actingAs($this->admin())
            ->post(route('sync-servers.revoke', $server))
            ->assertRedirect(route('sync-servers.show', $server));

        $server->refresh();
        $this->assertSame(OfflineServer::STATUS_REVOKED, $server->status);
        $this->assertNull($server->api_token_hash);
        $this->assertNotNull($server->revoked_at);

        $this->actingAs($this->admin())
            ->put(route('sync-servers.status', $server), ['status' => 'active'])
            ->assertSessionHas('error');

        $this->assertSame(OfflineServer::STATUS_REVOKED, $server->fresh()->status);
    }
}
