<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Services\Sync\SnapshotDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A snapshot (or delta) may bring the authoritative version of a beneficiary
 * whose government ID is still held locally by a stale copy of the same origin
 * (for example after a batch re-import replaced the record identity). The stale
 * copy must be superseded instead of aborting the whole download with a unique
 * constraint violation.
 */
class SyncGovernmentIdCollisionTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL_UUID = '44444444-4444-4444-8444-444444444444';

    private const GOVERNMENT_ID = '5824-7206-4287-9604';

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();

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

    private function fakeSnapshot(array $row): void
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
                'data' => ['locations' => [], 'batches' => [], 'trainings' => []],
            ]),
            'https://central.test/api/sync/v1/snapshot/snapshot-uuid-1/beneficiaries*' => Http::response([
                'data' => [$row],
                'meta' => ['next_cursor' => 10, 'has_more' => false, 'count' => 1],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function incomingRow(): array
    {
        return [
            'global_uuid' => '5a68f946-8d4b-4f98-8f35-4dc415053152',
            'origin_server_uuid' => self::CENTRAL_UUID,
            'revision' => 1,
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-02604',
            'first_name' => 'HALIMA',
            'middle_name' => 'MANGIGEN',
            'last_name' => 'ZAMAN',
            'birthdate' => '1978-07-15',
            'source_id_no' => self::GOVERNMENT_ID,
            'id_type' => 'NATIONAL ID',
            'barangay' => 'MATILAC',
            'city_municipality' => 'PAHAMUDDIN',
            'beneficiary_batch_uuid' => null,
            'duplicate_status' => 'none',
        ];
    }

    public function test_a_snapshot_replaces_a_stale_copy_holding_the_same_government_id(): void
    {
        $stale = Beneficiary::factory()->create([
            'global_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'origin_server_uuid' => self::CENTRAL_UUID,
            'source_id_no' => self::GOVERNMENT_ID,
            'first_name' => 'STALE',
            'last_name' => 'COPY',
        ]);

        $this->fakeSnapshot($this->incomingRow());

        app(SnapshotDownloadService::class)->download();

        $incoming = Beneficiary::query()
            ->where('global_uuid', '5a68f946-8d4b-4f98-8f35-4dc415053152')
            ->firstOrFail();

        $this->assertSame(self::GOVERNMENT_ID, $incoming->source_id_no);
        $this->assertSame('HALIMA', $incoming->first_name);

        // The stale copy is superseded, so the unique slot is free again.
        $stale->refresh();
        $this->assertNull($stale->source_id_no);

        $this->assertSame(1, Beneficiary::query()
            ->where('source_id_no', self::GOVERNMENT_ID)
            ->where('origin_server_uuid', self::CENTRAL_UUID)
            ->count());

        $this->assertDatabaseHas('sync_events', [
            'type' => 'beneficiary_superseded_government_id',
        ]);
    }

    public function test_a_stale_copy_with_queued_edits_is_archived_instead_of_deleted(): void
    {
        $stale = Beneficiary::factory()->create([
            'global_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'origin_server_uuid' => self::CENTRAL_UUID,
            'source_id_no' => self::GOVERNMENT_ID,
        ]);

        SyncOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'entity_type' => 'beneficiary',
            'entity_uuid' => $stale->global_uuid,
            'operation' => 'updated',
            'status' => SyncOperation::STATUS_PENDING,
            'payload' => ['first_name' => 'EDITED'],
        ]);

        $this->fakeSnapshot($this->incomingRow());

        app(SnapshotDownloadService::class)->download();

        $stale->refresh();
        $this->assertNull($stale->source_id_no);
        $this->assertNull($stale->deleted_at);
        $this->assertNotNull($stale->archived_at);
        $this->assertSame('Superseded by a newer copy of this record.', $stale->archive_reason);

        $this->assertSame(1, Beneficiary::query()
            ->where('source_id_no', self::GOVERNMENT_ID)
            ->whereNull('archived_at')
            ->count());
    }
}
