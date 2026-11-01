<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncOperation;
use App\Services\Sync\SyncOperationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SyncBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.instance_mode' => 'offline']);
        config(['sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111']);
    }

    /**
     * Seed members as if they predate the sync baseline: the batch and its
     * rows exist but no operation was ever recorded (the recorder is
     * suppressed to simulate the pre-enrollment state).
     *
     * @return array{batch: BeneficiaryBatch, members: Collection<int, Beneficiary>}
     */
    private function seedBaselineMembers(int $count, string $name = 'South Upi'): array
    {
        [$batch, $members] = SyncOperationRecorder::withoutRecording(
            function () use ($count, $name): array {
                $batch = BeneficiaryBatch::factory()->create(['name' => $name]);

                $members = Beneficiary::factory()->count($count)->create([
                    'beneficiary_batch_id' => $batch->id,
                    'location_code' => '1900702001',
                ]);

                return [$batch, $members];
            },
        );

        // The archive/delete tombstones of unrelated state must not linger.
        SyncOperation::query()->delete();

        return ['batch' => $batch, 'members' => $members];
    }

    public function test_the_command_refuses_to_run_on_a_central_installation(): void
    {
        config(['sync.instance_mode' => 'online']);

        $this->artisan('sync:queue-batch-backfill', ['--all' => true])
            ->expectsOutputToContain('OFFLINE installation only')
            ->assertExitCode(1);
    }

    public function test_it_queues_the_batch_and_its_unqueued_members_batch_first(): void
    {
        ['batch' => $batch, 'members' => $members] = $this->seedBaselineMembers(3);

        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id])
            ->expectsOutputToContain('Done')
            ->assertExitCode(0);

        $batchOps = SyncOperation::query()
            ->where('entity_type', 'beneficiary_batch')
            ->where('entity_uuid', $batch->global_uuid)
            ->where('operation', 'created')
            ->get();

        $this->assertSame(1, $batchOps->count(), 'the batch itself is queued first');

        $memberOps = SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('operation', 'created')
            ->whereIn('entity_uuid', $members->pluck('global_uuid'))
            ->get();

        $this->assertSame(3, $memberOps->count());
        $this->assertTrue($memberOps->every(fn ($op) => $op->status === SyncOperation::STATUS_PENDING));

        // Batch-first ordering: the batch operation uploads before the members.
        $this->assertTrue($batchOps->first()->id < $memberOps->min('id'));

        // The member payload carries the batch UUID so central links it.
        $this->assertSame(
            $batch->global_uuid,
            $memberOps->first()->payload['beneficiary_batch_uuid'],
        );
    }

    public function test_it_is_idempotent(): void
    {
        ['batch' => $batch] = $this->seedBaselineMembers(2);

        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id])->assertExitCode(0);
        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id])
            ->assertExitCode(0) // queued count asserted via the database below
            ->assertExitCode(0);

        $this->assertSame(
            3,
            SyncOperation::query()->where('operation', 'created')->count(),
            'one batch op + two member ops, never duplicated',
        );
    }

    public function test_members_with_pending_or_synced_creation_ops_are_not_requeued(): void
    {
        ['batch' => $batch, 'members' => $members] = $this->seedBaselineMembers(2);

        // One member already has a pending creation, the other a synced one.
        SyncOperation::create([
            'operation_uuid' => '22222222-2222-4222-8222-222222222221',
            'entity_type' => 'beneficiary',
            'entity_uuid' => $members[0]->global_uuid,
            'operation' => 'created',
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'local_id' => $members[0]->id,
            'base_revision' => 0,
            'payload' => [],
            'status' => SyncOperation::STATUS_PENDING,
            'available_at' => now(),
        ]);
        SyncOperation::create([
            'operation_uuid' => '22222222-2222-4222-8222-222222222222',
            'entity_type' => 'beneficiary',
            'entity_uuid' => $members[1]->global_uuid,
            'operation' => 'created',
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'local_id' => $members[1]->id,
            'base_revision' => 0,
            'payload' => [],
            'status' => SyncOperation::STATUS_SYNCED,
            'available_at' => now(),
        ]);

        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id])
            ->assertExitCode(0) // skip counts asserted via the database below
            ->assertExitCode(0);

        $this->assertSame(
            2,
            SyncOperation::query()->where('entity_type', 'beneficiary')->where('operation', 'created')->count(),
        );
    }

    public function test_include_synced_requeues_members_central_may_already_hold(): void
    {
        ['batch' => $batch, 'members' => $members] = $this->seedBaselineMembers(1);

        SyncOperation::create([
            'operation_uuid' => '22222222-2222-4222-8222-222222222223',
            'entity_type' => 'beneficiary',
            'entity_uuid' => $members[0]->global_uuid,
            'operation' => 'created',
            'origin_server_uuid' => '11111111-1111-4111-8111-111111111111',
            'local_id' => $members[0]->id,
            'base_revision' => 0,
            'payload' => [],
            'status' => SyncOperation::STATUS_SYNCED,
            'available_at' => now(),
        ]);

        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id, '--include-synced' => true])
            ->assertExitCode(0);

        // A second, pending creation operation was queued for the member.
        $requeued = SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $members[0]->global_uuid)
            ->where('operation', 'created')
            ->where('status', SyncOperation::STATUS_PENDING)
            ->count();

        $this->assertSame(1, $requeued);
    }

    public function test_archived_members_are_skipped(): void
    {
        ['batch' => $batch] = $this->seedBaselineMembers(1);

        $archived = Beneficiary::factory()->create([
            'beneficiary_batch_id' => $batch->id,
            'location_code' => '1900702001',
        ]);
        $archived->archive();
        SyncOperation::query()->where('entity_uuid', $archived->global_uuid)->delete();

        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id])
            ->assertExitCode(0);

        // Only the batch operation and the active member's operation exist;
        // the archived member got none.
        $this->assertSame(2, SyncOperation::query()->count());
        $this->assertSame(
            1,
            SyncOperation::query()->where('entity_type', 'beneficiary')->count(),
        );
        $this->assertSame(
            0,
            SyncOperation::query()->where('entity_uuid', $archived->global_uuid)->count(),
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        ['batch' => $batch] = $this->seedBaselineMembers(2);

        $this->artisan('sync:queue-batch-backfill', ['batch' => $batch->id, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertSame(0, SyncOperation::query()->count());
    }

    public function test_it_resolves_a_batch_by_name_and_covers_all_batches_with_all(): void
    {
        ['batch' => $first, 'members' => $firstMembers] = $this->seedBaselineMembers(1, 'South Upi');
        [$second] = SyncOperationRecorder::withoutRecording(
            function (): array {
                $batch = BeneficiaryBatch::factory()->create(['name' => 'Datu Angan']);
                $member = Beneficiary::factory()->create([
                    'beneficiary_batch_id' => $batch->id,
                    'location_code' => '1900702001',
                ]);

                return [$batch, $member];
            },
        );
        SyncOperation::query()->delete(); // clean the auto-recorded log

        $this->artisan('sync:queue-batch-backfill', ['batch' => 'South Upi'])->assertExitCode(0);
        $this->assertSame(2, SyncOperation::query()->count()); // 1 batch op + 1 member op

        $this->artisan('sync:queue-batch-backfill', ['--all' => true])->assertExitCode(0);
        // + the Datu Angan batch op and its one member op; South Upi is
        // already queued and is never duplicated.
        $this->assertSame(4, SyncOperation::query()->count());
    }
}
