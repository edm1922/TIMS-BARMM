<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batches carry a beneficiary limit: the create modal accepts it, existing
 * batches are capped at their current membership, and every assignment path
 * respects it.
 */
class BatchCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_a_batch_can_be_created_with_a_beneficiary_limit(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/batches', [
            'name' => 'Limited Batch',
            'capacity' => 100,
        ])->assertRedirect();

        $batch = BeneficiaryBatch::query()->where('name', 'Limited Batch')->firstOrFail();
        $this->assertSame(100, $batch->capacity);
        $this->assertSame(100, $batch->remainingCapacity());

        $this->actingAs($admin)->get(route('beneficiary-batches.index'))
            ->assertOk()
            ->assertSee('0 / 100');
    }

    public function test_a_batch_without_a_limit_stays_unlimited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/batches', ['name' => 'Unlimited Batch'])->assertRedirect();

        $batch = BeneficiaryBatch::query()->where('name', 'Unlimited Batch')->firstOrFail();
        $this->assertNull($batch->capacity);
        $this->assertNull($batch->remainingCapacity());
        $this->assertTrue($batch->hasRoomFor(50));
    }

    public function test_assigning_existing_beneficiaries_stops_at_the_limit(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Capped Batch', 'capacity' => 2]);

        $beneficiaries = Beneficiary::factory()->count(3)->create();

        $this->actingAs($admin)->post(route('beneficiary-batches.members.store', $batch), [
            'ids' => $beneficiaries->pluck('id')->all(),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, $batch->memberCount());
        $this->assertSame(1, Beneficiary::query()->whereNull('beneficiary_batch_id')->count());

        $session = session('success');
        $this->assertStringContainsString('limit', (string) $session);
    }

    public function test_a_full_batch_rejects_further_assignments(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['capacity' => 1]);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $extra = Beneficiary::factory()->create();

        $this->actingAs($admin)->post(route('beneficiary-batches.members.store', $batch), [
            'ids' => [$extra->id],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertNull($extra->refresh()->beneficiary_batch_id);
        $this->assertSame(1, $batch->memberCount());
    }

    public function test_a_full_batch_blocks_manual_registration(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['capacity' => 1]);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $location = $this->seededLocation();

        $this->actingAs($admin)->post(
            route('beneficiary-batches.beneficiaries.store', $batch),
            $this->beneficiaryPayload($location, ['first_name' => 'FULL', 'last_name' => 'BATCH']),
        )->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Beneficiary::query()->where('last_name', 'BATCH')->count());
    }

    public function test_capacity_can_be_raised_or_cleared_but_not_lowered_below_members(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Adjust Batch', 'capacity' => 3]);
        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $batch->id]);

        // Below the current membership is rejected.
        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Adjust Batch',
            'status' => 'open',
            'capacity' => 1,
        ])->assertSessionHasErrors('capacity');

        $this->assertSame(3, $batch->refresh()->capacity);

        // Raising the limit works.
        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Adjust Batch',
            'status' => 'open',
            'capacity' => 10,
        ])->assertRedirect();

        $this->assertSame(10, $batch->refresh()->capacity);

        // Clearing the limit restores unlimited capacity.
        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Adjust Batch',
            'status' => 'open',
            'capacity' => '',
        ])->assertRedirect();

        $this->assertNull($batch->refresh()->capacity);
    }

    public function test_the_batch_page_shows_the_limit_and_blocks_add_when_full(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['capacity' => 1]);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)->get(route('beneficiary-batches.show', $batch))
            ->assertOk()
            ->assertSee('1 / 1')
            ->assertSee('Batch full')
            ->assertSee('Beneficiary limit');
    }
}
