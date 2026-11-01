<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\SyncEvent;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Services\Sync\SyncRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sync.instance_mode' => 'offline',
            'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);

        SyncSetting::flushCache();
        SyncSetting::set('central_url', 'https://central.test');
        SyncSetting::set('server_uuid', '22222222-2222-4222-8222-222222222222');
        SyncSetting::setEncrypted('server_token', 'tims_sync_test_token');
        SyncSetting::flushCache();
    }

    public function test_upload_marks_operations_synced_and_records_the_central_revision(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Local note.',
        ]);
        $operation = SyncOperation::query()->firstOrFail();

        Http::fake([
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => $operation->operation_uuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $beneficiary->global_uuid,
                    'status' => 'applied',
                    'revision' => 1,
                ]],
                'meta' => ['count' => 1, 'applied' => 1],
            ]),
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        app(SyncRunner::class)->run();

        $this->assertSame(SyncOperation::STATUS_SYNCED, $operation->fresh()->status);
        $this->assertSame(1, $beneficiary->fresh()->revision);
        $this->assertSame(1, $beneficiary->fresh()->server_revision);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://central.test/api/sync/v1/operations'
            && $request['operations'][0]['operation_uuid'] === $operation->operation_uuid
            && $request['operations'][0]['payload']['notes'] === 'Local note.');
    }

    public function test_download_applies_central_changes_and_advances_the_cursor(): void
    {
        $uuid = '88888888-8888-4888-8888-888888888888';

        Http::fake([
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 10,
                    'change_uuid' => '99999999-9999-4999-8999-999999999999',
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $uuid,
                    'operation' => 'created',
                    'origin_server_uuid' => '33333333-3333-4333-8333-333333333333',
                    'revision' => 4,
                    'payload' => [
                        'changed_fields' => ['first_name'],
                        'data' => [
                            'beneficiary_code' => 'REMOTE-0001',
                            'first_name' => 'REMOTE',
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
        $this->assertSame(10, $result['cursor']);

        $beneficiary = Beneficiary::query()->where('global_uuid', $uuid)->firstOrFail();

        $this->assertSame('REMOTE-0001', $beneficiary->beneficiary_code);
        $this->assertSame(4, $beneficiary->revision);
        $this->assertSame('synced', $beneficiary->sync_status);
        $this->assertSame(0, SyncOperation::query()->count());
        $this->assertSame('10', SyncSetting::get('last_change_cursor'));
    }

    public function test_download_marks_a_conflict_instead_of_overwriting_unsynced_local_edits(): void
    {
        $beneficiary = Beneficiary::factory()->create([
            'location_code' => '1900702001',
            'notes' => 'Local edit must survive.',
        ]);
        $operation = SyncOperation::query()->latest('id')->firstOrFail();

        Http::fake([
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 11,
                    'change_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $beneficiary->global_uuid,
                    'operation' => 'updated',
                    'origin_server_uuid' => '33333333-3333-4333-8333-333333333333',
                    'revision' => 9,
                    'payload' => [
                        'changed_fields' => ['notes'],
                        'data' => ['notes' => 'Remote edit.'],
                    ],
                ]],
                'meta' => ['next_cursor' => 11, 'has_more' => false, 'count' => 1],
            ]),
        ]);

        $result = app(SyncRunner::class)->download();

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame('Local edit must survive.', $beneficiary->fresh()->notes);
        $this->assertSame('open', $beneficiary->fresh()->conflict_status);
        $this->assertSame(SyncOperation::STATUS_CONFLICT, $operation->fresh()->status);
        $this->assertSame(1, SyncEvent::query()->where('type', 'conflict')->count());
    }

    public function test_upload_marks_operations_awaiting_review_when_central_holds_them(): void
    {
        Beneficiary::factory()->create(['location_code' => '1900702001']);
        $operation = SyncOperation::query()->firstOrFail();

        Http::fake([
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => $operation->operation_uuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $operation->entity_uuid,
                    'status' => 'awaiting_review',
                    'code' => 'deletion_pending_approval',
                    'message' => 'The deletion is waiting for central administrator approval.',
                ]],
            ]),
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [],
                'meta' => ['next_cursor' => null, 'has_more' => false, 'count' => 0],
            ]),
            'https://central.test/api/sync/v1/ack' => Http::response(['data' => []]),
        ]);

        $result = app(SyncRunner::class)->upload();

        $this->assertSame(1, $result['awaiting_review']);
        $this->assertSame(SyncOperation::STATUS_AWAITING_REVIEW, $operation->fresh()->status);
    }

    public function test_upload_failure_schedules_a_retry_with_backoff(): void
    {
        Beneficiary::factory()->create(['location_code' => '1900702001']);
        $operation = SyncOperation::query()->firstOrFail();

        Http::fake([
            'https://central.test/api/sync/v1/operations' => Http::response([
                'error' => ['message' => 'Temporary failure.'],
            ], 503),
        ]);

        $result = app(SyncRunner::class)->upload();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(SyncOperation::STATUS_FAILED, $operation->fresh()->status);
        $this->assertSame(1, $operation->fresh()->attempts);
        $this->assertTrue($operation->fresh()->available_at->isFuture());
    }

    public function test_changes_originating_from_this_server_are_skipped(): void
    {
        Beneficiary::factory()->create([
            'global_uuid' => '77777777-7777-4777-8777-777777777777',
            'location_code' => '1900702001',
            'notes' => 'Keep this.',
        ]);
        SyncOperation::query()->delete();

        Http::fake([
            'https://central.test/api/sync/v1/changes*' => Http::response([
                'data' => [[
                    'id' => 12,
                    'change_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => '77777777-7777-4777-8777-777777777777',
                    'operation' => 'updated',
                    'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
                    'revision' => 9,
                    'payload' => [
                        'changed_fields' => ['notes'],
                        'data' => ['notes' => 'Echoed change.'],
                    ],
                ]],
                'meta' => ['next_cursor' => 12, 'has_more' => false, 'count' => 1],
            ]),
        ]);

        $result = app(SyncRunner::class)->download();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Keep this.', Beneficiary::query()->firstOrFail()->notes);
    }
}
