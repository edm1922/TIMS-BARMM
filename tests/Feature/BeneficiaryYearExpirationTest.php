<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BeneficiaryYearExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prior_year_beneficiaries_are_marked_inactive_by_the_command(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 00:10:00'));

        $prior = Beneficiary::factory()->create(['coverage_year' => 2026, 'is_active' => true]);
        $current = Beneficiary::factory()->create(['coverage_year' => 2027, 'is_active' => true]);

        $this->artisan('beneficiaries:deactivate-prior-years', ['--apply' => true])
            ->assertSuccessful();

        $this->assertFalse($prior->refresh()->is_active);
        $this->assertTrue($current->refresh()->is_active);
        $this->assertSame(1, Beneficiary::query()->active()->count());
        $this->assertSame(1, Beneficiary::query()->inactive()->count());
    }

    public function test_a_beneficiary_page_consolidates_annual_applications_into_one_timeline(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $firstBatch = BeneficiaryBatch::factory()->create(['name' => '2026 Batch']);
        $secondBatch = BeneficiaryBatch::factory()->create(['name' => '2027 Batch']);

        $first = Beneficiary::factory()->create([
            'coverage_year' => 2026,
            'beneficiary_batch_id' => $firstBatch->id,
            'source_id_no' => 'TIMELINE-GOV-1',
        ]);
        $second = Beneficiary::factory()->create([
            'coverage_year' => 2027,
            'beneficiary_batch_id' => $secondBatch->id,
            'source_id_no' => 'TIMELINE-GOV-1',
        ]);

        $this->actingAs($admin)->get(route('beneficiaries.show', $second))
            ->assertOk()
            ->assertSee('Application timeline')
            ->assertSee($first->beneficiary_code)
            ->assertSee($second->beneficiary_code)
            ->assertSee('2026 Batch')
            ->assertSee('2027 Batch');
    }

    public function test_the_year_filter_shows_only_the_selected_coverage_year(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02 09:00:00'));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $prior = Beneficiary::factory()->create(['coverage_year' => 2026, 'is_active' => false]);
        $current = Beneficiary::factory()->create(['coverage_year' => 2027, 'is_active' => true]);

        $this->actingAs($admin)->get('/beneficiaries?year=2026&status=inactive')
            ->assertOk()
            ->assertSee('name="year"', false)
            ->assertSee($prior->beneficiary_code)
            ->assertDontSee($current->beneficiary_code);
    }
}
