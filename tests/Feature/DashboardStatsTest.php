<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_assignment_split_adds_up_to_the_active_total(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();

        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $batch->id]);
        Beneficiary::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['total'] === 5
                && $counts['assigned_to_batch'] === 2
                && $counts['unassigned'] === 3;
        });
        $response->assertViewHas('assignment', function (array $assignment): bool {
            return $assignment['total'] === 5
                && $assignment['assigned'] === 2
                && $assignment['unassigned'] === 3
                && $assignment['assigned_percent'] === 40.0
                && $assignment['unassigned_percent'] === 60.0;
        });
    }

    public function test_a_beneficiary_in_a_batch_is_never_counted_twice(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();

        $beneficiary = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['total'] === 1
                && $counts['assigned_to_batch'] === 1
                && $counts['unassigned'] === 0
                && $counts['assigned_to_batch'] + $counts['unassigned'] === $counts['total'];
        });
        $this->assertSame(1, Beneficiary::query()->active()->count());
    }

    public function test_dashboard_renders_with_an_empty_register(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['total'] === 0
                && $counts['assigned_to_batch'] === 0
                && $counts['unassigned'] === 0;
        });
        $response->assertViewHas('assignment', function (array $assignment): bool {
            return $assignment['assigned_percent'] === null
                && $assignment['unassigned_percent'] === null;
        });
        $response->assertSee('—', false);
        $response->assertDontSee('NaN', false);
        $response->assertDontSee('Infinity', false);
    }

    public function test_data_quality_counts_follow_the_age_and_duplicate_rules(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create([
            'first_name' => 'MINOR', 'last_name' => 'QUALITY',
            'birthdate' => now()->subYears(16)->toDateString(),
        ]);
        Beneficiary::factory()->create([
            'first_name' => 'NOBIRTH', 'last_name' => 'QUALITY',
            'birthdate' => null,
        ]);
        Beneficiary::factory()->create([
            'first_name' => 'DUPLICATE', 'last_name' => 'QUALITY',
            'birthdate' => '1990-01-01',
            'duplicate_status' => 'candidate',
        ]);
        Beneficiary::factory()->create([
            'first_name' => 'CONFIRMED', 'last_name' => 'QUALITY',
            'birthdate' => '1991-01-01',
            'duplicate_status' => 'confirmed_duplicate',
        ]);
        Beneficiary::factory()->create([
            'first_name' => 'CLEARED', 'last_name' => 'QUALITY',
            'birthdate' => '1992-01-01',
            'duplicate_status' => 'cleared',
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['total'] === 5
                && $counts['underage'] === 1
                && $counts['unknown_age'] === 1
                && $counts['duplicate_candidates'] === 2;
        });
    }

    public function test_archived_beneficiaries_stay_out_of_the_active_totals(): void
    {
        $admin = $this->admin();

        $archived = Beneficiary::factory()->create();
        $archived->archive();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['total'] === 0
                && $counts['assigned_to_batch'] === 0
                && $counts['unassigned'] === 0
                && $counts['archived'] === 1;
        });
    }

    public function test_donut_segments_match_the_active_batch_counts(): void
    {
        $admin = $this->admin();
        $batchA = BeneficiaryBatch::factory()->create(['name' => 'Batch Alpha']);
        $batchB = BeneficiaryBatch::factory()->create(['name' => 'Batch Beta']);

        Beneficiary::factory()->create(['beneficiary_batch_id' => $batchA->id]);
        Beneficiary::factory()->count(3)->create(['beneficiary_batch_id' => $batchB->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('batchDistribution', function (array $dist): bool {
            return $dist['total'] === 4
                && count($dist['batches']) === 2;
        });
        $response->assertSee('donut-segment-batch', false);
        $response->assertSee('Batch Alpha');
        $response->assertSee('Batch Beta');
        $response->assertSee('stroke-dasharray="226.19 301.59"', false);
        $response->assertSee('stroke-dasharray="75.4 301.59"', false);
    }

    public function test_batch_overview_lists_open_batches_only(): void
    {
        $admin = $this->admin();

        BeneficiaryBatch::factory()->create(['name' => 'Payroll Open']);
        BeneficiaryBatch::factory()->closed()->create(['name' => 'Payroll Closed']);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertViewHas('counts', function (array $counts): bool {
            return $counts['active_batches'] === 1
                && $counts['total_batches'] === 2;
        });
        $response->assertSee('Payroll Open');
        $response->assertDontSee('Payroll Closed');
    }

    public function test_dashboard_links_point_at_supported_list_filters(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertSee(route('beneficiaries.index'), false);
        $response->assertSee(route('beneficiaries.index', ['batch' => 'unassigned']), false);
        $response->assertSee(route('beneficiaries.index', ['age_status' => 'underage']), false);
        $response->assertSee(route('beneficiaries.index', ['age_status' => 'unknown']), false);
        $response->assertSee(route('beneficiaries.index', ['status' => 'archived']), false);
    }

    public function test_donut_handles_active_batches_with_no_beneficiaries(): void
    {
        $admin = $this->admin();
        BeneficiaryBatch::factory()->create(['name' => 'Empty Batch']);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Empty Batch');
        $response->assertSee('No beneficiaries assigned to active batches yet.');
        $response->assertDontSee('donut-segment-batch', false);
    }

    public function test_donut_legend_links_to_batch_filter_on_beneficiaries_index(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Batch Zeta']);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('beneficiaries.index', ['batch' => $batch->id]), false);
    }
}
