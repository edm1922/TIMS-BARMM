<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use App\Services\BeneficiaryIdGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-batch beneficiary ID series: the first beneficiary created in the batch
 * gets the administrator's starting code and the trailing number increments
 * automatically for later beneficiaries.
 */
class BatchCodeSeriesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function createInBatch(User $admin, BeneficiaryBatch $batch, array $overrides = []): void
    {
        $location = $this->seededLocation();

        $this->actingAs($admin)->post(
            route('beneficiary-batches.beneficiaries.store', $batch),
            $this->beneficiaryPayload($location, array_merge([
                'first_name' => 'SERIES',
                'last_name' => 'TESTER',
                'source_id_no' => 'SERIES-GOV-'.uniqid(),
                'source_id_number' => 'SERIES-ID-'.uniqid(),
            ], $overrides)),
        )->assertSessionHasNoErrors();
    }

    public function test_a_batch_can_be_created_with_a_starting_id_series(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/batches', [
            'name' => 'Series Batch',
            'beneficiary_code_series' => 'DOLE XII-SCFO-BARMM-102026-01646',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $batch = BeneficiaryBatch::query()->where('name', 'Series Batch')->firstOrFail();
        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01646', $batch->beneficiary_code_series);
    }

    public function test_the_first_beneficiary_uses_the_starting_code_and_then_increments(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Series Batch',
            'beneficiary_code_series' => 'DOLE XII-SCFO-BARMM-102026-01646',
        ]);

        $this->createInBatch($admin, $batch);
        $this->createInBatch($admin, $batch);

        $codes = Beneficiary::query()->orderBy('id')->pluck('beneficiary_code')->all();

        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01646', $codes[0]);
        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01647', $codes[1]);
        $this->assertSame('DOLE XII-SCFO-BARMM-102026-01648', $batch->refresh()->beneficiary_code_series);
    }

    public function test_existing_codes_are_skipped_when_allocating_from_the_series(): void
    {
        $admin = $this->admin();

        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Series Batch',
            'beneficiary_code_series' => 'SERIES-00010',
        ]);

        // Take the first two candidates so the allocator must skip them.
        Beneficiary::factory()->create(['beneficiary_code' => 'SERIES-00010']);
        Beneficiary::factory()->create(['beneficiary_code' => 'SERIES-00011']);

        $this->createInBatch($admin, $batch);

        $created = Beneficiary::query()->where('last_name', 'TESTER')->firstOrFail();
        $this->assertSame('SERIES-00012', $created->beneficiary_code);
    }

    public function test_an_invalid_series_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/batches', [
            'name' => 'Bad Series Batch',
            'beneficiary_code_series' => 'NO-NUMBER-HERE',
        ])->assertSessionHasErrors('beneficiary_code_series');

        $this->assertSame(0, BeneficiaryBatch::query()->where('name', 'Bad Series Batch')->count());
    }

    public function test_the_series_can_be_modified_and_cleared_from_the_batch_settings(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Adjustable Series', 'capacity' => 10]);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Adjustable Series',
            'status' => 'open',
            'beneficiary_code_series' => 'CUSTOM-00090',
        ])->assertRedirect();

        $this->assertSame('CUSTOM-00090', $batch->refresh()->beneficiary_code_series);

        $this->createInBatch($admin, $batch);
        $this->assertSame(
            'CUSTOM-00090',
            Beneficiary::query()->where('last_name', 'TESTER')->firstOrFail()->beneficiary_code,
        );

        // Clearing the series falls back to the automatic generator.
        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Adjustable Series',
            'status' => 'open',
            'beneficiary_code_series' => '',
        ])->assertRedirect();

        $this->assertNull($batch->refresh()->beneficiary_code_series);

        $this->createInBatch($admin, $batch, ['source_id_no' => 'SERIES-GOV-AUTO', 'first_name' => 'AUTO']);

        $auto = Beneficiary::query()->where('first_name', 'AUTO')->firstOrFail();
        $this->assertStringStartsWith('DOLE XII-SCFO-BARMM-', $auto->beneficiary_code);
    }

    public function test_setting_a_new_series_renumbers_the_existing_members_in_registration_order(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Renumber Batch', 'capacity' => 10]);

        $first = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $second = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $third = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Renumber Batch',
            'status' => 'open',
            'beneficiary_code_series' => 'REISSUE-00050',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('REISSUE-00050', $first->refresh()->beneficiary_code);
        $this->assertSame('REISSUE-00051', $second->refresh()->beneficiary_code);
        $this->assertSame('REISSUE-00052', $third->refresh()->beneficiary_code);
        $this->assertSame('REISSUE-00053', $batch->refresh()->beneficiary_code_series);

        $session = session('success');
        $this->assertStringContainsString('3 beneficiary IDs were renumbered', (string) $session);
    }

    public function test_renumbering_skips_codes_used_outside_the_batch(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Skip Batch', 'capacity' => 10]);

        $first = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $second = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        // Someone outside the batch already holds the second candidate.
        Beneficiary::factory()->create(['beneficiary_code' => 'REISSUE-00051']);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Skip Batch',
            'status' => 'open',
            'beneficiary_code_series' => 'REISSUE-00050',
        ])->assertRedirect();

        $this->assertSame('REISSUE-00050', $first->refresh()->beneficiary_code);
        $this->assertSame('REISSUE-00052', $second->refresh()->beneficiary_code);
        $this->assertSame('REISSUE-00053', $batch->refresh()->beneficiary_code_series);
    }

    public function test_saving_the_same_series_does_not_renumber_members(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Same Series',
            'capacity' => 10,
            'beneficiary_code_series' => 'KEEP-00010',
        ]);
        // An explicit code keeps the batch series untouched during creation.
        $member = Beneficiary::factory()->create([
            'beneficiary_batch_id' => $batch->id,
            'beneficiary_code' => 'OLD-00001',
        ]);
        $before = $member->beneficiary_code;

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Same Series',
            'status' => 'open',
            'beneficiary_code_series' => 'KEEP-00010',
        ])->assertRedirect();

        $this->assertSame($before, $member->refresh()->beneficiary_code);
        $this->assertSame('KEEP-00010', $batch->refresh()->beneficiary_code_series);
    }

    public function test_clearing_the_series_does_not_change_existing_codes(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Clear Series',
            'capacity' => 10,
            'beneficiary_code_series' => 'CLEAR-00020',
        ]);
        $member = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $before = $member->beneficiary_code;

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Clear Series',
            'status' => 'open',
            'beneficiary_code_series' => '',
        ])->assertRedirect();

        $this->assertSame($before, $member->refresh()->beneficiary_code);
        $this->assertNull($batch->refresh()->beneficiary_code_series);
    }

    public function test_the_batch_page_shows_the_next_code_and_series_field(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Visible Series',
            'beneficiary_code_series' => 'SHOWN-00400',
        ]);

        $this->actingAs($admin)->get(route('beneficiary-batches.show', $batch))
            ->assertOk()
            ->assertSee('Next beneficiary ID:')
            ->assertSee('SHOWN-00400')
            ->assertSee('Beneficiary ID series');
    }

    public function test_the_create_modal_exposes_the_series_field(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('beneficiary-batches.index'))
            ->assertOk()
            ->assertSee('Beneficiary ID series (optional)')
            ->assertSee('name="beneficiary_code_series"', false);
    }

    public function test_increment_series_preserves_padding(): void
    {
        $generator = app(BeneficiaryIdGenerator::class);

        $this->assertSame('DOLE-00009', $generator->incrementSeries('DOLE-00008'));
        $this->assertSame('DOLE-01000', $generator->incrementSeries('DOLE-00999'));
        $this->assertSame('PREFIX-100000', $generator->incrementSeries('PREFIX-99999'));
        $this->assertTrue(BeneficiaryIdGenerator::isValidSeries('DOLE XII-SCFO-BARMM-102026-01646'));
        $this->assertFalse(BeneficiaryIdGenerator::isValidSeries('NO-NUMBER'));
    }
}
