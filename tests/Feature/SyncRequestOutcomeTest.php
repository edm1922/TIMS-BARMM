<?php

namespace Tests\Feature;

use App\Models\OfflineServer;
use App\Models\SyncOperation;
use App\Models\SyncRequest;
use App\Models\SyncRequestItem;
use App\Models\SyncSetting;
use App\Services\Sync\SyncRunner;
use App\Services\Sync\TokenService;
use App\Support\SyncIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5, offline half: a record the central server holds for review must not
 * be presented as accepted, and the administrator's decision must reach the
 * local queue idempotently.
 */
class SyncRequestOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'offline']);

        SyncSetting::set('central_url', 'https://central.test');
        SyncSetting::set('server_uuid', (string) Str::uuid());
        SyncSetting::setEncrypted('server_token', 'local-test-token');
        SyncSetting::set('last_request_outcome_cursor', '0');
        SyncSetting::flushCache();
    }

    private function localOperation(array $overrides = []): SyncOperation
    {
        return SyncOperation::create(array_merge([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'base_revision' => 0,
            'payload' => [
                'first_name' => 'LOCAL',
                'last_name' => 'RECORD',
                'location_code' => '1900702001',
            ],
            'status' => SyncOperation::STATUS_PENDING,
            'origin_server_uuid' => SyncSetting::get('server_uuid'),
        ], $overrides));
    }

    public function test_a_held_duplicate_is_marked_awaiting_review_not_synced(): void
    {
        $operation = $this->localOperation();

        Http::fake([
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => $operation->operation_uuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $operation->entity_uuid,
                    'status' => 'duplicate',
                    'code' => 'duplicate_beneficiary',
                    'message' => 'A matching beneficiary already exists.',
                ]],
                'meta' => ['count' => 1, 'applied' => 0, 'duplicate' => 1, 'conflict' => 0, 'rejected' => 0],
            ]),
        ]);

        $result = app(SyncRunner::class)->upload();

        $this->assertSame(1, $result['awaiting_review']);
        $this->assertSame(0, $result['applied']);
        $this->assertSame(SyncOperation::STATUS_AWAITING_REVIEW, $operation->fresh()->status);
    }

    public function test_a_reviewer_confirmed_duplicate_is_marked_synced(): void
    {
        $operation = $this->localOperation();

        Http::fake([
            'https://central.test/api/sync/v1/operations' => Http::response([
                'data' => [[
                    'operation_uuid' => $operation->operation_uuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $operation->entity_uuid,
                    'status' => 'duplicate',
                    'code' => 'duplicate_confirmed',
                    'message' => 'Confirmed as the existing record.',
                ]],
            ]),
        ]);

        $result = app(SyncRunner::class)->upload();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(SyncOperation::STATUS_SYNCED, $operation->fresh()->status);
    }

    public function test_an_operation_awaiting_review_is_not_reuploaded(): void
    {
        $operation = $this->localOperation(['status' => SyncOperation::STATUS_AWAITING_REVIEW]);

        Http::fake();

        $result = app(SyncRunner::class)->upload();

        $this->assertSame(0, $result['attempted']);
        Http::assertNothingSent();
        $this->assertSame(SyncOperation::STATUS_AWAITING_REVIEW, $operation->fresh()->status);
    }

    public function test_reconcile_applies_accept_and_reject_decisions_once(): void
    {
        $accepted = $this->localOperation(['status' => SyncOperation::STATUS_AWAITING_REVIEW]);
        $rejected = $this->localOperation(['status' => SyncOperation::STATUS_AWAITING_REVIEW]);

        Http::fake([
            'https://central.test/api/sync/v1/request-outcomes*' => Http::response([
                'data' => [
                    [
                        'item_id' => 11,
                        'operation_uuid' => $accepted->operation_uuid,
                        'status' => 'accepted',
                        'code' => null,
                        'message' => null,
                        'revision' => 4,
                    ],
                    [
                        'item_id' => 12,
                        'operation_uuid' => $rejected->operation_uuid,
                        'status' => 'rejected',
                        'code' => 'reviewer_rejected',
                        'message' => 'Same person already registered.',
                        'revision' => null,
                    ],
                ],
                'meta' => ['count' => 2, 'next_cursor' => 12, 'has_more' => false],
            ]),
        ]);

        $counts = app(SyncRunner::class)->reconcileOutcomes();

        $this->assertSame(1, $counts['accepted']);
        $this->assertSame(1, $counts['rejected']);
        $this->assertSame(12, $counts['cursor']);
        $this->assertSame(SyncOperation::STATUS_SYNCED, $accepted->fresh()->status);
        $this->assertSame(SyncOperation::STATUS_REJECTED, $rejected->fresh()->status);
        $this->assertSame('Same person already registered.', $rejected->fresh()->last_error);
        $this->assertSame('12', SyncSetting::get('last_request_outcome_cursor'));

        // The next run asks from the stored cursor, so decisions are consumed
        // once and replayed decisions never double-count.
        Http::fake([
            'https://central.test/api/sync/v1/request-outcomes*' => Http::response([
                'data' => [],
                'meta' => ['count' => 0, 'next_cursor' => 12, 'has_more' => false],
            ]),
        ]);

        $second = app(SyncRunner::class)->reconcileOutcomes();

        $this->assertSame(0, $second['accepted']);
        $this->assertSame(0, $second['rejected']);
        $this->assertSame(12, $second['cursor']);
    }

    public function test_the_outcome_endpoint_only_returns_decisions_for_the_calling_server(): void
    {
        /** @var OfflineServer $mine */
        $mine = $this->enroll('Outcome Mine');
        /** @var OfflineServer $other */
        $other = $this->enroll('Outcome Other');

        $this->decidedItem($mine, SyncRequestItem::STATUS_ACCEPTED, 3, 'accepted-id');
        $this->decidedItem($mine, SyncRequestItem::STATUS_REJECTED, null, 'rejected-id');
        $this->decidedItem($other, SyncRequestItem::STATUS_ACCEPTED, 9, 'other-server');

        $response = $this->withHeader((string) config('sync.server_token_header'), $mine->plain_token)
            ->getJson('/api/sync/v1/request-outcomes')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', false);

        $operationUuids = array_column($response->json('data'), 'operation_uuid');

        $this->assertContains('accepted-id', $operationUuids);
        $this->assertContains('rejected-id', $operationUuids);
        $this->assertNotContains('other-server', $operationUuids);
        $this->assertNotNull($response->json('meta.next_cursor'));
    }

    public function test_the_outcome_endpoint_pages_from_the_since_cursor(): void
    {
        /** @var OfflineServer $server */
        $server = $this->enroll('Outcome Pagination');

        for ($i = 1; $i <= 3; $i++) {
            $this->decidedItem($server, SyncRequestItem::STATUS_ACCEPTED, $i, 'op-'.$i);
        }

        $firstPage = $this->withHeader((string) config('sync.server_token_header'), $server->plain_token)
            ->getJson('/api/sync/v1/request-outcomes?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('data.0.operation_uuid', 'op-1');

        $cursor = $firstPage->json('meta.next_cursor');

        $this->withHeader((string) config('sync.server_token_header'), $server->plain_token)
            ->getJson('/api/sync/v1/request-outcomes?since='.$cursor.'&limit=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation_uuid', 'op-3')
            ->assertJsonPath('meta.has_more', false);
    }

    private function enroll(string $name): OfflineServer
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => $name,
            'server_uuid' => (string) Str::uuid(),
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'city_municipality',
            'scope_code' => '1900702000',
        ])->assertCreated();

        $server = OfflineServer::query()
            ->where('server_uuid', $response->json('data.server_uuid'))
            ->firstOrFail();

        // Keep the plaintext token for the request headers in this test.
        $server->plain_token = (string) $response->json('data.server_token');

        return $server;
    }

    private function decidedItem(OfflineServer $server, string $status, ?int $revision, string $operationUuid): void
    {
        $request = SyncRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'offline_server_id' => $server->id,
            'status' => $status === SyncRequestItem::STATUS_ACCEPTED
                ? SyncRequest::STATUS_ACCEPTED
                : SyncRequest::STATUS_REJECTED,
            'total_operations' => 1,
            'accepted_count' => $status === SyncRequestItem::STATUS_ACCEPTED ? 1 : 0,
            'rejected_count' => $status === SyncRequestItem::STATUS_REJECTED ? 1 : 0,
            'submitted_at' => now(),
        ]);

        SyncRequestItem::create([
            'sync_request_id' => $request->id,
            'operation_uuid' => $operationUuid,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'payload' => [],
            'status' => $status,
            'result' => ['status' => $status === SyncRequestItem::STATUS_ACCEPTED ? 'applied' : 'rejected', 'revision' => $revision],
            'decided_at' => now(),
            'decision_reason' => $status === SyncRequestItem::STATUS_REJECTED ? 'Duplicate' : null,
        ]);
    }
}
