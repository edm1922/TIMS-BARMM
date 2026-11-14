<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeOrphanBatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_batches_are_only_deleted_with_force(): void
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'TEST3']);
        $batch->delete();

        $this->artisan('batches:purge-orphans')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertSoftDeleted('beneficiary_batches', ['id' => $batch->id]);

        $this->artisan('batches:purge-orphans', ['--force' => true])
            ->expectsOutputToContain('Permanently removed 1 orphan batch')
            ->assertSuccessful();

        $this->assertDatabaseMissing('beneficiary_batches', ['id' => $batch->id]);
    }

    public function test_active_batches_with_beneficiaries_are_never_removed(): void
    {
        $batch = BeneficiaryBatch::factory()->create(['name' => 'PAHAMUDDIN, SGA, BARMM']);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->artisan('batches:purge-orphans', ['--force' => true])
            ->expectsOutputToContain('No matching zero-member orphan batches found.')
            ->assertSuccessful();

        $this->assertDatabaseHas('beneficiary_batches', ['id' => $batch->id]);
    }
}
