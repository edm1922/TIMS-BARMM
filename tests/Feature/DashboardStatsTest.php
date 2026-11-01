<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
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
                && $counts['total'] === $counts['assigned_to_batch'] + $counts['unassigned'];
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

    public function test_the_dashboard_uses_one_shared_year_selector_for_both_charts(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('id="dashboard-year"', false)
            ->assertSee('All years')
            ->assertDontSee('id="trend-year"', false)
            ->assertDontSee('id="batch-year"', false)
            ->assertSee('data-registration-trend', false)
            ->assertSee('data-batch-data', false);
    }

    public function test_registration_trend_defaults_to_the_current_year_with_twelve_months(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['created_at' => now()->startOfMonth()->addDays(2)]);
        Beneficiary::factory()->create(['created_at' => now()->startOfMonth()->addDays(5)]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();

        $trend = $response->viewData('registrationTrend');

        $this->assertSame(now()->year, $trend['current']);
        $this->assertContains(now()->year, $trend['years']);
        $this->assertSame(12, count($trend['months'][now()->year]));
        $this->assertSame(2, $trend['months'][now()->year][now()->month]);
        $this->assertSame(2, array_sum($trend['months'][now()->year]));

        $response->assertSee('Beneficiary Registration Trend');
        $response->assertSee('Monthly beneficiary registration activity for the selected year.');
        $response->assertSee('id="dashboard-year"', false);
        $response->assertSee('All years');

        foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $month) {
            $response->assertSee($month);
        }
    }

    public function test_registration_trend_groups_counts_by_month_and_year(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['created_at' => '2024-03-10 09:00:00']);
        Beneficiary::factory()->create(['created_at' => '2024-03-20 09:00:00']);
        Beneficiary::factory()->create(['created_at' => '2024-07-01 09:00:00']);
        Beneficiary::factory()->create(['created_at' => '2024-11-15 09:00:00']);
        Beneficiary::factory()->create(['created_at' => '2025-01-05 09:00:00']);

        $response = $this->actingAs($admin)->get('/dashboard');
        $trend = $response->viewData('registrationTrend');

        $this->assertSame(2, $trend['months'][2024][3]);
        $this->assertSame(1, $trend['months'][2024][7]);
        $this->assertSame(1, $trend['months'][2024][11]);
        $this->assertSame(0, $trend['months'][2024][1]);
        $this->assertSame(4, $trend['annual'][2024]);
        $this->assertSame(1, $trend['annual'][2025]);
        $this->assertSame(5, $trend['all_total']);

        // All Years aggregates annual totals in chronological order.
        $years = array_keys($trend['annual']);
        $sorted = $years;
        sort($sorted);
        $this->assertSame($sorted, $years);
    }

    public function test_registration_trend_counts_each_beneficiary_once_and_skips_archived(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->count(2)->create(['created_at' => '2025-04-01 09:00:00']);

        $archived = Beneficiary::factory()->create(['created_at' => '2025-04-02 09:00:00']);
        $archived->archive();

        $response = $this->actingAs($admin)->get('/dashboard');
        $trend = $response->viewData('registrationTrend');
        $counts = $response->viewData('counts');

        $this->assertSame(2, $trend['months'][2025][4]);
        $this->assertSame(2, $trend['annual'][2025]);
        $this->assertSame(2, $trend['all_total']);
        $this->assertSame($counts['total'], $trend['all_total']);
    }

    public function test_registration_trend_reports_missing_dates_separately(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['created_at' => '2025-06-01 09:00:00']);
        Beneficiary::factory()->create(['created_at' => null]);

        $response = $this->actingAs($admin)->get('/dashboard');
        $trend = $response->viewData('registrationTrend');

        $this->assertSame(1, $trend['unclassified']);
        $this->assertSame(1, $trend['all_total']);
        $response->assertSee('without a registration date');
    }

    public function test_registration_trend_includes_the_current_year_even_without_records(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['created_at' => '2024-02-02 09:00:00']);

        $trend = $this->actingAs($admin)->get('/dashboard')->viewData('registrationTrend');

        $this->assertContains(now()->year, $trend['years']);
        $this->assertSame(0, array_sum($trend['months'][now()->year]));
        $this->assertSame(1, $trend['months'][2024][2]);
    }

    public function test_registration_trend_shows_future_months_as_zero(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['created_at' => now()->startOfYear()->addDays(3)]);

        $trend = $this->actingAs($admin)->get('/dashboard')->viewData('registrationTrend');

        $this->assertSame(1, $trend['months'][now()->year][1]);

        for ($month = now()->month + 1; $month <= 12; $month++) {
            $this->assertSame(0, $trend['months'][now()->year][$month], "month {$month} should be zero");
        }
    }

    public function test_registration_trend_summary_links_to_the_registered_year_filter(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('beneficiaries.index', ['registered_year' => (string) now()->year]), false);
    }

    public function test_registered_year_filter_matches_the_chart_grouping(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['first_name' => 'TWOZERO24', 'created_at' => '2024-03-03 10:00:00']);
        Beneficiary::factory()->create(['first_name' => 'TWOZERO25', 'created_at' => '2025-03-03 10:00:00']);
        Beneficiary::factory()->create(['first_name' => 'NODATE', 'created_at' => null]);

        $this->actingAs($admin)->get('/beneficiaries?registered_year=2024')
            ->assertOk()
            ->assertSee('TWOZERO24')
            ->assertDontSee('TWOZERO25');

        $this->actingAs($admin)->get('/beneficiaries?registered_year=unclassified')
            ->assertOk()
            ->assertSee('NODATE')
            ->assertDontSee('TWOZERO24');
    }

    public function test_dashboard_has_two_analytics_cards_with_one_shared_year_control(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk()
            ->assertSee('Beneficiary Registration Trend')
            ->assertSee('Monthly beneficiary registration activity for the selected year.')
            ->assertSee('Year-Based Batch Distribution')
            ->assertSee('Beneficiary distribution across batches for the selected year.')
            ->assertSee('id="dashboard-year"', false)
            ->assertDontSee('id="trend-year"', false)
            ->assertDontSee('id="batch-year"', false)
            ->assertSee('data-registration-trend', false)
            ->assertSee('data-batch-data', false);

        $trend = $response->viewData('registrationTrend');
        $this->assertSame(now()->year, $trend['current']);
    }

    public function test_batch_distribution_defaults_to_the_most_recent_year_with_batches(): void
    {
        $admin = $this->admin();

        $old = BeneficiaryBatch::factory()->create(['name' => 'Old Batch', 'program_year' => 2024]);
        $mid = BeneficiaryBatch::factory()->create(['name' => 'Mid Batch', 'program_year' => 2025]);
        $current = BeneficiaryBatch::factory()->create(['name' => 'Current Batch', 'program_year' => 2026]);

        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $old->id]);
        Beneficiary::factory()->count(3)->create(['beneficiary_batch_id' => $mid->id]);
        Beneficiary::factory()->count(5)->create(['beneficiary_batch_id' => $current->id]);

        $second2026 = BeneficiaryBatch::factory()->create(['name' => 'Second 2026 Batch', 'program_year' => 2026]);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $second2026->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();

        $data = $response->viewData('batchYearData');

        $this->assertSame([2026, 2025, 2024], $data['years']);
        $this->assertSame(2026, $data['default']);

        // 2026: 5 + 1 across two distinct batch records.
        $this->assertSame(6, $data['by_year']['2026']['total']);
        $this->assertSame(2, $data['by_year']['2026']['batch_count']);
        $this->assertSame(5, $data['by_year']['2026']['batches'][0]['count']);
        $this->assertSame(83.3, $data['by_year']['2026']['batches'][0]['percent']);
        $this->assertSame(2026, $data['by_year']['2026']['batches'][0]['year']);

        $this->assertSame(3, $data['by_year']['2025']['total']);
        $this->assertSame(2, $data['by_year']['2024']['total']);

        // All years aggregate (percentages against the overall population).
        $this->assertSame(11, $data['all']['total']);
        $this->assertSame(4, $data['all']['batch_count']);
        $this->assertSame(45.5, $data['all']['batches'][0]['percent']);

        // The default year's rows are rendered server-side with batch links.
        $response->assertSee(route('beneficiaries.index', ['batch' => $current->id]), false);
        $response->assertSee('Current Batch');
        $response->assertSee('6 active beneficiaries across');
    }

    public function test_batch_distribution_counts_distinct_active_members_only(): void
    {
        $admin = $this->admin();

        $batch = BeneficiaryBatch::factory()->create(['name' => 'Counted Batch', 'program_year' => 2025]);
        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $batch->id]);

        $archived = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $archived->archive();

        $response = $this->actingAs($admin)->get('/dashboard');

        $data = $response->viewData('batchYearData');

        $this->assertSame(2, $data['by_year']['2025']['total']);
        $this->assertSame(2, $data['by_year']['2025']['batches'][0]['count']);
        $this->assertSame(100.0, $data['by_year']['2025']['batches'][0]['percent']);
        $this->assertSame(2, $data['all']['total']);
    }

    public function test_batch_distribution_year_selection_is_independent_of_the_trend(): void
    {
        $admin = $this->admin();

        // A batch in the previous year only: the batch card defaults to that
        // year while the registration trend stays on the current year.
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Prior Year Batch', 'program_year' => now()->year - 1]);
        Beneficiary::factory()->count(4)->create(['beneficiary_batch_id' => $batch->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $trend = $response->viewData('registrationTrend');
        $batchData = $response->viewData('batchYearData');

        $this->assertSame(now()->year, $trend['current']);
        $this->assertSame(now()->year - 1, $batchData['default']);
        $this->assertSame(4, $batchData['by_year'][(string) (now()->year - 1)]['total']);
        $response->assertSee('Prior Year Batch');
    }

    public function test_batch_distribution_remains_available_for_batch_management(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Retained Batch']);
        Beneficiary::factory()->count(3)->create(['beneficiary_batch_id' => $batch->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('batchDistribution', function (array $dist): bool {
            return $dist['total'] === 3 && count($dist['batches']) === 1;
        });
        $response->assertSee('Retained Batch');
        $response->assertSee(route('beneficiary-batches.show', $batch), false);

        // The batch listing keeps the per-batch member counts.
        $this->actingAs($admin)->get(route('beneficiary-batches.index'))
            ->assertOk()
            ->assertSee('Retained Batch');
    }

    public function test_archived_batch_members_do_not_inflate_the_donut(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create();

        $member = Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);
        $member->archive();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertViewHas('batchDistribution', fn (array $dist): bool => $dist['total'] === 0);
        $response->assertViewHas('counts', fn (array $counts): bool => $counts['total'] === 0);
    }

    public function test_batch_overview_lists_open_and_closed_batches(): void
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
        $response->assertSee('Payroll Closed');
    }

    public function test_dashboard_links_point_at_supported_list_filters(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertSee(route('beneficiaries.index'), false);
        $response->assertSee(route('beneficiaries.index', ['batch' => 'unassigned']), false);
        $response->assertSee(route('beneficiaries.index', ['age_range' => 'below18']), false);
        $response->assertSee(route('beneficiaries.index', ['age_range' => 'unclassified']), false);
        $response->assertSee(route('beneficiaries.index', ['status' => 'archived']), false);
    }

    public function test_batch_overview_includes_batches_with_no_beneficiaries_and_zeroed_trend(): void
    {
        $admin = $this->admin();
        BeneficiaryBatch::factory()->create(['name' => 'Empty Batch']);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Empty Batch');
        $response->assertSee('Beneficiary Registration Trend');
        $response->assertSee('Jan');
        $response->assertSee('Dec');
        $response->assertDontSee('NaN', false);
        $response->assertDontSee('Infinity', false);

        $trend = $response->viewData('registrationTrend');
        $this->assertSame(now()->year, $trend['current']);
        $this->assertSame(0, array_sum($trend['months'][now()->year]));
    }

    public function test_batch_overview_includes_closed_batches_with_members(): void
    {
        $admin = $this->admin();

        $closed = BeneficiaryBatch::factory()->closed()->create(['name' => 'Closed Payout']);
        BeneficiaryBatch::factory()->create(['name' => 'Empty Open']);

        Beneficiary::factory()->count(2)->create(['beneficiary_batch_id' => $closed->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('batchDistribution', function (array $dist): bool {
            return $dist['total'] === 2
                && count($dist['batches']) === 2;
        });
        $response->assertViewHas('batches', fn ($batches): bool => $batches->count() === 2);
        $response->assertSee('Closed Payout');
        $response->assertSee('Empty Open');
    }

    public function test_batch_overview_links_to_the_batch_page(): void
    {
        $admin = $this->admin();
        $batch = BeneficiaryBatch::factory()->create(['name' => 'Batch Zeta']);
        Beneficiary::factory()->create(['beneficiary_batch_id' => $batch->id]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('beneficiary-batches.show', $batch), false);
    }

    public function test_demographics_reconcile_with_the_active_population(): void
    {
        $admin = $this->admin();

        // Gender variants: MALE/M and FEMALE/F must merge into the two
        // categories; a blank value lands in Other / Unspecified.
        Beneficiary::factory()->create(['sex' => 'MALE', 'birthdate' => now()->subYears(30)->toDateString(), 'civil_status' => 'SINGLE']);
        Beneficiary::factory()->create(['sex' => 'M', 'birthdate' => now()->subYears(45)->toDateString(), 'civil_status' => 'MARRIED']);
        Beneficiary::factory()->create(['sex' => 'FEMALE', 'birthdate' => now()->subYears(20)->toDateString(), 'civil_status' => 'WIDOW']);
        Beneficiary::factory()->create(['sex' => 'F', 'birthdate' => now()->subYears(65)->toDateString(), 'civil_status' => 'WIDOWED']);
        Beneficiary::factory()->create(['sex' => null, 'birthdate' => null, 'civil_status' => null]);
        Beneficiary::factory()->create(['sex' => 'X', 'birthdate' => now()->subYears(16)->toDateString(), 'civil_status' => 'DIVORCED']);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();

        $demographics = $response->viewData('demographics');
        $total = 6;

        $this->assertSame($total, $demographics['total']);

        // Gender reconciliation.
        $gender = collect($demographics['gender'])->keyBy('key');
        $this->assertSame(2, $gender['male']['count']);
        $this->assertSame(2, $gender['female']['count']);
        $this->assertSame(2, $gender['other']['count']);
        $this->assertSame($total, $gender->sum('count'));

        // Age reconciliation: below 18, 18–25, 26–31, 41–50, 61+, unclassified.
        $age = collect($demographics['age'])->keyBy('key');
        $this->assertSame(1, $age['below18']['count']);
        $this->assertSame(1, $age['18-25']['count']);
        $this->assertSame(1, $age['26-31']['count']);
        $this->assertSame(1, $age['41-50']['count']);
        $this->assertSame(1, $age['61plus']['count']);
        $this->assertSame(1, $age['unclassified']['count']);
        $this->assertSame($total, $age->sum('count'));

        // Marital reconciliation, including WIDOW/WIDOWED merging.
        $marital = collect($demographics['marital'])->keyBy('key');
        $this->assertSame(1, $marital['single']['count']);
        $this->assertSame(1, $marital['married']['count']);
        $this->assertSame(2, $marital['widowed']['count']);
        $this->assertSame(1, $marital['divorced_separated']['count']);
        $this->assertSame(1, $marital['other']['count']);
        $this->assertSame($total, $marital->sum('count'));

        // Percentages use the active total as the denominator.
        $this->assertSame(round(2 / $total * 100, 1), $gender['male']['percent']);
    }

    public function test_demographic_cards_link_to_supported_beneficiary_filters(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['sex' => 'MALE', 'civil_status' => 'SINGLE', 'birthdate' => now()->subYears(30)->toDateString()]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('beneficiaries.index', ['sex' => 'MALE']), false);
        $response->assertSee(route('beneficiaries.index', ['sex' => 'FEMALE']), false);
        $response->assertSee(route('beneficiaries.index', ['age_range' => '18-25']), false);
        $response->assertSee(route('beneficiaries.index', ['age_range' => 'unclassified']), false);
        $response->assertSee(route('beneficiaries.index', ['civil_status' => 'SINGLE']), false);
    }

    public function test_age_range_filter_matches_the_dashboard_groups(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['first_name' => 'TWENTY', 'birthdate' => now()->subYears(20)->toDateString()]);
        Beneficiary::factory()->create(['first_name' => 'FORTY', 'birthdate' => now()->subYears(45)->toDateString()]);
        Beneficiary::factory()->create(['first_name' => 'NOBIRTH', 'birthdate' => null]);

        $response = $this->actingAs($admin)->get('/beneficiaries?age_range=18-25');

        $response->assertOk();
        $response->assertSee('TWENTY');
        $response->assertDontSee('FORTY');
        $response->assertDontSee('NOBIRTH');

        $this->actingAs($admin)->get('/beneficiaries?age_range=41-50')
            ->assertOk()
            ->assertSee('FORTY')
            ->assertDontSee('TWENTY');

        $this->actingAs($admin)->get('/beneficiaries?age_range=unclassified')
            ->assertOk()
            ->assertSee('NOBIRTH')
            ->assertDontSee('TWENTY');
    }

    public function test_sex_filter_accepts_m_and_f_aliases(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['first_name' => 'ALPHA', 'sex' => 'MALE']);
        Beneficiary::factory()->create(['first_name' => 'BETA', 'sex' => 'M']);
        Beneficiary::factory()->create(['first_name' => 'GAMMA', 'sex' => 'FEMALE']);

        $this->actingAs($admin)->get('/beneficiaries?sex=MALE')
            ->assertOk()
            ->assertSee('ALPHA')
            ->assertSee('BETA')
            ->assertDontSee('GAMMA');
    }

    public function test_civil_status_filter_accepts_multiple_values(): void
    {
        $admin = $this->admin();

        Beneficiary::factory()->create(['first_name' => 'WIDOWONE', 'civil_status' => 'WIDOW']);
        Beneficiary::factory()->create(['first_name' => 'WIDOWTWO', 'civil_status' => 'WIDOWED']);
        Beneficiary::factory()->create(['first_name' => 'SINGLEONE', 'civil_status' => 'SINGLE']);

        $this->actingAs($admin)->get('/beneficiaries?civil_status=WIDOWED,WIDOW')
            ->assertOk()
            ->assertSee('WIDOWONE')
            ->assertSee('WIDOWTWO')
            ->assertDontSee('SINGLEONE');
    }

    public function test_recent_imports_show_preview_outcome_counts(): void
    {
        $admin = $this->admin();

        $import = ImportBatch::create([
            'user_id' => $admin->id,
            'original_filename' => 'candidates.xlsx',
            'worksheet' => '1,000',
            'header_row' => 13,
            'status' => 'previewed',
            'rows_read' => 4,
        ]);

        foreach (['new', 'new', 'update', 'review'] as $index => $outcome) {
            ImportRow::create([
                'import_batch_id' => $import->id,
                'excel_row' => 15 + $index,
                'outcome' => $outcome,
                'payload' => ['first_name' => 'ROW'],
                'messages' => [],
                'applied' => false,
            ]);
        }

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();

        $recent = $response->viewData('recentBatches')->first();
        $this->assertSame(['new' => 2, 'update' => 1, 'review' => 1, 'invalid' => 0], $recent->outcome_counts);
        $response->assertSee('candidates.xlsx');
        $response->assertSee('Previewed');
    }

    public function test_the_dashboard_renders_the_redesigned_chart_canvases(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="registrationTrendChart"', false)
            ->assertSee('id="batchDistributionChart"', false)
            ->assertSee('chart.umd.min.js', false)
            ->assertSee('Beneficiary Registration Trend')
            ->assertSee('Year-Based Batch Distribution');
    }
}
