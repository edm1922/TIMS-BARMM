<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncOperation;
use App\Services\DashboardStats;
use App\Services\Sync\GhostBatchCleaner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ghost beneficiaries: active records still filed in a release batch that was
 * deleted locally. They are the offline-only leftovers that make local totals
 * disagree with central; the cleaner archives them without hard deletes and
 * without queueing phantom uploads.
 */
class GhostBatchCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function deletedBatchWithMembers(int $members = 3): BeneficiaryBatch
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'DELETED DATU PIANG']);
        Beneficiary::factory()->count($members)->create(['beneficiary_batch_id' => $batch->id]);
        $batch->delete();

        return $batch;
    }

    public function test_preview_lists_active_members_of_deleted_batches_only(): void
    {
        $deleted = $this->deletedBatchWithMembers(3);

        $live = BeneficiaryBatch::factory()->create(['name' => 'LIVE BATCH']);
        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $live->id]);

        $preview = app(GhostBatchCleaner::class)->preview();

        $this->assertSame(3, $preview['total']);
        $this->assertCount(1, $preview['groups']);
        $this->assertSame($deleted->global_uuid, $preview['groups'][0]['uuid']);
        $this->assertSame('DELETED DATU PIANG', $preview['groups'][0]['name']);
    }

    public function test_cleaning_archives_ghosts_without_deleting_or_queueing_uploads(): void
    {
        $this->deletedBatchWithMembers(3);

        $live = BeneficiaryBatch::factory()->create(['name' => 'LIVE BATCH']);
        $liveMember = Beneficiary::factory()->create(['beneficiary_batch_id' => $live->id]);

        $opsBefore = SyncOperation::query()->count();

        $result = app(GhostBatchCleaner::class)->clean();

        $this->assertSame(3, $result['archived']);
        $this->assertSame(3, $result['total_before']);

        // Records are archived, not removed.
        $this->assertSame(3, Beneficiary::query()->whereNotNull('archived_at')->count());
        $this->assertSame(1, Beneficiary::query()->whereNull('archived_at')->count()); // live member only
        $this->assertNull($liveMember->fresh()->archived_at);

        // The cleanup itself does not queue any sync upload.
        $this->assertSame($opsBefore, SyncOperation::query()->count());

        $this->assertDatabaseHas('sync_events', ['type' => 'ghost_batch_cleaned']);

        // The dashboard active total now matches the live records only.
        $this->assertSame(1, (int) app(DashboardStats::class)->summary()['counts']['total']);
    }

    public function test_a_second_clean_is_a_noop(): void
    {
        $this->deletedBatchWithMembers(2);

        app(GhostBatchCleaner::class)->clean();

        $this->assertSame(0, app(GhostBatchCleaner::class)->preview()['total']);
        $this->assertSame(0, app(GhostBatchCleaner::class)->clean()['archived']);
    }

    public function test_the_command_defaults_to_a_dry_run_and_applies_with_force(): void
    {
        $this->deletedBatchWithMembers(2);

        $this->artisan('sync:clean-ghost-batches')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertSame(2, Beneficiary::query()->whereNull('archived_at')->count());

        $this->artisan('sync:clean-ghost-batches --apply --force')
            ->expectsOutputToContain('ghost records archived')
            ->assertSuccessful();

        $this->assertSame(0, Beneficiary::query()->whereNull('archived_at')->count());
    }

    public function test_the_cleanup_route_is_registered_for_sync_setup(): void
    {
        $this->assertSame(url('/sync/setup/clean-ghosts'), route('sync.local.clean-ghosts'));
    }
}
