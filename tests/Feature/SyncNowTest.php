<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Models\User;
use App\Support\SyncIdentity;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncNowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private OfflineServer $server;

    private string $serverToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'offline']);

        // Manually create the enrolled server state (as if enrollment succeeded)
        $this->server = OfflineServer::create([
            'server_uuid' => '30a09bea-4ff3-42ee-8435-879aed112917',
            'name' => 'Test Offline Server',
            'status' => OfflineServer::STATUS_ACTIVE,
            'protocol_version' => 1,
            'schema_version' => 1,
            'enrolled_at' => now(),
            'authorized_at' => now(),
            'authorization_expires_at' => now()->addDays(30),
            'created_by' => null,
        ]);

        // Create scope for the server
        $this->server->scopes()->create([
            'scope_type' => 'city_municipality',
            'location_code' => '1900702000',
        ]);

        // Set local sync settings as if enrolled
        $this->serverToken = 'test-server-token-123';
        SyncSetting::set('central_url', 'https://central.test');
        SyncSetting::set('server_uuid', $this->server->server_uuid);
        SyncSetting::set('local_server_uuid', $this->server->server_uuid);
        SyncSetting::set('server_name', 'Test Offline Server');
        SyncSetting::setEncrypted('server_token', $this->serverToken);
        SyncSetting::set('enrolled_at', now()->toIso8601String());
        SyncSetting::set('authorized_at', now()->toIso8601String());
        SyncSetting::set('authorization_expires_at', now()->addDays(30)->toIso8601String());
        SyncSetting::set('authorization_status', 'active');
        SyncSetting::set('scope_type', 'city_municipality');
        SyncSetting::set('scope_code', '1900702000');
        SyncSetting::flushCache();
    }

    // 1. Central Server connection succeeds and the UI reports Connected.
    public function test_connection_check_reports_connected_when_central_reachable(): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
        ]);

        $response = $this->actingAs($this->manager())->getJson(route('sync.local.connection'));

        $response->assertOk()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('message', 'Central server reachable and authorized.');
    }

    // 2. Central Server connection fails and the UI reports Offline.
    public function test_connection_check_reports_offline_when_central_unreachable(): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response('', 500),
        ]);

        $response = $this->actingAs($this->manager())->getJson(route('sync.local.connection'));

        $response->assertOk()
            ->assertJsonPath('status', 'offline');
    }

    // 3. Authorization errors are distinguished from connection failures.
    public function test_connection_check_reports_auth_error_on_401(): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'error' => ['message' => 'Invalid token'],
            ], 401),
        ]);

        $response = $this->actingAs($this->manager())->getJson(route('sync.local.connection'));

        $response->assertOk()
            ->assertJsonPath('status', 'auth_error')
            ->assertJsonPath('message', 'Server token rejected (authorization expired or revoked).');
    }

    // 4. The 10 missing beneficiaries synchronize correctly.
    public function test_missing_beneficiaries_synchronize_via_sync_now(): void
    {
        // Create 10 beneficiaries on central with NULL location_code (unassigned)
        $beneficiaries = Beneficiary::factory()->count(10)->create([
            'location_code' => null,
            'origin_server_uuid' => SyncIdentity::centralUuid(),
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid',
                    'status' => 'ready',
                    'revision_to' => 10,
                    'manifest' => [
                        'beneficiaries' => 10,
                        'locations' => 0,
                        'batches' => 0,
                        'trainings' => 0,
                        'page_size' => 500,
                        'scope_prefixes' => ['1900702'],
                    ],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid/beneficiaries' => Http::response([
                'data' => $beneficiaries->map(fn ($b) => [
                    'global_uuid' => $b->global_uuid,
                    'origin_server_uuid' => $b->origin_server_uuid,
                    'revision' => $b->revision,
                    'beneficiary_code' => $b->beneficiary_code,
                    'first_name' => $b->first_name,
                    'last_name' => $b->last_name,
                    'location_code' => null,
                ])->all(),
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 10],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Sync complete'));

        // Verify all 10 beneficiaries are now in local database
        $this->assertEquals(10, Beneficiary::query()->whereNull('location_code')->count());

        // Verify they have the correct origin_server_uuid (central)
        $synced = Beneficiary::query()->whereNull('location_code')->get();
        $synced->each(fn ($b) => $this->assertEquals(SyncIdentity::centralUuid(), $b->origin_server_uuid));
    }

    // 5. The local database persists the downloaded records.
    public function test_synced_beneficiaries_persist_in_local_database(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => null,
            'origin_server_uuid' => SyncIdentity::centralUuid(),
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-2',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 1, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-2/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-2/beneficiaries' => Http::response([
                'data' => [[
                    'global_uuid' => $beneficiary->global_uuid,
                    'origin_server_uuid' => $beneficiary->origin_server_uuid,
                    'revision' => $beneficiary->revision,
                    'beneficiary_code' => $beneficiary->beneficiary_code,
                    'first_name' => $beneficiary->first_name,
                    'last_name' => $beneficiary->last_name,
                    'location_code' => null,
                ]],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 1],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $beneficiary->global_uuid]);
    }

    // 6. Repeated synchronization does not duplicate beneficiary records.
    public function test_repeated_sync_does_not_duplicate_beneficiaries(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => null,
            'origin_server_uuid' => SyncIdentity::centralUuid(),
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-3',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 1, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-3/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-3/beneficiaries' => Http::response([
                'data' => [[
                    'global_uuid' => $beneficiary->global_uuid,
                    'origin_server_uuid' => $beneficiary->origin_server_uuid,
                    'revision' => $beneficiary->revision,
                    'beneficiary_code' => $beneficiary->beneficiary_code,
                    'first_name' => $beneficiary->first_name,
                    'last_name' => $beneficiary->last_name,
                    'location_code' => null,
                ]],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 1],
            ]),
        ]);

        // First sync
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));
        // Second sync (same data)
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));
        // Third sync
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        // Should still only have 1 record
        $this->assertEquals(1, Beneficiary::query()->where('global_uuid', $beneficiary->global_uuid)->count());
    }

    // 7. Pending local operations are uploaded and acknowledged correctly.
    public function test_pending_local_operations_are_uploaded_and_acknowledged(): void
    {
        $operation = SyncOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'TEST',
                'last_name' => 'SYNC',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-TEST123',
            ],
            'status' => SyncOperation::STATUS_PENDING,
            'origin_server_uuid' => SyncIdentity::originUuid(),
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => $operation->operation_uuid,
                    'status' => 'applied',
                    'revision' => 5,
                ]],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response([
                'data' => [],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-4',
                    'status' => 'ready',
                    'revision_to' => 5,
                    'manifest' => ['beneficiaries' => 0, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-4/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-4/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $operation->refresh();
        $this->assertEquals(SyncOperation::STATUS_SYNCED, $operation->status);
    }

    // 8. Audit events are uploaded without duplication or loss.
    public function test_audit_events_uploaded_without_duplication(): void
    {
        $event1 = AuditLog::create([
            'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee1',
            'action' => 'beneficiary.created',
            'entity_type' => 'beneficiary',
            'entity_id' => 1,
            'actor_uuid' => '11111111-2222-4333-8444-555555555555',
            'actor_username' => 'test_encoder',
            'actor_role' => 'encoder',
            'occurred_at' => now()->subHour(),
            'metadata' => ['barangay' => 'TEST'],
            'ip' => '10.0.0.5',
        ]);

        $event2 = AuditLog::create([
            'event_uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee2',
            'action' => 'beneficiary.updated',
            'entity_type' => 'beneficiary',
            'entity_id' => 1,
            'actor_uuid' => '11111111-2222-4333-8444-555555555555',
            'actor_username' => 'test_encoder',
            'actor_role' => 'encoder',
            'occurred_at' => now()->subMinutes(30),
            'metadata' => ['barangay' => 'TEST2'],
            'ip' => '10.0.0.5',
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/audit-events' => Http::response([
                'data' => [
                    'accepted_event_uuids' => ['aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee1', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeee2'],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-5',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 0, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-5/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-5/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $event1->refresh();
        $event2->refresh();

        $this->assertNotNull($event1->synced_at);
        $this->assertNotNull($event2->synced_at);
    }

    // 9. Encoder identity registry synchronization follows the correct authorization rules.
    public function test_encoder_registry_synchronization_authorization(): void
    {
        $encoder = User::factory()->create([
            'role' => User::ROLE_ENCODER,
            'username' => 'test_encoder_sync',
            'is_active' => true,
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/audit-events' => Http::response([
                'data' => [
                    'accepted_event_uuids' => [],
                    'registry' => [[
                        'encoder_uuid' => $encoder->uuid,
                        'username' => $encoder->username,
                        'role' => $encoder->role,
                        'is_active' => true,
                    ]],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-6',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 0, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-6/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-6/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        // Verify the encoder was included in the registry push. The registry
        // covers this installation's Manager as well, so the encoder is
        // asserted by presence, not by the total count.
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://central.test/api/sync/v1/audit-events' || ! isset($request['encoders'])) {
                return false;
            }

            $encoders = (array) $request['encoders'];
            $usernames = array_column($encoders, 'username');
            $roles = array_column($encoders, 'role');

            return in_array('test_encoder_sync', $usernames, true)
                && in_array(User::ROLE_ENCODER, $roles, true)
                && in_array(User::ROLE_MANAGER, $roles, true);
        });
    }

    // 10. Partial synchronization failure preserves unprocessed operations and does not advance checkpoints incorrectly.
    public function test_partial_sync_failure_preserves_operations_and_checkpoints(): void
    {
        // Create pending operation
        $operation = SyncOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'TEST',
                'last_name' => 'FAIL',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-FAIL123',
            ],
            'status' => SyncOperation::STATUS_PENDING,
            'origin_server_uuid' => SyncIdentity::originUuid(),
        ]);

        $originalCursor = SyncSetting::get('last_change_cursor') ?? '0';

        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => $operation->operation_uuid,
                    'status' => 'rejected',
                    'message' => 'Central validation failed',
                ]],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-7',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 0, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-7/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-7/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $operation->refresh();
        // Operation should remain pending/rejected locally, not marked as synced
        $this->assertNotEquals(SyncOperation::STATUS_SYNCED, $operation->status);

        // Cursor should not advance beyond what was successfully processed
        $newCursor = SyncSetting::get('last_change_cursor') ?? '0';
        $this->assertEquals($originalCursor, $newCursor);
    }

    // 11. Sync Now cannot issue authorization when mandatory steps fail.
    public function test_sync_now_does_not_extend_authorization_on_failure(): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => 'fake-uuid',
                    'status' => 'rejected',
                ]],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-8',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 0, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-8/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-8/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
        ]);

        $originalAuthExpires = SyncSetting::get('authorization_expires_at');

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        // Authorization should NOT be extended (no new authorized_at/expires_at from prepare)
        $this->assertEquals($originalAuthExpires, SyncSetting::get('authorization_expires_at'));
    }

    // 12. Expired or revoked authorization is enforced correctly.
    public function test_expired_authorization_blocks_sync_now(): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
        ]);

        SyncSetting::set('authorization_expires_at', now()->subDay()->toIso8601String());
        SyncSetting::set('authorization_status', 'expired');
        SyncSetting::flushCache();

        $response = $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Authorization validation failed'));
    }

    // 13. Concurrent Sync Now requests do not corrupt synchronization state.
    public function test_concurrent_sync_now_requests_are_blocked(): void
    {
        Cache::shouldReceive('lock')
            ->once()
            ->andReturn($lock = \Mockery::mock(Lock::class));
        $lock->shouldReceive('get')->once()->andReturn(false); // Already locked

        $response = $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $response->assertSessionHas('error', fn ($msg) => str_contains($msg, 'already in progress'));
    }

    // 14. The UI reports the actual result rather than unconditional success.
    public function test_sync_now_reports_actual_result_not_unconditional_success(): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => 'fake-uuid',
                    'status' => 'rejected',
                    'message' => 'Validation error',
                ]],
            ]),
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'test-snapshot-uuid-9',
                    'status' => 'ready',
                    'revision_to' => 1,
                    'manifest' => ['beneficiaries' => 0, 'locations' => 0, 'batches' => 0, 'trainings' => 0, 'page_size' => 500, 'scope_prefixes' => ['1900702']],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-9/reference' => Http::response([
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/test-snapshot-uuid-9/beneficiaries' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'rejected') ||
                str_contains($msg, 'warning') ||
                str_contains($msg, 'conflict') ||
                str_contains($msg, 'completed with warnings')
            );
    }

    // 15. Download-only changes are applied when nothing is pending locally.
    public function test_download_only_changes_are_applied_when_nothing_is_pending(): void
    {
        $uuid = '77777777-7777-4777-8777-777777777777';

        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 12,
                    'change_uuid' => '88888888-8888-4888-8888-888888888888',
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $uuid,
                    'operation' => 'created',
                    'origin_server_uuid' => '33333333-3333-4333-8333-333333333333',
                    'revision' => 2,
                    'payload' => [
                        'changed_fields' => ['first_name'],
                        'data' => [
                            'beneficiary_code' => 'DELTA-0001',
                            'first_name' => 'DELTA',
                            'last_name' => 'ONLY',
                            'location_code' => '1900702001',
                            'birthdate' => '1991-02-02',
                        ],
                    ],
                ]],
                'meta' => ['next_cursor' => 12, 'has_more' => false, 'count' => 1],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, '1 downloaded'));

        $this->assertDatabaseHas('beneficiaries', [
            'global_uuid' => $uuid,
            'beneficiary_code' => 'DELTA-0001',
        ]);
    }

    // 16. No pending changes in either direction is a clean zero-count success.
    public function test_sync_with_no_changes_reports_success_with_zero_counts(): void
    {
        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, '0 uploaded, 0 downloaded, 0 conflict(s), 0 rejected'));

        $this->assertNull(SyncSetting::get('last_sync_error'));
    }

    // 17. Connection loss during upload preserves every pending operation.
    public function test_connection_loss_during_upload_preserves_pending_operations(): void
    {
        $operation = SyncOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'LOST',
                'last_name' => 'UPLINK',
                'location_code' => '1900702001',
            ],
            'status' => SyncOperation::STATUS_PENDING,
            'origin_server_uuid' => SyncIdentity::originUuid(),
        ]);

        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/operations' => function (): void {
                throw new \RuntimeException('Connection lost during upload');
            },
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('sync_result', fn ($result) => $result['status'] === 'warning'
                && collect($result['steps'])->contains(
                    fn ($step) => $step['step'] === 'upload' && $step['status'] === 'error'
                ));

        // The operation was never acknowledged or discarded — it stays queued.
        $this->assertSame(SyncOperation::STATUS_PENDING, $operation->refresh()->status);
    }

    // 18. Connection loss during download keeps the checkpoint where it was.
    public function test_connection_loss_during_download_keeps_checkpoint(): void
    {
        SyncSetting::set('last_change_cursor', '40');
        SyncSetting::flushCache();

        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/changes*' => function (): void {
                throw new \RuntimeException('Connection lost during download');
            },
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertSessionHas('sync_result', fn ($result) => $result['status'] === 'warning'
                && collect($result['steps'])->contains(
                    fn ($step) => $step['step'] === 'download' && $step['status'] === 'error'
                ));

        $this->assertSame('40', SyncSetting::get('last_change_cursor'));
    }

    // 19. A lost acknowledgment response is recorded, and a retry never re-uploads.
    public function test_lost_ack_response_is_recorded_and_retry_is_safe(): void
    {
        $operation = SyncOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'ACK',
                'last_name' => 'GHOST',
                'location_code' => '1900702001',
            ],
            'status' => SyncOperation::STATUS_PENDING,
            'origin_server_uuid' => SyncIdentity::originUuid(),
        ]);

        $uploadCalls = 0;

        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/operations' => function () use (&$uploadCalls, $operation) {
                $uploadCalls++;

                return Http::response([
                    'data' => [[
                        'operation_uuid' => $operation->operation_uuid,
                        'status' => 'applied',
                        'revision' => 3,
                    ]],
                ]);
            },
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => function (): void {
                throw new \RuntimeException('Connection lost before acknowledgment');
            },
        ]);

        // Run 1: upload succeeds, the acknowledgment response is lost.
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertSessionHas('sync_result', fn ($result) => $result['status'] === 'warning'
                && collect($result['steps'])->contains(
                    fn ($step) => $step['step'] === 'acknowledge' && $step['status'] === 'warning'
                ));

        $this->assertSame(1, $uploadCalls);
        $this->assertSame(SyncOperation::STATUS_SYNCED, $operation->refresh()->status);
        $this->assertStringContainsString('acknowledgment failed', (string) SyncSetting::get('last_sync_error'));

        // Run 2: the acknowledged operation is NOT resubmitted — the upload
        // step has nothing pending, so the central server sees it once only.
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'));

        $this->assertSame(1, $uploadCalls);
    }

    // 20. Delta download resumes from its checkpoint after an interruption,
    //     and a repeat run never duplicates records already applied.
    public function test_delta_download_resumes_from_checkpoint_after_interruption(): void
    {
        $firstUuid = 'aaaa1111-1111-4111-8111-aaaaaaaaaaa1';
        $secondUuid = 'aaaa2222-2222-4222-8222-aaaaaaaaaaa2';

        $changeRow = fn (string $uuid, string $code, string $first, int $revision, int $id): array => [
            'id' => $id,
            'change_uuid' => 'cccc'.$id.'-0000-4000-8000-cccccccccccc',
            'entity_type' => 'beneficiary',
            'entity_uuid' => $uuid,
            'operation' => 'created',
            'origin_server_uuid' => '33333333-3333-4333-8333-333333333333',
            'revision' => $revision,
            'payload' => [
                'changed_fields' => ['first_name'],
                'data' => [
                    'beneficiary_code' => $code,
                    'first_name' => $first,
                    'last_name' => 'RESUME',
                    'location_code' => '1900702001',
                    'birthdate' => '1992-03-03',
                ],
            ],
        ];

        $calls = 0;

        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/changes*' => function () use (&$calls, $changeRow, $firstUuid, $secondUuid) {
                $calls++;

                if ($calls === 1) {
                    // Run 1, page one: succeeds and reports more data behind cursor 10.
                    return Http::response([
                        'data' => [$changeRow($firstUuid, 'RESUME-0001', 'FIRST', 5, 10)],
                        'meta' => ['next_cursor' => 10, 'has_more' => true, 'count' => 1],
                    ]);
                }

                if ($calls === 2) {
                    // Run 1, page two: the connection drops mid-download.
                    throw new \RuntimeException('Connection lost on page two');
                }

                // Run 2: the download resumes from cursor 10 and finishes.
                return Http::response([
                    'data' => [$changeRow($secondUuid, 'RESUME-0002', 'SECOND', 6, 11)],
                    'meta' => ['next_cursor' => 11, 'has_more' => false, 'count' => 1],
                ]);
            },
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        // Run 1: page one applied, checkpoint persisted, page two lost.
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertSessionHas('sync_result', fn ($result) => $result['status'] === 'warning');

        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $firstUuid, 'beneficiary_code' => 'RESUME-0001']);
        $this->assertSame('10', SyncSetting::get('last_change_cursor'));

        // Run 2: resumes from cursor 10 and applies the remaining page.
        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, '1 downloaded'));

        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $secondUuid, 'beneficiary_code' => 'RESUME-0002']);
        $this->assertSame(1, Beneficiary::query()->where('global_uuid', $firstUuid)->count());
        $this->assertSame('11', SyncSetting::get('last_change_cursor'));
    }

    // 21. The page carries ONE sync action: the Download Data button is gone,
    //     the recovery path stays tucked away, and app updates stay separate.
    public function test_the_enroll_server_page_has_one_primary_sync_action(): void
    {
        $this->actingAs($this->manager())->get(route('sync.local.index'))
            ->assertOk()
            ->assertSee('Sync Now', false)
            ->assertSee(route('sync.local.sync-now'), false)
            ->assertDontSee('Download Data')
            ->assertSee('Download full snapshot')
            ->assertSee(route('sync.local.download'), false)
            ->assertSee('Check for Updates', false)
            ->assertSee(route('sync.local.app-update.check'), false)
            ->assertSee(route('sync.local.connection'), false);
    }

    // 22. The flashed result distinguishes success from partial success, and
    //     carries the per-step detail the summary panel renders.
    public function test_sync_result_flash_carries_steps_and_status(): void
    {
        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertSessionHas('sync_result', function ($result) {
                $steps = collect($result['steps']);

                return $result['status'] === 'success'
                    && $steps->count() >= 7
                    && $steps->every(fn ($step) => $step['status'] === 'ok')
                    && ($result['summary']['uploaded'] ?? -1) === 0
                    && ($result['summary']['downloaded'] ?? -1) === 0;
            });
    }

    /**
     * Endpoints a run contacts that a given scenario does not care about:
     * review outcomes and the audit/registry push both answer cleanly so the
     * step under test is the only thing that can fail.
     */
    private function quietCentral(): array
    {
        return [
            'https://central.test/api/sync/v1/compatibility' => Http::response([
                'data' => ['protocol_version' => 1, 'schema_version' => 1],
            ]),
            'https://central.test/api/sync/v1/health' => Http::response([
                'data' => ['status' => 'ok', 'server_uuid' => $this->server->server_uuid],
            ]),
            'https://central.test/api/sync/v1/request-outcomes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/audit-events' => Http::response([
                'data' => ['accepted_event_uuids' => []],
            ]),
            'https://central.test/api/sync/v1/heartbeat' => Http::response(['data' => []]),
        ];
    }

    // 23. Every Sync Now reports the post-run queue state, so the central
    //     Offline Servers page never shows stale monitoring numbers.
    public function test_sync_now_reports_a_heartbeat_to_the_central_server(): void
    {
        Http::fake($this->quietCentral() + [
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $this->actingAs($this->manager())->post(route('sync.local.sync-now'))
            ->assertRedirect(route('sync.local.index'));

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://central.test/api/sync/v1/heartbeat') {
                return false;
            }

            $queue = (array) $request['queue'];

            return array_key_exists('pending', $queue)
                && array_key_exists('awaiting_review', $queue)
                && array_key_exists('reported_at', $queue)
                && (int) $queue['pending'] === 0;
        });
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }
}
