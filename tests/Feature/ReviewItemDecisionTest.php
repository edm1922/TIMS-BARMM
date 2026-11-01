<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\SyncConflict;
use App\Models\SyncRequest;
use App\Models\SyncRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Option A: one decision page for every review item type. These tests exercise
 * the unified queue/detail page for deletions, mappings, field conflicts,
 * duplicates and invalid holds, plus the redirects for stale keys.
 */
class ReviewItemDecisionTest extends TestCase
{
    use RefreshDatabase;

    private OfflineServer $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'online']);

        $this->server = OfflineServer::factory()->create(['name' => 'Field Laptop']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function request(): SyncRequest
    {
        return SyncRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'status' => SyncRequest::STATUS_AWAITING_REVIEW,
            'total_operations' => 1,
            'submitted_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function heldItem(array $overrides = []): SyncRequestItem
    {
        return SyncRequestItem::create(array_merge([
            'sync_request_id' => $this->request()->id,
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'payload' => [
                'first_name' => 'HELD',
                'last_name' => 'RECORD',
                'beneficiary_code' => 'DOLE-X-001',
                'location_code' => '1900702001',
            ],
            'status' => SyncRequestItem::STATUS_AWAITING_REVIEW,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function conflict(string $operation, array $overrides = []): SyncConflict
    {
        return SyncConflict::create(array_merge([
            'conflict_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => $operation,
            'origin_server_uuid' => $this->server->server_uuid,
            'local_payload' => [],
            'remote_payload' => [],
            'diff' => [],
            'status' => 'open',
        ], $overrides));
    }

    public function test_the_inbox_rows_open_the_unified_decision_page(): void
    {
        $conflict = $this->conflict('deleted');
        $item = $this->heldItem(['duplicate_rule' => 'source_id_no']);

        $this->actingAs($this->admin)->get(route('review-inbox.index'))
            ->assertOk()
            ->assertSee(route('review-inbox.show', 'conflict-'.$conflict->conflict_uuid), false)
            ->assertSee(route('review-inbox.show', 'item-'.$item->id), false);
    }

    public function test_a_deletion_approval_is_decided_on_the_unified_page(): void
    {
        $beneficiary = Beneficiary::factory()->create(['beneficiary_code' => 'DEL-777']);

        $item = $this->heldItem();

        $conflict = $this->conflict('deleted', [
            'entity_uuid' => $beneficiary->global_uuid,
            'operation_uuid' => $item->operation_uuid,
            'local_payload' => ['beneficiary_code' => 'DEL-777'],
            'remote_payload' => ['requested_by_server_name' => 'Field Laptop', 'delete_reason' => 'Duplicate entry'],
        ]);
        $item->forceFill(['conflict_uuid' => $conflict->conflict_uuid])->save();

        $this->actingAs($this->admin)
            ->get(route('review-inbox.show', 'conflict-'.$conflict->conflict_uuid))
            ->assertOk()
            ->assertSee('Deletion approval')
            ->assertSee('Keep the beneficiary')
            ->assertSee('Approve the deletion');

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'conflict-'.$conflict->conflict_uuid), ['action' => 'keep_record'])
            ->assertRedirect(route('review-inbox.index'))
            ->assertSessionHas('success');

        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame(0, SyncRequestItem::query()->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)->count());
        $this->actingAs($this->admin)->get(route('review-inbox.index'))->assertOk()->assertSee('All clear');
    }

    public function test_a_batch_mapping_is_discarded_on_the_unified_page(): void
    {
        $conflict = $this->conflict('mapping', [
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => (string) Str::uuid(),
            'remote_payload' => ['requested_by_server_name' => 'Field Laptop'],
        ]);
        $this->heldItem([
            'conflict_uuid' => $conflict->conflict_uuid,
            'payload' => ['first_name' => 'MAPPED', 'beneficiary_batch_uuid' => $conflict->entity_uuid],
        ]);

        $this->actingAs($this->admin)
            ->get(route('review-inbox.show', 'conflict-'.$conflict->conflict_uuid))
            ->assertOk()
            ->assertSee('Batch mapping')
            ->assertSee('Discard these records');

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'conflict-'.$conflict->conflict_uuid), ['action' => 'discard_batch'])
            ->assertRedirect(route('review-inbox.index'));

        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame(SyncRequestItem::STATUS_REJECTED, SyncRequestItem::query()->first()->status);
    }

    public function test_a_field_conflict_is_resolved_on_the_unified_page(): void
    {
        $beneficiary = Beneficiary::factory()->create(['notes' => 'Central note.']);

        $item = $this->heldItem();

        $conflict = $this->conflict('updated', [
            'entity_uuid' => $beneficiary->global_uuid,
            'operation_uuid' => $item->operation_uuid,
            'local_payload' => ['notes' => 'Offline note.'],
            'remote_payload' => ['notes' => 'Central note.'],
            'diff' => ['notes'],
        ]);
        $item->forceFill(['conflict_uuid' => $conflict->conflict_uuid])->save();

        $this->actingAs($this->admin)
            ->get(route('review-inbox.show', 'conflict-'.$conflict->conflict_uuid))
            ->assertOk()
            ->assertSee('Field conflict')
            ->assertSee('Apply the submitted values')
            ->assertSee('Keep the central record');

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'conflict-'.$conflict->conflict_uuid), ['action' => 'accept_local'])
            ->assertRedirect(route('review-inbox.index'));

        $this->assertSame('Offline note.', $beneficiary->fresh()->notes);
        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, SyncRequestItem::query()->first()->status);
    }

    public function test_a_duplicate_candidate_is_confirmed_on_the_unified_page(): void
    {
        $matched = Beneficiary::factory()->create(['beneficiary_code' => 'DOLE-MATCH-1']);

        $item = $this->heldItem([
            'duplicate_rule' => 'source_id_no',
            'duplicate_matches' => ['DOLE-MATCH-1'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('review-inbox.show', 'item-'.$item->id))
            ->assertOk()
            ->assertSee('Duplicate candidate')
            ->assertSee('DOLE-MATCH-1')
            ->assertSee('Same person');

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'item-'.$item->id), ['action' => 'link'])
            ->assertRedirect(route('review-inbox.index'));

        $this->assertSame(SyncRequestItem::STATUS_ACCEPTED, $item->fresh()->status);
        $this->assertSame(1, Beneficiary::query()->count());
    }

    public function test_an_invalid_hold_requires_a_reason_to_reject(): void
    {
        $item = $this->heldItem();

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'item-'.$item->id), ['action' => 'reject'])
            ->assertSessionHas('error');

        $this->assertSame(SyncRequestItem::STATUS_AWAITING_REVIEW, $item->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'item-'.$item->id), ['action' => 'reject', 'reason' => 'Bad data'])
            ->assertRedirect(route('review-inbox.index'));

        $this->assertSame(SyncRequestItem::STATUS_REJECTED, $item->fresh()->status);
    }

    public function test_stale_or_unknown_review_keys_return_to_the_inbox(): void
    {
        $this->actingAs($this->admin)
            ->get(route('review-inbox.show', 'conflict-'.Str::uuid()))
            ->assertRedirect(route('review-inbox.index'))
            ->assertSessionHas('error');

        $this->actingAs($this->admin)
            ->post(route('review-inbox.decide', 'item-999999'), ['action' => 'accept'])
            ->assertRedirect(route('review-inbox.index'))
            ->assertSessionHas('error');
    }

    public function test_non_admins_cannot_open_the_decision_page(): void
    {
        $item = $this->heldItem();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_STAFF]))
            ->get(route('review-inbox.show', 'item-'.$item->id))
            ->assertForbidden();
    }

    public function test_batch_options_are_the_existing_central_batches(): void
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Existing Batch']);
        $conflict = $this->conflict('mapping', ['entity_type' => 'beneficiary_batch']);

        $this->actingAs($this->admin)
            ->get(route('review-inbox.show', 'conflict-'.$conflict->conflict_uuid))
            ->assertOk()
            ->assertSee($batch->name);
    }
}
