<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\SyncConflict;
use App\Models\SyncConflictDecision;
use App\Models\SyncRequestItem;
use App\Models\User;
use App\Services\Sync\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Central-master safety: an offline deletion of a record the central server
 * knows is never applied silently — it is held for administrator approval,
 * and both decisions (keep / approve) propagate back correctly.
 */
class DeletionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private string $serverToken;

    private OfflineServer $server;

    private Beneficiary $beneficiary;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'online']);

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

        $this->server = OfflineServer::query()->latest('id')->firstOrFail();
        $this->serverToken = $enrolled['server_token'];

        $batch = BeneficiaryBatch::factory()->create(['name' => 'South Upi']);
        $this->beneficiary = Beneficiary::factory()->create([
            'beneficiary_batch_id' => $batch->id,
            'location_code' => '1900702001',
            'first_name' => 'DELETABLE',
            'last_name' => 'PERSON',
        ]);
    }

    private string $operationUuid;

    /**
     * Submit a deletion operation from the offline installation. The same
     * operation UUID is reused across calls so a second submit is a true
     * retry of the first (receipt-replay path).
     *
     * @return array<string, mixed>
     */
    private function submitDeletion(): array
    {
        $this->operationUuid ??= (string) Str::uuid();

        $response = $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => $this->operationUuid,
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $this->beneficiary->global_uuid,
                    'operation' => 'deleted',
                    'base_revision' => $this->beneficiary->revision,
                    'payload' => ['delete_reason' => 'Encoder removed a duplicate entry.'],
                ]],
            ]);

        $response->assertOk();

        return $response->json('data.0');
    }

    public function test_an_offline_deletion_is_held_for_central_approval(): void
    {
        $result = $this->submitDeletion();

        $this->assertSame('awaiting_review', $result['status']);
        $this->assertSame('deletion_pending_approval', $result['code']);
        $this->assertNotEmpty($result['conflict_uuid']);

        // The central record is preserved untouched.
        $this->assertDatabaseHas('beneficiaries', ['id' => $this->beneficiary->id, 'archived_at' => null]);

        $conflict = SyncConflict::query()->latest('id')->firstOrFail();
        $this->assertSame('open', $conflict->status);
        $this->assertSame('deleted', $conflict->operation);
        $this->assertSame($this->server->id, $conflict->offline_server_id);
        $this->assertSame('Encoder removed a duplicate entry.', data_get($conflict->remote_payload, 'delete_reason'));
        $this->assertSame('DELETABLE', data_get($conflict->local_payload, 'first_name'));
    }

    public function test_the_held_item_is_linked_to_its_conflict_and_appears_once_in_the_inbox(): void
    {
        $result = $this->submitDeletion();

        $item = SyncRequestItem::query()
            ->where('operation_uuid', $this->operationUuid)
            ->firstOrFail();

        $this->assertSame($result['conflict_uuid'], $item->conflict_uuid);

        $inbox = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('review-inbox.index'))
            ->assertOk();

        // One conflict row, no duplicate "Invalid record" row for the item.
        $this->assertSame(1, $inbox->viewData('entries')->total());
        $this->assertSame('Deletion approval', $inbox->viewData('entries')->first()['label']);
    }

    public function test_the_request_page_cannot_decide_a_deletion_hold(): void
    {
        $this->submitDeletion();

        $item = SyncRequestItem::query()
            ->where('operation_uuid', $this->operationUuid)
            ->firstOrFail();

        $request = $item->request;
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // The per-record accept/reject actions cannot express a deletion decision.
        $this->actingAs($admin)
            ->get(route('sync-requests.show', $request))
            ->assertOk()
            ->assertSee('Review this record in the Review inbox')
            ->assertDontSee('Accept record');

        $this->actingAs($admin)
            ->post(route('sync-requests.items.approve', [$request, $item]), ['action' => 'accept_local'])
            ->assertSessionHasErrors('review');

        $this->actingAs($admin)
            ->post(route('sync-requests.items.reject', [$request, $item]), ['reason' => 'no'])
            ->assertSessionHasErrors('review');

        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->fresh()->status);
        $this->assertSame('open', SyncConflict::query()->firstOrFail()->status);
    }

    public function test_a_retried_deletion_reuses_the_one_open_approval(): void
    {
        $first = $this->submitDeletion();
        $second = $this->submitDeletion();

        $this->assertSame('awaiting_review', $second['status']);
        $this->assertSame($first['conflict_uuid'], $second['conflict_uuid']);
        $this->assertSame(1, SyncConflict::query()->where('operation', 'deleted')->count());
    }

    public function test_keep_record_retains_the_beneficiary_and_rejects_the_operation(): void
    {
        $this->submitDeletion();

        $conflict = SyncConflict::query()->where('operation', 'deleted')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'keep_record'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The central record survives, fully intact and not archived.
        $this->beneficiary->refresh();
        $this->assertNull($this->beneficiary->archived_at);

        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'beneficiary.deletion_kept']);

        // The decision is recorded in the immutable history with the resolver.
        $this->assertSame(
            1,
            SyncConflictDecision::query()
                ->where('conflict_uuid', $conflict->conflict_uuid)
                ->where('decision', 'keep_record')
                ->whereNotNull('resolved_by')
                ->count(),
        );

        // The requesting installation replays the decision from the receipt.
        $replayed = $this->submitDeletion();
        $this->assertSame('rejected', $replayed['status']);
        $this->assertSame('deletion_rejected', $replayed['code']);

        // The decision also reaches the request-outcomes channel, so the
        // offline operation settles (rejected) without a retry.
        $item = SyncRequestItem::query()
            ->where('operation_uuid', $this->operationUuid)
            ->firstOrFail();
        $this->assertSame('rejected', $item->status);

        $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->getJson('/api/sync/v1/request-outcomes?since=0')
            ->assertOk()
            ->assertJsonPath('data.0.operation_uuid', $this->operationUuid)
            ->assertJsonPath('data.0.status', 'rejected');
    }

    public function test_approve_deletion_archives_and_propagates_the_tombstone(): void
    {
        $this->submitDeletion();

        $conflict = SyncConflict::query()->where('operation', 'deleted')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('sync-conflicts.resolve', $conflict), ['action' => 'approve_deletion'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Never a hard delete: the record is archived with an audited reason.
        $this->beneficiary->refresh();
        $this->assertNotNull($this->beneficiary->archived_at);
        $this->assertSame('Deletion approved by central administrator.', $this->beneficiary->archive_reason);

        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'beneficiary.deletion_approved']);

        // The approval is in the immutable decision history.
        $this->assertSame(
            1,
            SyncConflictDecision::query()
                ->where('conflict_uuid', $conflict->conflict_uuid)
                ->where('decision', 'approve_deletion')
                ->count(),
        );

        // The archive tombstone propagates through the change log as an
        // update carrying archived_at (distinct from operation receipts).
        $this->assertSame(
            1,
            DB::table('sync_changes')
                ->where('entity_uuid', $this->beneficiary->global_uuid)
                ->where('operation', 'updated')
                ->count(),
        );

        // The requesting installation replays the approval from the receipt.
        $replayed = $this->submitDeletion();
        $this->assertSame('applied', $replayed['status']);
        $this->assertSame('deletion_approved', $replayed['code']);

        // The approval also reaches the request-outcomes channel: the offline
        // operation settles as synced.
        $item = SyncRequestItem::query()
            ->where('operation_uuid', $this->operationUuid)
            ->firstOrFail();
        $this->assertSame('accepted', $item->status);

        $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->getJson('/api/sync/v1/request-outcomes?since=0')
            ->assertOk()
            ->assertJsonPath('data.0.operation_uuid', $this->operationUuid)
            ->assertJsonPath('data.0.status', 'accepted');
    }

    public function test_the_gate_can_be_relaxed_per_installation(): void
    {
        $this->server->forceFill([
            'metadata' => array_merge($this->server->metadata ?? [], ['deletion_approval_required' => false]),
        ])->save();

        $this->submitDeletion();

        // Legacy behavior: applied immediately as a hard delete.
        $this->assertDatabaseMissing('beneficiaries', ['id' => $this->beneficiary->id]);
        $this->assertSame(0, SyncConflict::query()->where('operation', 'deleted')->count());
    }

    public function test_a_deletion_of_an_unknown_record_stays_an_idempotent_no_op(): void
    {
        $response = $this->withHeader((string) config('sync.server_token_header'), $this->serverToken)
            ->postJson('/api/sync/v1/operations', [
                'operations' => [[
                    'operation_uuid' => (string) Str::uuid(),
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => (string) Str::uuid(),
                    'operation' => 'deleted',
                    'payload' => [],
                ]],
            ]);

        $response->assertOk();
        $this->assertSame('applied', $response->json('data.0.status'));
        $this->assertSame(0, SyncConflict::query()->where('operation', 'deleted')->count());
    }
}
