<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use App\Services\DashboardStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch Manage page: the administrator can change the batch registration /
 * profiling date, which is applied to the beneficiaries filed in the batch.
 */
class BatchProfileDateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_setting_the_batch_date_updates_the_members_profiling_dates(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Dated Batch', 'capacity' => 10]);

        $first = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id, 'profiled_on' => null]);
        $second = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id, 'profiled_on' => '2024-01-01']);
        $outsider = Beneficiary::factory()->create(['profiled_on' => '2020-01-01']);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Dated Batch',
            'status' => 'open',
            'profiled_on' => '2025-06-15',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('2025-06-15', $batch->refresh()->profiled_on?->toDateString());
        $this->assertSame('2025-06-15', $first->refresh()->profiled_on?->toDateString());
        $this->assertSame('2025-06-15', $second->refresh()->profiled_on?->toDateString());

        // Beneficiaries outside the batch are untouched.
        $this->assertSame('2020-01-01', $outsider->refresh()->profiled_on?->toDateString());

        $session = session('success');
        $this->assertStringContainsString('2 beneficiary dates were updated', (string) $session);
    }

    public function test_an_invalid_batch_date_is_rejected(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Dated Batch', 'capacity' => 10]);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Dated Batch',
            'status' => 'open',
            'profiled_on' => 'not-a-date',
        ])->assertSessionHasErrors('profiled_on');

        $this->assertNull($batch->refresh()->profiled_on);
    }

    public function test_leaving_the_date_blank_keeps_member_dates(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Dated Batch', 'capacity' => 10]);
        $member = Beneficiary::factory()->create([
            'beneficiary_batch_id' => $batch->id,
            'profiled_on' => '2023-03-03',
        ]);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Dated Batch',
            'status' => 'open',
            'profiled_on' => '',
        ])->assertRedirect();

        $this->assertSame('2023-03-03', $member->refresh()->profiled_on?->toDateString());
    }

    public function test_the_manage_page_exposes_the_registration_date_field(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create([
            'name' => 'Dated Batch',
            'capacity' => 10,
            'profiled_on' => '2025-06-15',
        ]);

        $this->actingAs($admin)->get(route('beneficiary-batches.show', $batch))
            ->assertOk()
            ->assertSee('Registration date')
            ->assertSee('batch-edit-profiled-on', false)
            ->assertSee('value="2025-06-15"', false);
    }

    public function test_the_dashboard_registration_trend_uses_the_updated_date(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Trend Batch', 'capacity' => 10]);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id, 'profiled_on' => null]);

        $this->actingAs($admin)->put(route('beneficiary-batches.update', $batch), [
            'name' => 'Trend Batch',
            'status' => 'open',
            'profiled_on' => '2024-11-11',
        ])->assertRedirect();

        $stats = app(DashboardStats::class);
        $trend = $stats->summary()['registration_trend'];

        $this->assertSame(1, (int) ($trend['annual'][2024] ?? 0));
        $this->assertSame(1, (int) ($trend['months'][2024][11] ?? 0));
    }
}
