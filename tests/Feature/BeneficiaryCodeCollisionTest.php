<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\SyncEvent;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Services\Sync\SnapshotDownloadService;
use App\Services\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeneficiaryCodeCollisionTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_an_offline_installation_issues_codes_with_its_own_server_marker(): void
    {
        $this->configureOffline();

        $beneficiary = Beneficiary::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^DOLE XII-SCFO-BARMM-\d{6}-\d{5}-1111$/',
            $beneficiary->beneficiary_code,
        );
    }

    public function test_the_central_server_keeps_the_plain_code_format(): void
    {
        config([
            'sync.instance_mode' => 'online',
            'sync.central_server_uuid' => '44444444-4444-4444-8444-444444444444',
        ]);

        $beneficiary = Beneficiary::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^DOLE XII-SCFO-BARMM-\d{6}-\d{5}$/',
            $beneficiary->beneficiary_code,
        );
    }

    public function test_a_snapshot_record_keeps_its_code_and_the_local_record_is_reissued(): void
    {
        $this->configureOffline();

        $local = Beneficiary::factory()->create([
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00001',
        ]);

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
                'data' => [[
                    'global_uuid' => '66666666-6666-4666-8666-666666666666',
                    'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'revision' => 5,
                    'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00001',
                    'first_name' => 'CENTRAL',
                    'last_name' => 'PERSON',
                    'birthdate' => '1990-01-01',
                    'location_code' => '1900702001',
                    'beneficiary_batch_uuid' => null,
                    'duplicate_status' => 'none',
                ]],
                'meta' => ['next_cursor' => 10, 'has_more' => false, 'count' => 1],
            ]),
        ]);

        app(SnapshotDownloadService::class)->download();

        $incoming = Beneficiary::query()
            ->where('global_uuid', '66666666-6666-4666-8666-666666666666')
            ->firstOrFail();

        // The incoming record keeps the code the Central Server issued.
        $this->assertSame('DOLE XII-SCFO-BARMM-102026-00001', $incoming->beneficiary_code);

        // The local record that held it is re-issued a code of its own.
        $reissued = $local->fresh()->beneficiary_code;

        $this->assertNotSame('DOLE XII-SCFO-BARMM-102026-00001', $reissued);
        $this->assertStringEndsWith('-1111', $reissued);

        $this->assertDatabaseHas('sync_events', [
            'type' => 'beneficiary_code_reissued',
            'severity' => 'warning',
        ]);

        // The queued upload of the local record carries the new code, so the
        // Central Server never receives the colliding one.
        $queued = SyncOperation::query()
            ->where('entity_uuid', $local->global_uuid)
            ->firstOrFail();

        $this->assertSame($reissued, $queued->fresh()->payload['beneficiary_code']);
    }

    public function test_a_delta_change_with_a_colliding_code_is_applied(): void
    {
        $this->configureOffline();

        $local = Beneficiary::factory()->create([
            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00001',
        ]);

        $uuid = (string) Str::uuid();

        Http::fake([
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 10,
                    'change_uuid' => (string) Str::uuid(),
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $uuid,
                    'operation' => 'updated',
                    'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'revision' => 4,
                    'payload' => [
                        'changed_fields' => ['first_name'],
                        'data' => [
                            'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00001',
                            'first_name' => 'CENTRAL',
                            'last_name' => 'PERSON',
                            'location_code' => '1900702001',
                            'birthdate' => '1990-01-01',
                        ],
                    ],
                ]],
                'meta' => ['next_cursor' => 10, 'has_more' => false, 'count' => 1],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $result = app(SyncRunner::class)->download();

        $this->assertSame(1, $result['applied']);

        $incoming = Beneficiary::query()->where('global_uuid', $uuid)->firstOrFail();

        $this->assertSame('DOLE XII-SCFO-BARMM-102026-00001', $incoming->beneficiary_code);
        $this->assertNotSame('DOLE XII-SCFO-BARMM-102026-00001', $local->fresh()->beneficiary_code);
    }

    public function test_a_record_that_already_holds_the_code_keeps_it(): void
    {
        $this->configureOffline();

        $uuid = (string) Str::uuid();

        $existing = Beneficiary::factory()->create([
            'global_uuid' => $uuid,
            'beneficiary_code' => 'REMOTE-0001',
        ]);

        Http::fake([
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 10,
                    'change_uuid' => (string) Str::uuid(),
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $uuid,
                    'operation' => 'updated',
                    'origin_server_uuid' => '44444444-4444-4444-8444-444444444444',
                    'revision' => 4,
                    'payload' => [
                        'changed_fields' => ['first_name'],
                        'data' => [
                            'beneficiary_code' => 'REMOTE-0001',
                            'first_name' => 'CENTRAL',
                            'last_name' => 'PERSON',
                        ],
                    ],
                ]],
                'meta' => ['next_cursor' => 10, 'has_more' => false, 'count' => 1],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        app(SyncRunner::class)->download();

        $this->assertSame('REMOTE-0001', $existing->fresh()->beneficiary_code);
        $this->assertSame(0, SyncEvent::query()->where('type', 'beneficiary_code_reissued')->count());
    }
}
