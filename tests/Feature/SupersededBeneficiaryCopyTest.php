<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncEvent;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Services\Sync\SnapshotDownloadService;
use App\Services\Sync\SyncOperationRecorder;
use App\Services\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Central Server re-imports records from time to time: a record that is
 * replaced keeps its source ID number but is given a new identity. An offline
 * installation still holds the copy of the previous generation, and the
 * incoming record has to take its place.
 */
class SupersededBeneficiaryCopyTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL_UUID = '44444444-4444-4444-8444-444444444444';

    private const SOURCE_ID = 'DOLE XII -SCFO-BARMM-1507-102026-003';

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();
    }

    private function configureOffline(): void
    {
        config([
            'sync.instance_mode' => 'offline',
            'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'sync.bootstrap_url' => 'https://central.test',
        ]);

        SyncSetting::set('central_url', 'https://central.test');
        SyncSetting::set('server_uuid', '22222222-2222-4222-8222-222222222222');
        SyncSetting::setEncrypted('server_token', 'tims_sync_test_token');
        SyncSetting::flushCache();
    }

    /**
     * A copy the Central Server replaced: same batch, same source ID number,
     * but the identity of the previous generation. Created the way a snapshot
     * import creates it, without queueing an upload.
     */
    private function staleCopy(BeneficiaryBatch $batch): Beneficiary
    {
        return SyncOperationRecorder::withoutRecording(fn (): Beneficiary => Beneficiary::factory()->create([
            'origin_server_uuid' => self::CENTRAL_UUID,
            'beneficiary_batch_id' => $batch->id,
            'source_id_number' => self::SOURCE_ID,
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00001',
            'first_name' => 'CENTRAL',
            'last_name' => 'PERSON',
        ]));
    }

    private function fakeSnapshot(BeneficiaryBatch $batch, string $incomingUuid): void
    {
        Http::fake([
            'https://central.test/api/sync/v1/snapshot' => Http::response([
                'data' => [
                    'snapshot_uuid' => 'snapshot-uuid-1',
                    'status' => 'ready',
                    'revision_to' => 42,
                ],
            ], 201),
            'https://central.test/api/sync/v1/snapshot/snapshot-uuid-1/reference' => Http::response([
                'data' => [
                    'locations' => [],
                    'batches' => [[
                        'global_uuid' => $batch->global_uuid,
                        'origin_server_uuid' => self::CENTRAL_UUID,
                        'name' => $batch->name,
                        'status' => 'open',
                        'revision' => 1,
                    ]],
                    'trainings' => [],
                ],
            ]),
            'https://central.test/api/sync/v1/snapshot/snapshot-uuid-1/beneficiaries*' => Http::response([
                'data' => [[
                    'global_uuid' => $incomingUuid,
                    'origin_server_uuid' => self::CENTRAL_UUID,
                    'revision' => 1,
                    'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-01101',
                    'source_id_number' => self::SOURCE_ID,
                    'first_name' => 'CENTRAL',
                    'last_name' => 'PERSON',
                    'birthdate' => '1990-01-01',
                    'location_code' => '1900702001',
                    'beneficiary_batch_uuid' => $batch->global_uuid,
                    'duplicate_status' => 'none',
                ]],
                'meta' => ['next_cursor' => 10, 'has_more' => false, 'count' => 1],
            ]),
        ]);
    }

    public function test_a_snapshot_record_replaces_the_copy_the_central_server_replaced(): void
    {
        $this->configureOffline();

        $batch = BeneficiaryBatch::factory()->create([
            'global_uuid' => 'batch-uuid-1',
            'origin_server_uuid' => self::CENTRAL_UUID,
        ]);

        $stale = $this->staleCopy($batch);
        $incomingUuid = '66666666-6666-4666-8666-666666666666';

        $this->fakeSnapshot($batch, $incomingUuid);

        app(SnapshotDownloadService::class)->download();

        $incoming = Beneficiary::query()->where('global_uuid', $incomingUuid)->firstOrFail();

        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01101', $incoming->beneficiary_code);
        $this->assertSame(self::SOURCE_ID, $incoming->source_id_number);
        $this->assertSame($batch->id, $incoming->beneficiary_batch_id);

        // The copy of the previous generation is gone.
        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $stale->global_uuid]);

        $this->assertDatabaseHas('sync_events', [
            'type' => 'beneficiary_superseded_copy_removed',
            'severity' => 'warning',
        ]);
    }

    public function test_a_snapshot_record_is_stored_when_the_copy_it_replaces_still_holds_the_source_id(): void
    {
        $this->configureOffline();

        $batch = BeneficiaryBatch::factory()->create([
            'global_uuid' => 'batch-uuid-1',
            'origin_server_uuid' => self::CENTRAL_UUID,
        ]);

        $this->staleCopy($batch);
        $incomingUuid = '66666666-6666-4666-8666-666666666666';

        $this->fakeSnapshot($batch, $incomingUuid);

        // Without the replacement the insert breaks on the unique constraint
        // (origin, batch, source ID number).
        app(SnapshotDownloadService::class)->download();

        $this->assertSame(1, Beneficiary::query()->where('global_uuid', $incomingUuid)->count());
        $this->assertSame(1, Beneficiary::query()->count());
    }

    public function test_a_copy_with_queued_edits_is_archived_instead_of_removed(): void
    {
        $this->configureOffline();

        $batch = BeneficiaryBatch::factory()->create([
            'global_uuid' => 'batch-uuid-1',
            'origin_server_uuid' => self::CENTRAL_UUID,
        ]);

        $stale = $this->staleCopy($batch);

        SyncOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => $stale->global_uuid,
            'operation' => 'updated',
            'origin_server_uuid' => '22222222-2222-4222-8222-222222222222',
            'payload' => ['beneficiary_code' => $stale->beneficiary_code],
            'status' => SyncOperation::STATUS_PENDING,
        ]);

        $incomingUuid = '66666666-6666-4666-8666-666666666666';

        $this->fakeSnapshot($batch, $incomingUuid);

        app(SnapshotDownloadService::class)->download();

        $kept = Beneficiary::query()->where('global_uuid', $stale->global_uuid)->firstOrFail();

        $this->assertNotNull($kept->archived_at);
        $this->assertNull($kept->source_id_number);
        $this->assertNotSame('DOLE XII-SCFO-BARMM-102026-01101', $kept->beneficiary_code);

        $incoming = Beneficiary::query()->where('global_uuid', $incomingUuid)->firstOrFail();

        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01101', $incoming->beneficiary_code);

        $this->assertDatabaseHas('sync_events', [
            'type' => 'beneficiary_superseded_copy_archived',
            'severity' => 'warning',
        ]);
    }

    public function test_a_delta_change_replaces_the_copy_the_central_server_replaced(): void
    {
        $this->configureOffline();

        $batch = BeneficiaryBatch::factory()->create([
            'global_uuid' => 'batch-uuid-1',
            'origin_server_uuid' => self::CENTRAL_UUID,
        ]);

        $stale = $this->staleCopy($batch);
        $incomingUuid = (string) Str::uuid();

        Http::fake([
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 10,
                    'change_uuid' => (string) Str::uuid(),
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $incomingUuid,
                    'operation' => 'created',
                    'origin_server_uuid' => self::CENTRAL_UUID,
                    'revision' => 1,
                    'payload' => [
                        'changed_fields' => ['first_name'],
                        'data' => [
                            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-01101',
                            'source_id_number' => self::SOURCE_ID,
                            'first_name' => 'CENTRAL',
                            'last_name' => 'PERSON',
                            'birthdate' => '1990-01-01',
                            'beneficiary_batch_uuid' => $batch->global_uuid,
                        ],
                    ],
                ]],
                'meta' => ['next_cursor' => 10, 'has_more' => false, 'count' => 1],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $result = app(SyncRunner::class)->download();

        $this->assertSame(1, $result['applied']);

        $this->assertDatabaseMissing('beneficiaries', ['global_uuid' => $stale->global_uuid]);

        $incoming = Beneficiary::query()->where('global_uuid', $incomingUuid)->firstOrFail();

        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01101', $incoming->beneficiary_code);
        $this->assertSame(self::SOURCE_ID, $incoming->source_id_number);
    }

    public function test_a_record_of_this_installation_is_never_removed(): void
    {
        $this->configureOffline();

        $batch = BeneficiaryBatch::factory()->create([
            'global_uuid' => 'batch-uuid-1',
            'origin_server_uuid' => self::CENTRAL_UUID,
        ]);

        $local = Beneficiary::factory()->create([
            'origin_server_uuid' => '22222222-2222-4222-8222-222222222222',
            'beneficiary_batch_id' => $batch->id,
            'source_id_number' => self::SOURCE_ID,
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00002-2222',
            'first_name' => 'LOCAL',
            'last_name' => 'PERSON',
        ]);

        $incomingUuid = '66666666-6666-4666-8666-666666666666';

        $this->fakeSnapshot($batch, $incomingUuid);

        app(SnapshotDownloadService::class)->download();

        $this->assertDatabaseHas('beneficiaries', ['global_uuid' => $local->global_uuid]);
        $this->assertSame(0, SyncEvent::query()->where('type', 'beneficiary_superseded_copy_removed')->count());
    }
}
