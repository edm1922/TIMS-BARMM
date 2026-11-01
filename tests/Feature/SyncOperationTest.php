<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Services\Sync\LocalChangeApplier;
use App\Services\Sync\TokenService;
use App\Support\SyncIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncOperationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{server: OfflineServer, token: string}
     */
    private function enrollServer(): array
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Operation Test Server',
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated();

        $data = $response->json('data');
        $server = OfflineServer::query()->where('server_uuid', $data['server_uuid'])->firstOrFail();

        return ['server' => $server, 'token' => $data['server_token']];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createOperation(array $overrides = []): array
    {
        return array_merge([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'SYNC',
                'last_name' => 'TEST',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-'.Str::upper(Str::random(10)),
            ],
        ], $overrides);
    }

    private function postOperations(string $token, array $operations): TestResponse
    {
        return $this->withHeader((string) config('sync.server_token_header'), $token)
            ->postJson('/api/sync/v1/operations', ['operations' => $operations]);
    }

    /**
     * Pre-seed the idempotency receipt the central server would have written
     * for a previous attempt.
     *
     * @param  array<string, mixed>  $result
     */
    private function receipt(OfflineServer $server, array $operation, array $result): void
    {
        SyncChange::create([
            'change_uuid' => $operation['operation_uuid'],
            'offline_server_id' => $server->id,
            'entity_type' => $operation['entity_type'],
            'entity_uuid' => $operation['entity_uuid'],
            'operation' => 'receipt',
            'origin_server_uuid' => $server->server_uuid,
            'revision' => 0,
            'payload' => ['result' => $result],
            'metadata' => ['direction' => 'upload'],
            'status' => $result['status'],
            'applied_at' => now(),
        ]);
    }

    public function test_creating_a_scoped_beneficiary_records_origin_and_revision(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();
        $operation = $this->createOperation();

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied')
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('meta.applied', 1);

        $beneficiary = Beneficiary::query()->where('global_uuid', $operation['entity_uuid'])->firstOrFail();

        $this->assertSame($server->server_uuid, $beneficiary->origin_server_uuid);
        $this->assertSame(1, $beneficiary->revision);
        $this->assertSame('1900702001', $beneficiary->location_code);
        $this->assertDatabaseHas('sync_changes', [
            'change_uuid' => $operation['operation_uuid'],
            'offline_server_id' => $server->id,
            'operation' => 'receipt',
            'status' => 'applied',
        ]);
    }

    public function test_operation_retry_is_idempotent(): void
    {
        ['token' => $token] = $this->enrollServer();
        $operation = $this->createOperation();

        $first = $this->postOperations($token, [$operation])->assertOk()->json('data.0');
        $second = $this->postOperations($token, [$operation])->assertOk()->json('data.0');

        $this->assertSame('applied', $first['status']);
        $this->assertSame($first, $second);
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('sync_changes', 2);
    }

    public function test_out_of_scope_creates_are_accepted_and_keep_their_location(): void
    {
        ['token' => $token] = $this->enrollServer();
        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'OUTSIDE',
                'last_name' => 'SCOPE',
                'location_code' => '2000000001',
                'source_id_number' => 'SRC-OUTSIDE-1',
            ],
        ]);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied')
            ->assertJsonPath('meta.applied', 1)
            ->assertJsonPath('meta.rejected', 0);

        $this->assertDatabaseHas('beneficiaries', [
            'global_uuid' => $operation['entity_uuid'],
            'location_code' => '2000000001',
            'first_name' => 'OUTSIDE',
        ]);
    }

    public function test_creates_without_a_location_are_still_rejected(): void
    {
        ['token' => $token] = $this->enrollServer();
        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'NO',
                'last_name' => 'LOCATION',
                'location_code' => '',
            ],
        ]);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.code', 'validation_failed');

        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $operation['entity_uuid']]);
    }

    public function test_beneficiary_updates_outside_scope_are_accepted_and_preserved(): void
    {
        ['token' => $token] = $this->enrollServer();
        $create = $this->createOperation([
            'payload' => [
                'first_name' => 'BEFORE',
                'last_name' => 'MOVE',
                'location_code' => '1900702001',
                'source_id_number' => 'SRC-MOVE-1',
            ],
        ]);

        $this->postOperations($token, [$create])->assertOk()->assertJsonPath('data.0.status', 'applied');

        $update = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'updated',
            'base_revision' => 1,
            'payload' => [
                'first_name' => 'AFTER',
                'last_name' => 'MOVE',
                'location_code' => '2000000002',
                'source_id_number' => 'SRC-MOVE-1',
            ],
        ];

        $this->postOperations($token, [$update])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $beneficiary = Beneficiary::query()->where('global_uuid', $create['entity_uuid'])->firstOrFail();

        $this->assertSame('AFTER', $beneficiary->first_name);
        $this->assertSame('2000000002', $beneficiary->location_code);
    }

    public function test_a_scope_rejected_operation_can_be_retried_and_applied(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();
        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'RETRY',
                'last_name' => 'SCOPE',
                'location_code' => '2000000003',
                'source_id_number' => 'SRC-RETRY-1',
            ],
        ]);

        $previous = [
            'operation_uuid' => $operation['operation_uuid'],
            'entity_type' => 'beneficiary',
            'entity_uuid' => $operation['entity_uuid'],
            'status' => 'rejected',
            'code' => 'out_of_scope',
            'message' => 'The beneficiary location is outside this server scope.',
        ];

        $this->receipt($server, $operation, $previous);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $this->assertDatabaseHas('beneficiaries', [
            'global_uuid' => $operation['entity_uuid'],
            'location_code' => '2000000003',
        ]);

        $receipt = SyncChange::query()->where('change_uuid', $operation['operation_uuid'])->firstOrFail();

        $this->assertSame('applied', $receipt->status);
        $this->assertSame($previous, data_get($receipt->metadata, 'previous_results.0.result'));

        // Re-sending the same operation is served from the new receipt.
        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_a_non_retryable_rejection_is_replayed_and_not_reprocessed(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();
        $operation = $this->createOperation();

        $previous = [
            'operation_uuid' => $operation['operation_uuid'],
            'entity_type' => 'beneficiary',
            'entity_uuid' => $operation['entity_uuid'],
            'status' => 'rejected',
            'code' => 'validation_failed',
            'message' => 'First name and last name are required.',
        ];

        $this->receipt($server, $operation, $previous);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.code', 'validation_failed');

        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $operation['entity_uuid']]);
    }

    public function test_a_region_scoped_server_accepts_beneficiaries_from_any_barm_province(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Region Test Server',
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'region',
            'scope_code' => 'BARMM',
        ])->assertCreated();

        $token = $response->json('data.server_token');

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'REGION',
                'last_name' => 'ACCEPTED',
                'location_code' => '1900708008',
            ],
        ]);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied')
            ->assertJsonPath('meta.applied', 1)
            ->assertJsonPath('meta.rejected', 0);

        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $operation['entity_uuid']]);
    }

    public function test_an_authorized_server_can_upload_codes_outside_its_region(): void
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Region Test Server',
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'region',
            'scope_code' => 'BARMM',
        ])->assertCreated();

        $token = $response->json('data.server_token');

        $operation = $this->createOperation([
            'payload' => [
                'first_name' => 'OUTSIDE',
                'last_name' => 'BARMM',
                'location_code' => '2000000001',
                'source_id_number' => 'SRC-REGION-OUTSIDE',
            ],
        ]);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied')
            ->assertJsonPath('meta.applied', 1)
            ->assertJsonPath('meta.rejected', 0);

        $this->assertDatabaseHas('beneficiaries', [
            'global_uuid' => $operation['entity_uuid'],
            'location_code' => '2000000001',
        ]);
    }

    public function test_exact_duplicate_beneficiaries_are_not_created_twice(): void
    {
        ['token' => $token] = $this->enrollServer();
        $first = $this->createOperation();
        $this->postOperations($token, [$first])->assertOk();

        $second = $this->createOperation([
            'payload' => [
                'first_name' => 'SYNC',
                'last_name' => 'TEST',
                'location_code' => '1900702001',
                'source_id_number' => $first['payload']['source_id_number'],
            ],
        ]);

        $this->postOperations($token, [$second])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'duplicate')
            ->assertJsonPath('data.0.code', 'duplicate_beneficiary');

        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_stale_revision_creates_a_conflict_without_overwriting_central_data(): void
    {
        ['token' => $token] = $this->enrollServer();
        $central = Beneficiary::factory()->create([
            'first_name' => 'CENTRAL',
            'last_name' => 'RECORD',
            'location_code' => '1900702001',
            'revision' => 2,
        ]);

        $operation = $this->createOperation([
            'entity_uuid' => $central->global_uuid,
            'operation' => 'updated',
            'base_revision' => 1,
            'payload' => ['first_name' => 'INCOMING'],
        ]);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'conflict')
            ->assertJsonPath('data.0.code', 'revision_conflict');

        $this->assertSame('CENTRAL', $central->fresh()->first_name);
        $this->assertSame('open', $central->fresh()->conflict_status);

        $conflict = SyncConflict::query()->firstOrFail();
        $this->assertSame('open', $conflict->status);
        $this->assertSame('INCOMING', $conflict->local_payload['first_name']);
        $this->assertSame('CENTRAL', $conflict->remote_payload['first_name']);
    }

    public function test_batch_create_delete_and_restore_use_tombstones(): void
    {
        ['token' => $token] = $this->enrollServer();
        $entityUuid = (string) Str::uuid();

        $create = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => ['name' => 'SYNC BATCH', 'description' => 'Created offline'],
        ];

        $this->postOperations($token, [$create])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $this->assertDatabaseHas('beneficiary_batches', [
            'global_uuid' => $entityUuid,
            'name' => 'SYNC BATCH',
            'deleted_at' => null,
        ]);

        $delete = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'deleted',
            'base_revision' => 1,
            'payload' => [],
        ];

        $this->postOperations($token, [$delete])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $this->assertSoftDeleted('beneficiary_batches', ['global_uuid' => $entityUuid]);

        $restore = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'restored',
            'base_revision' => 2,
            'payload' => [],
        ];

        $this->postOperations($token, [$restore])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $this->assertDatabaseHas('beneficiary_batches', [
            'global_uuid' => $entityUuid,
            'deleted_at' => null,
        ]);
    }

    public function test_an_offline_deletion_of_a_known_record_is_held_for_central_approval(): void
    {
        ['token' => $token] = $this->enrollServer();
        $create = $this->createOperation();

        $this->postOperations($token, [$create])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $delete = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'deleted',
            'base_revision' => 1,
            'payload' => ['delete_reason' => 'Duplicate entry'],
        ];

        $this->postOperations($token, [$delete])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_review')
            ->assertJsonPath('data.0.code', 'deletion_pending_approval');

        // Central-master safety: the record is preserved untouched until an
        // administrator decides, and the request is visible as an open
        // deletion approval.
        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $create['entity_uuid'], 'archived_at' => null]);
        $this->assertDatabaseHas('sync_conflicts', [
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'deleted',
            'status' => 'open',
        ]);
    }

    public function test_a_deletion_applies_immediately_when_the_installation_relaxes_the_gate(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();
        $server->forceFill([
            'metadata' => array_merge($server->metadata ?? [], ['deletion_approval_required' => false]),
        ])->save();

        $create = $this->createOperation();
        $this->postOperations($token, [$create])->assertOk();

        $delete = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'deleted',
            'base_revision' => 1,
            'payload' => ['delete_reason' => 'Duplicate entry'],
        ];

        $this->postOperations($token, [$delete])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        // A beneficiary delete is destructive: the row is gone, not archived.
        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $create['entity_uuid']]);

        // The deletion is recorded for the audit trail.
        $this->assertDatabaseHas('audit_logs', ['action' => 'beneficiary.deleted']);
    }

    public function test_a_replayed_deletion_hold_is_an_idempotent_awaiting_review(): void
    {
        ['token' => $token] = $this->enrollServer();
        $create = $this->createOperation();
        $this->postOperations($token, [$create])->assertOk();

        $delete = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'deleted',
            'base_revision' => 1,
            'payload' => [],
        ];

        $this->postOperations($token, [$delete])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_review');

        // Replaying the same operation UUID is served from the receipt.
        $this->postOperations($token, [$delete])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_review');

        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $create['entity_uuid']]);
        $this->assertSame(1, SyncConflict::query()->where('operation', 'deleted')->count());

        // A different operation UUID for the same record resolves to the one
        // open approval rather than opening a second one.
        $second = array_merge($delete, ['operation_uuid' => (string) Str::uuid()]);

        $this->postOperations($token, [$second])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'awaiting_review');

        $this->assertSame(1, SyncConflict::query()->where('operation', 'deleted')->count());
    }

    public function test_deleting_a_beneficiary_closes_its_open_conflicts(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();
        $server->forceFill([
            'metadata' => array_merge($server->metadata ?? [], ['deletion_approval_required' => false]),
        ])->save();

        $create = $this->createOperation();
        $this->postOperations($token, [$create])->assertOk();

        $beneficiary = Beneficiary::query()->where('global_uuid', $create['entity_uuid'])->firstOrFail();

        SyncConflict::create([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'origin_server_uuid' => $server->server_uuid,
            'local_payload' => ['first_name' => 'SYNC'],
            'remote_payload' => ['first_name' => 'SYNC'],
            'status' => 'open',
        ]);

        $delete = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'deleted',
            'base_revision' => 1,
            'payload' => [],
        ];

        $this->postOperations($token, [$delete])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'applied');

        $this->assertDatabaseMissing('beneficiaries', ['id' => $beneficiary->id]);
        $this->assertDatabaseHas('sync_conflicts', [
            'entity_uuid' => $create['entity_uuid'],
            'status' => 'resolved',
        ]);
    }

    public function test_acknowledgement_updates_sync_checkpoints(): void
    {
        ['server' => $server, 'token' => $token] = $this->enrollServer();

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->postJson('/api/sync/v1/ack', [
                'download_revision' => 42,
                'upload_revision' => 7,
                'cursor' => 'cursor-42',
            ])
            ->assertOk()
            ->assertJsonPath('data.server_uuid', $server->server_uuid);

        $this->assertDatabaseHas('sync_checkpoints', [
            'offline_server_id' => $server->id,
            'direction' => 'download',
            'last_revision' => 42,
            'cursor' => 'cursor-42',
        ]);
        $this->assertDatabaseHas('sync_checkpoints', [
            'offline_server_id' => $server->id,
            'direction' => 'upload',
            'last_revision' => 7,
        ]);
        $this->assertNotNull($server->fresh()->last_sync_at);
    }

    public function test_conflict_can_be_kept_central_or_accepted_from_the_local_payload(): void
    {
        ['token' => $token] = $this->enrollServer();
        $central = Beneficiary::factory()->create([
            'first_name' => 'CENTRAL',
            'last_name' => 'RECORD',
            'location_code' => '1900702001',
            'revision' => 2,
        ]);

        $operation = $this->createOperation([
            'entity_uuid' => $central->global_uuid,
            'operation' => 'updated',
            'base_revision' => 1,
            'payload' => ['first_name' => 'INCOMING'],
        ]);

        $this->postOperations($token, [$operation])->assertOk();
        $conflictUuid = SyncConflict::query()->firstOrFail()->conflict_uuid;

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->getJson('/api/sync/v1/conflicts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.conflict_uuid', $conflictUuid);

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->postJson('/api/sync/v1/conflicts/'.$conflictUuid.'/resolve', ['action' => 'keep_central'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSame('CENTRAL', $central->fresh()->first_name);
        $this->assertSame('none', $central->fresh()->conflict_status);
        $this->assertSame('resolved', SyncConflict::query()->firstOrFail()->status);

        $central->forceFill(['revision' => 3])->save();

        $secondOperation = $this->createOperation([
            'entity_uuid' => $central->global_uuid,
            'operation' => 'updated',
            'base_revision' => 2,
            'payload' => ['first_name' => 'ACCEPTED'],
        ]);

        $this->postOperations($token, [$secondOperation])->assertOk();
        $secondConflict = SyncConflict::query()->where('status', 'open')->firstOrFail();

        $this->withHeader((string) config('sync.server_token_header'), $token)
            ->postJson('/api/sync/v1/conflicts/'.$secondConflict->conflict_uuid.'/resolve', [
                'action' => 'accept_local',
            ])
            ->assertOk();

        $this->assertSame('ACCEPTED', $central->fresh()->first_name);
        $this->assertSame('none', $central->fresh()->conflict_status);
    }

    public function test_archived_beneficiary_update_creates_conflict(): void
    {
        ['token' => $token] = $this->enrollServer();

        $central = Beneficiary::factory()->create([
            'first_name' => 'ARCHIVED',
            'last_name' => 'RECORD',
            'location_code' => '1900702001',
            'revision' => 3,
            'archived_at' => now(),
            'archive_reason' => 'Archived centrally',
        ]);

        $operation = $this->createOperation([
            'entity_uuid' => $central->global_uuid,
            'operation' => 'updated',
            'base_revision' => 3,
            'payload' => ['first_name' => 'INCOMING'],
        ]);

        $this->postOperations($token, [$operation])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'conflict')
            ->assertJsonPath('data.0.code', 'revision_conflict');

        $this->assertSame('ARCHIVED', $central->fresh()->first_name);
        $this->assertSame('open', $central->fresh()->conflict_status);

        $conflict = SyncConflict::query()->firstOrFail();
        $this->assertSame('open', $conflict->status);
        $this->assertSame('INCOMING', $conflict->local_payload['first_name']);
        $this->assertArrayHasKey('archived_at', $conflict->remote_payload);
        $this->assertContains('archived_at', $conflict->diff);
    }

    public function test_deleted_batch_update_creates_conflict(): void
    {
        ['token' => $token] = $this->enrollServer();

        $entityUuid = (string) Str::uuid();

        $create = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => ['name' => 'SYNC BATCH', 'description' => 'Created offline'],
        ];

        $this->postOperations($token, [$create])->assertOk();

        $delete = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'deleted',
            'base_revision' => 1,
            'payload' => [],
        ];

        $this->postOperations($token, [$delete])->assertOk();

        $this->assertSoftDeleted('beneficiary_batches', ['global_uuid' => $entityUuid]);

        $update = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'updated',
            'base_revision' => 2,
            'payload' => ['name' => 'UPDATED NAME'],
        ];

        $this->postOperations($token, [$update])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'conflict')
            ->assertJsonPath('data.0.code', 'revision_conflict');

        $this->assertSoftDeleted('beneficiary_batches', ['global_uuid' => $entityUuid]);
        $this->assertSame('open', SyncConflict::query()->firstOrFail()->status);

        $conflict = SyncConflict::query()->firstOrFail();
        $this->assertContains('deleted_at', $conflict->diff);
    }

    public function test_beneficiary_location_change_out_of_scope_archives_locally(): void
    {
        ['token' => $token] = $this->enrollServer();

        // Create a beneficiary in scope via central (simulate central creation)
        $entityUuid = (string) Str::uuid();
        $create = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $entityUuid,
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'SCOPE',
                'last_name' => 'TEST',
                'location_code' => '1900702001', // in scope
                'source_id_number' => 'SRC-'.Str::upper(Str::random(10)),
            ],
        ];

        $this->postOperations($token, [$create])->assertOk();

        $beneficiary = Beneficiary::query()->where('global_uuid', $entityUuid)->firstOrFail();
        $this->assertSame('1900702001', $beneficiary->location_code);
        $this->assertNull($beneficiary->archived_at);

        // Simulate central server updating the location_code to out-of-scope
        // This would generate a removed_from_scope change for this server
        $centralChange = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $entityUuid,
            'operation' => 'removed_from_scope',
            'base_revision' => 2,
            'origin_server_uuid' => 'central-uuid',
            'offline_server_id' => null, // will be set by the test
            'payload' => [
                'data' => [
                    'global_uuid' => $entityUuid,
                    'location_code' => '2000102001', // out of scope
                ],
                'changed_fields' => ['location_code'],
                'old_location_code' => '1900702001',
                'new_location_code' => '2000102001',
            ],
        ];

        $applier = app(LocalChangeApplier::class);
        $result = $applier->apply($centralChange);

        $this->assertSame('applied', $result);

        $beneficiary->refresh();
        $this->assertNotNull($beneficiary->archived_at);
        $this->assertStringContainsString('scope', $beneficiary->archive_reason);
        $this->assertSame('synced', $beneficiary->sync_status);
    }

    public function test_batch_removed_from_scope_is_deleted_locally(): void
    {
        ['token' => $token] = $this->enrollServer();

        $entityUuid = (string) Str::uuid();

        $create = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => ['name' => 'SCOPE BATCH', 'description' => 'In scope'],
        ];

        $this->postOperations($token, [$create])->assertOk();

        $batch = BeneficiaryBatch::query()->where('global_uuid', $entityUuid)->firstOrFail();
        $this->assertNull($batch->deleted_at);

        // Simulate receiving a removed_from_scope change from central
        $centralChange = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $entityUuid,
            'operation' => 'removed_from_scope',
            'base_revision' => 1,
            'origin_server_uuid' => 'central-uuid',
            'payload' => [
                'data' => [
                    'global_uuid' => $entityUuid,
                    'name' => 'SCOPE BATCH',
                ],
                'changed_fields' => ['location_code'],
            ],
        ];

        $applier = app(LocalChangeApplier::class);
        $result = $applier->apply($centralChange);

        $this->assertSame('applied', $result);

        $this->assertSoftDeleted('beneficiary_batches', ['global_uuid' => $entityUuid]);
    }

    public function test_a_central_deletion_removes_the_beneficiary_locally(): void
    {
        ['token' => $token] = $this->enrollServer();
        $create = $this->createOperation();
        $this->postOperations($token, [$create])->assertOk();

        $beneficiary = Beneficiary::query()->where('global_uuid', $create['entity_uuid'])->firstOrFail();

        $change = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $create['entity_uuid'],
            'operation' => 'deleted',
            'revision' => 2,
            'origin_server_uuid' => 'central-uuid',
            'payload' => [
                'data' => ['global_uuid' => $create['entity_uuid']],
                'changed_fields' => ['deleted_at'],
            ],
        ];

        $this->assertSame('applied', app(LocalChangeApplier::class)->apply($change));

        $this->assertDatabaseMissing('beneficiaries', ['id' => $beneficiary->id]);
    }

    public function test_central_create_matching_local_creates_duplicate_conflict(): void
    {
        ['token' => $token] = $this->enrollServer();

        // First, create a local beneficiary directly (not via sync)
        $localUuid = (string) Str::uuid();
        $sourceId = 'SRC-'.Str::upper(Str::random(10));
        $local = Beneficiary::createWithCode([
            'global_uuid' => $localUuid,
            'first_name' => 'LOCAL',
            'last_name' => 'DUPLICATE',
            'birthdate' => '2000-01-01',
            'location_code' => '1900702001',
            'source_id_number' => $sourceId,
            'origin_server_uuid' => SyncIdentity::originUuid(),
            'revision' => 1,
            'sync_status' => 'synced',
            'conflict_status' => 'none',
        ]);

        // Now central sends a 'created' operation with the same source_id_number
        // but a different global_uuid (simulating another server creating the same person)
        $centralUuid = (string) Str::uuid();
        $centralChange = [
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $centralUuid,
            'operation' => 'created',
            'base_revision' => 0,
            'origin_server_uuid' => 'other-server-uuid',
            'payload' => [
                'data' => [
                    'first_name' => 'LOCAL',
                    'last_name' => 'DUPLICATE',
                    'birthdate' => '2000-01-01',
                    'location_code' => '1900702001',
                    'source_id_number' => $sourceId,
                ],
                'changed_fields' => ['first_name', 'last_name', 'birthdate', 'location_code', 'source_id_number'],
            ],
        ];

        $applier = app(LocalChangeApplier::class);
        $result = $applier->apply($centralChange);

        // Should create a conflict, not apply the change
        $this->assertSame('conflict', $result);

        // Local record should still exist and be marked as conflict
        $local->refresh();
        $this->assertSame('open', $local->conflict_status);

        // A SyncConflict should have been created
        $conflict = SyncConflict::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $localUuid)
            ->firstOrFail();
        $this->assertSame('open', $conflict->status);
        $this->assertContains('duplicate', $conflict->diff);

        // The central record should NOT have been created
        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $centralUuid]);
    }
}
