<?php

namespace Tests\Feature;

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
 * The unified review inbox lists every pending decision from both decision
 * surfaces — open conflicts and held upload items — with held items that are
 * already represented by an open conflict shown only once.
 */
class ReviewInboxTest extends TestCase
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

    private function heldItem(array $overrides = []): SyncRequestItem
    {
        $request = SyncRequest::create([
            'request_uuid' => (string) Str::uuid(),
            'offline_server_id' => $this->server->id,
            'status' => SyncRequest::STATUS_AWAITING_REVIEW,
            'total_operations' => 1,
            'submitted_at' => now(),
            'last_activity_at' => now(),
        ]);

        return SyncRequestItem::create(array_merge([
            'sync_request_id' => $request->id,
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => 'beneficiary',
            'entity_uuid' => (string) Str::uuid(),
            'operation' => 'created',
            'payload' => [
                'first_name' => 'HELD',
                'last_name' => 'CANDIDATE',
                'beneficiary_code' => 'DOLE XII-SCFO-BARMM-102026-00999',
                'beneficiary_batch_uuid' => null,
            ],
            'status' => SyncRequestItem::STATUS_AWAITING_REVIEW,
            'duplicate_rule' => 'source_id_no',
        ], $overrides));
    }

    private function openConflict(string $operation, array $overrides = []): SyncConflict
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

    public function test_the_inbox_lists_deletion_approvals_mappings_and_duplicates(): void
    {
        $this->openConflict('deleted', ['local_payload' => ['beneficiary_code' => 'DEL-001']]);

        $batch = BeneficiaryBatch::factory()->create(['name' => 'Unknown Batch']);
        $this->openConflict('mapping', [
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $batch->global_uuid,
        ]);

        $this->heldItem();

        $response = $this->actingAs($this->admin)->get(route('review-inbox.index'));

        $response->assertOk()
            ->assertSee('Deletion approval')
            ->assertSee('Batch mapping')
            ->assertSee('Duplicate candidate')
            ->assertSee('DEL-001')
            ->assertSee('Field Laptop')
            ->assertSee('Pending Decisions')
            ->assertSee('Decision Queue')
            ->assertSee('data-filter="Deletion approval"', false)
            ->assertSee('data-filter="other"', false);
    }

    public function test_an_item_represented_by_an_open_conflict_is_listed_once(): void
    {
        // A revision conflict creates both a conflict row and a held item for
        // the same operation: the inbox must show it once, as the conflict.
        $conflict = $this->openConflict('updated');

        $this->heldItem([
            'conflict_uuid' => $conflict->conflict_uuid,
            'operation_uuid' => $conflict->operation_uuid ?? (string) Str::uuid(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('review-inbox.index'));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('entries')->count());
        $this->assertSame('Field conflict', $response->viewData('entries')->first()['label']);
    }

    public function test_resolved_decisions_leave_the_inbox(): void
    {
        $conflict = $this->openConflict('deleted');
        $conflict->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution' => ['action' => 'approve_deletion'],
        ])->save();

        $item = $this->heldItem();
        $item->forceFill(['status' => SyncRequestItem::STATUS_ACCEPTED])->save();

        $this->actingAs($this->admin)->get(route('review-inbox.index'))
            ->assertOk()
            ->assertSee('all caught up!')
            ->assertSee('There are no records waiting for an administrator decision.')
            ->assertSee('All clear')
            ->assertDontSee('data-kind=', false);
    }

    public function test_category_counts_come_from_the_review_records(): void
    {
        $this->openConflict('deleted');
        $this->openConflict('deleted');
        $this->openConflict('mapping', [
            'entity_type' => 'beneficiary_batch',
        ]);
        $this->heldItem();

        $response = $this->actingAs($this->admin)->get(route('review-inbox.index'));

        $response->assertOk();

        $counts = $response->viewData('counts');

        // Counts are the single source of truth rendered by the page.
        $this->assertSame(4, $counts['total']);
        $this->assertSame(2, $counts['deletions']);
        $this->assertSame(1, $counts['mappings']);
        $this->assertSame(1, $counts['duplicates']);
        $this->assertSame(0, $counts['conflicts']);
        $this->assertSame(0, $counts['other']);
    }

    public function test_the_inbox_renders_the_shared_sync_console_layout(): void
    {
        $this->openConflict('deleted', ['local_payload' => ['beneficiary_code' => 'DEL-001']]);

        $this->actingAs($this->admin)->get(route('review-inbox.index'))
            ->assertOk()
            ->assertSee('Review inbox')
            ->assertSee('Pending Decisions')
            ->assertSee('Decision Queue')
            ->assertSee('id="ri-table"', false)
            ->assertSee('id="ri-search"', false)
            ->assertSee('data-filter="other"', false);
    }

    public function test_the_inbox_paginates_and_filters_on_the_server(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->heldItem([
                'payload' => [
                    'first_name' => 'HELD'.$i,
                    'last_name' => 'CANDIDATE',
                    'beneficiary_code' => 'DOLE-'.$i,
                    'beneficiary_batch_uuid' => null,
                ],
                'duplicate_rule' => 'source_id_no',
            ]);
        }

        $first = $this->actingAs($this->admin)->get(route('review-inbox.index'))->assertOk();
        $this->assertSame(30, $first->viewData('counts')['total']);
        $this->assertSame(25, $first->viewData('entries')->count());
        $this->assertSame(30, $first->viewData('entries')->total());

        $second = $this->actingAs($this->admin)->get(route('review-inbox.index', ['page' => 2]))->assertOk();
        $this->assertSame(5, $second->viewData('entries')->count());

        $this->actingAs($this->admin)
            ->get(route('review-inbox.index', ['q' => 'HELD7']))
            ->assertOk()
            ->assertSee('HELD7')
            ->assertDontSee('HELD8</div>', false);

        $this->actingAs($this->admin)
            ->get(route('review-inbox.index', ['kind' => 'Deletion approval']))
            ->assertOk()
            ->assertSee('No records match your filters');
    }

    public function test_non_admins_cannot_open_the_inbox(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($staff)->get(route('review-inbox.index'))->assertForbidden();
    }
}
