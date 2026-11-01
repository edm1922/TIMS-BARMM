<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Support\Collection;

/**
 * Aggregated metrics for the dashboard.
 *
 * Every figure is produced with a fixed number of queries so the dashboard
 * never fans out into per-row lookups, and the assignment split is read from
 * a single scan of the active beneficiaries.
 */
class DashboardStats
{
    public const RECENT_IMPORT_LIMIT = 6;

    public const BATCH_OVERVIEW_LIMIT = 5;

    /**
     * Duplicate states that are reported as duplicates (never merged automatically).
     */
    private const DUPLICATE_STATUSES = ['candidate', 'confirmed_duplicate'];

    /**
     * @return array{
     *     counts: array<string, int>,
     *     assignment: array{total: int, assigned: int, unassigned: int, assigned_percent: ?float, unassigned_percent: ?float},
     *     batch_overview: Collection<int, BeneficiaryBatch>,
     *     recent_imports: Collection<int, ImportBatch>
     * }
     */
    public function summary(): array
    {
        $duplicateStatuses = implode("', '", self::DUPLICATE_STATUSES);

        $totals = Beneficiary::query()
            ->active()
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw('COALESCE(SUM(CASE WHEN beneficiary_batch_id IS NOT NULL THEN 1 ELSE 0 END), 0) as assigned')
            ->selectRaw('COALESCE(SUM(CASE WHEN beneficiary_batch_id IS NULL THEN 1 ELSE 0 END), 0) as unassigned')
            ->selectRaw('COALESCE(SUM(CASE WHEN birthdate IS NULL THEN 1 ELSE 0 END), 0) as missing_birthdate')
            ->selectRaw("COALESCE(SUM(CASE WHEN duplicate_status IN ('{$duplicateStatuses}') THEN 1 ELSE 0 END), 0) as duplicate_candidates")
            ->first();

        $total = (int) $totals->aggregate;
        $assigned = (int) $totals->assigned;
        $unassigned = (int) $totals->unassigned;

        $batchCounts = BeneficiaryBatch::query()
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) as open")
            ->first();

        $counts = [
            'total' => $total,
            'assigned_to_batch' => $assigned,
            'unassigned' => $unassigned,
            'underage' => $this->underageCount(),
            'unknown_age' => (int) $totals->missing_birthdate,
            'duplicate_candidates' => (int) $totals->duplicate_candidates,
            'archived' => Beneficiary::query()->archived()->count(),
            'active_batches' => (int) $batchCounts->open,
            'total_batches' => (int) $batchCounts->aggregate,
        ];

        $batchesAll = BeneficiaryBatch::query()
            ->withCount(['beneficiaries' => fn ($query) => $query->active()])
            ->orderByDesc('beneficiaries_count')
            ->latest('id')
            ->get();

        $batchPalette = [
            '#2563eb', // Blue
            '#10b981', // Emerald
            '#f59e0b', // Amber
            '#8b5cf6', // Violet
            '#ec4899', // Pink
            '#06b6d4', // Cyan
            '#f97316', // Orange
            '#6366f1', // Indigo
            '#14b8a6', // Teal
            '#ca8a04', // Yellow
        ];

        $totalBatchMembers = (int) $batchesAll->sum('beneficiaries_count');
        $circumference = round(2 * M_PI * 48, 2);
        $currentOffset = 0.0;
        $batchDistributionItems = [];

        foreach ($batchesAll as $index => $batch) {
            $count = (int) $batch->memberCount();
            $percent = $totalBatchMembers > 0 ? round(($count / $totalBatchMembers) * 100, 1) : null;
            $length = $totalBatchMembers > 0 ? round($circumference * ($count / $totalBatchMembers), 2) : 0.0;
            $color = $batchPalette[$index % count($batchPalette)];

            $batchDistributionItems[] = [
                'id' => $batch->id,
                'name' => $batch->name,
                'status' => $batch->status,
                'count' => $count,
                'percent' => $percent,
                'color' => $color,
                'length' => $length,
                'offset' => round($currentOffset, 2),
            ];

            $currentOffset += $length;
        }

        return [
            'counts' => $counts,
            'assignment' => [
                'total' => $total,
                'assigned' => $assigned,
                'unassigned' => $unassigned,
                'assigned_percent' => $this->percent($assigned, $total),
                'unassigned_percent' => $this->percent($unassigned, $total),
            ],
            'batch_distribution' => [
                'total' => $totalBatchMembers,
                'batches' => $batchDistributionItems,
            ],
            'batch_overview' => $batchesAll->take(self::BATCH_OVERVIEW_LIMIT),
            'recent_imports' => $this->recentImports(),
            'demographics' => $this->demographics($total),
            'registration_trend' => $this->registrationTrend(),
            'batch_distribution_by_year' => $this->batchDistributionByYear(),
        ];
    }

    /**
     * Monthly beneficiary registration counts per year.
     *
     * Date field: `created_at` — the moment the beneficiary record was
     * registered in TIMS-BARMM (manual entry or import apply). The schema has
     * no dedicated DOLE profiling-date column; Excel upload/batch dates are
     * administrative activities, not registration dates, so they are not
     * used. Records without a timestamp are reported as unclassified and are
     * never assigned to a year or month.
     *
     * @return array{
     *     years: list<int>,
     *     current: int,
     *     months: array<int, array<int, int>>,
     *     annual: array<int, int>,
     *     all_total: int,
     *     unclassified: int
     * }
     */
    private function registrationTrend(): array
    {
        $rows = Beneficiary::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNotNull('profiled_on')->orWhereNotNull('created_at');
            })
            ->selectRaw('SUBSTR(COALESCE(profiled_on, created_at), 1, 4) as year, SUBSTR(COALESCE(profiled_on, created_at), 6, 2) as month, COUNT(*) as total')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        $months = [];
        $annual = [];

        foreach ($rows as $row) {
            $year = (int) $row->year;
            $month = (int) $row->month;
            $count = (int) $row->total;

            $months[$year][$month] = $count;
            $annual[$year] = ($annual[$year] ?? 0) + $count;
        }

        // Every year carries all twelve months (zero-filled) so the chart can
        // always render January–December without gaps.
        $current = now()->year;
        $years = array_values(array_unique(array_merge(array_keys($annual), [$current])));
        rsort($years);

        $completeMonths = [];

        foreach ($years as $year) {
            $completeMonths[$year] = [];

            for ($month = 1; $month <= 12; $month++) {
                $completeMonths[$year][$month] = $months[$year][$month] ?? 0;
            }
        }

        ksort($annual);

        return [
            'years' => $years,
            'current' => $current,
            'months' => $completeMonths,
            'annual' => $annual,
            'all_total' => array_sum($annual),
            'unclassified' => Beneficiary::query()->active()
                ->whereNull('profiled_on')
                ->whereNull('created_at')
                ->count(),
        ];
    }

    /**
     * Batch distribution grouped by the batch's operational year
     * (`beneficiary_batches.program_year` — the established program year, not
     * the creation or upload date). Counts are distinct active beneficiaries
     * per batch; batches with the same name in different years stay separate
     * records because each row is keyed by batch id.
     *
     * @return array{
     *     years: list<int>,
     *     default: ?int,
     *     by_year: array<string, array{total: int, batches: list<array<string, mixed>>}>,
     *     all: array{total: int, batch_count: int, shown: int, batches: list<array<string, mixed>>},
     *     unclassified: array{total: int, batches: list<array<string, mixed>>}
     * }
     */
    private function batchDistributionByYear(): array
    {
        $batchPalette = [
            '#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899',
            '#06b6d4', '#f97316', '#6366f1', '#14b8a6', '#ca8a04',
        ];

        $batches = BeneficiaryBatch::query()
            ->withCount(['beneficiaries' => fn ($query) => $query->active()])
            ->get();

        $build = function (Collection $items, int $limit = 0) use ($batchPalette): array {
            $total = (int) $items->sum(fn (BeneficiaryBatch $batch): int => (int) $batch->beneficiaries_count);

            $sorted = $items
                ->sortByDesc('beneficiaries_count')
                ->values();

            $shown = $limit > 0 ? $sorted->take($limit)->values() : $sorted;

            $rows = $shown->map(function (BeneficiaryBatch $batch, int $index) use ($total, $batchPalette): array {
                $count = (int) $batch->beneficiaries_count;

                return [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'status' => $batch->status,
                    'year' => $batch->program_year !== null ? (int) $batch->program_year : null,
                    'count' => $count,
                    'percent' => $total > 0 ? round($count / $total * 100, 1) : null,
                    'color' => $batchPalette[$index % count($batchPalette)],
                ];
            })->values()->all();

            return [
                'total' => $total,
                'batch_count' => $sorted->count(),
                'shown' => count($rows),
                'batches' => $rows,
            ];
        };

        $years = $batches
            ->pluck('program_year')
            ->filter(fn ($year): bool => $year !== null)
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn ($year): int => (int) $year)
            ->all();

        $byYear = [];

        foreach ($years as $year) {
            $group = $batches->filter(fn (BeneficiaryBatch $batch): bool => (int) $batch->program_year === $year);

            $byYear[(string) $year] = $build($group);
        }

        $all = $build($batches, limit: 15);

        $unclassified = $build($batches->filter(fn (BeneficiaryBatch $batch): bool => $batch->program_year === null));

        return [
            'years' => $years,
            'default' => $years[0] ?? null,
            'by_year' => $byYear,
            'all' => [
                'total' => $all['total'],
                'batch_count' => $all['batch_count'],
                'shown' => $all['shown'],
                'batches' => $all['batches'],
            ],
            'unclassified' => ['total' => $unclassified['total'], 'batches' => $unclassified['batches']],
        ];
    }

    /**
     * Demographic breakdowns of the active register. Every category is
     * computed from the stored records; missing or unrecognized values are
     * folded into "Other / Unspecified" so the categories always reconcile
     * with the active total.
     *
     * @return array{
     *     total: int,
     *     gender: list<array{key: string, label: string, count: int, percent: ?float, color: string, filter: ?string}>,
     *     age: list<array{key: string, label: string, count: int, percent: ?float, filter: ?string}>,
     *     marital: list<array{key: string, label: string, count: int, percent: ?float, color: string, filter: ?string}>
     * }
     */
    private function demographics(int $total): array
    {
        // --- Gender: stored values vary (MALE/M, FEMALE/F); merge for display.
        $genderGroups = Beneficiary::query()
            ->active()
            ->selectRaw("UPPER(TRIM(COALESCE(sex, ''))) as sex_key, COUNT(*) as total")
            ->groupBy('sex_key')
            ->pluck('total', 'sex_key');

        $male = (int) ($genderGroups['MALE'] ?? 0) + (int) ($genderGroups['M'] ?? 0);
        $female = (int) ($genderGroups['FEMALE'] ?? 0) + (int) ($genderGroups['F'] ?? 0);
        $other = max(0, $total - $male - $female);

        $gender = [
            ['key' => 'male', 'label' => 'Male', 'count' => $male, 'percent' => $this->percent($male, $total), 'color' => '#2563eb', 'filter' => 'MALE'],
            ['key' => 'female', 'label' => 'Female', 'count' => $female, 'percent' => $this->percent($female, $total), 'color' => '#a855f7', 'filter' => 'FEMALE'],
            ['key' => 'other', 'label' => 'Other / Unspecified', 'count' => $other, 'percent' => $this->percent($other, $total), 'color' => '#94a3b8', 'filter' => null],
        ];

        // --- Age: one grouped query over the birthdate cutoffs (same rule as
        // AgeService: a future date cannot produce a valid age).
        $today = now()->toDateString();
        $cutoff = fn (int $years): string => now()->subYears($years)->toDateString();

        $ageRow = Beneficiary::query()
            ->active()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN birthdate IS NULL OR birthdate > ? THEN 1 ELSE 0 END), 0) as unclassified,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as below18,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as g18_25,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as g26_31,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as g32_40,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as g41_50,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as g51_60,
                 COALESCE(SUM(CASE WHEN birthdate <= ? AND birthdate > ? THEN 1 ELSE 0 END), 0) as g61_plus',
                [
                    $today,
                    $today, $cutoff(18),
                    $cutoff(18), $cutoff(26),
                    $cutoff(26), $cutoff(32),
                    $cutoff(32), $cutoff(41),
                    $cutoff(41), $cutoff(51),
                    $cutoff(51), $cutoff(61),
                    $cutoff(61), $cutoff(150),
                ],
            )
            ->first();

        $ageDefs = [
            ['key' => 'below18', 'label' => 'Below 18', 'value' => (int) ($ageRow->below18 ?? 0), 'filter' => 'below18'],
            ['key' => '18-25', 'label' => '18–25 years', 'value' => (int) ($ageRow->g18_25 ?? 0), 'filter' => '18-25'],
            ['key' => '26-31', 'label' => '26–31 years', 'value' => (int) ($ageRow->g26_31 ?? 0), 'filter' => '26-31'],
            ['key' => '32-40', 'label' => '32–40 years', 'value' => (int) ($ageRow->g32_40 ?? 0), 'filter' => '32-40'],
            ['key' => '41-50', 'label' => '41–50 years', 'value' => (int) ($ageRow->g41_50 ?? 0), 'filter' => '41-50'],
            ['key' => '51-60', 'label' => '51–60 years', 'value' => (int) ($ageRow->g51_60 ?? 0), 'filter' => '51-60'],
            ['key' => '61plus', 'label' => '61 years and above', 'value' => (int) ($ageRow->g61_plus ?? 0), 'filter' => '61plus'],
            ['key' => 'unclassified', 'label' => 'Unclassified', 'value' => (int) ($ageRow->unclassified ?? 0), 'filter' => 'unclassified'],
        ];

        $age = array_map(fn (array $def): array => [
            'key' => $def['key'],
            'label' => $def['label'],
            'count' => $def['value'],
            'percent' => $this->percent($def['value'], $total),
            'filter' => $def['filter'],
        ], $ageDefs);

        // --- Marital status: group by the stored values, then map to the
        // reporting categories. Filters carry the exact stored values so the
        // links land on the same rows that were counted.
        $maritalGroups = Beneficiary::query()
            ->active()
            ->selectRaw("COALESCE(civil_status, '') as status_key, COUNT(*) as total")
            ->groupBy('status_key')
            ->pluck('total', 'status_key');

        $maritalBuckets = [
            'single' => ['label' => 'Single', 'color' => '#2563eb', 'values' => []],
            'married' => ['label' => 'Married', 'color' => '#10b981', 'values' => []],
            'widowed' => ['label' => 'Widowed', 'color' => '#f59e0b', 'values' => []],
            'divorced_separated' => ['label' => 'Divorced / Separated', 'color' => '#8b5cf6', 'values' => []],
            'other' => ['label' => 'Other / Unspecified', 'color' => '#94a3b8', 'values' => []],
        ];

        foreach ($maritalGroups as $rawValue => $count) {
            $key = mb_strtoupper(trim((string) $rawValue));

            $bucket = match (true) {
                $key === 'SINGLE' => 'single',
                $key === 'MARRIED' => 'married',
                in_array($key, ['WIDOW', 'WIDOWED'], true) => 'widowed',
                str_contains($key, 'DIVORCED'), str_contains($key, 'SEPARATED') => 'divorced_separated',
                default => 'other',
            };

            $maritalBuckets[$bucket]['values'][] = (string) $rawValue;
            $maritalBuckets[$bucket]['count'] = ($maritalBuckets[$bucket]['count'] ?? 0) + (int) $count;
        }

        $marital = [];
        foreach ($maritalBuckets as $key => $bucket) {
            $count = (int) ($bucket['count'] ?? 0);
            $values = array_values(array_filter($bucket['values'], fn (string $v): bool => trim($v) !== ''));

            $marital[] = [
                'key' => $key,
                'label' => $bucket['label'],
                'count' => $count,
                'percent' => $this->percent($count, $total),
                'color' => $bucket['color'],
                'filter' => $values === [] ? null : implode(',', $values),
            ];
        }

        return ['total' => $total, 'gender' => $gender, 'age' => $age, 'marital' => $marital];
    }

    /**
     * Recent imports with their previewed outcome counts (one grouped query
     * for the whole page, never a query per import batch).
     *
     * @return Collection<int, ImportBatch>
     */
    private function recentImports(): Collection
    {
        $imports = ImportBatch::with(['user', 'beneficiaryBatch'])
            ->latest()
            ->limit(self::RECENT_IMPORT_LIMIT)
            ->get();

        if ($imports->isEmpty()) {
            return $imports;
        }

        $outcomeTotals = ImportRow::query()
            ->whereIn('import_batch_id', $imports->pluck('id')->all())
            ->selectRaw('import_batch_id, outcome, COUNT(*) as total')
            ->groupBy('import_batch_id', 'outcome')
            ->get()
            ->groupBy('import_batch_id');

        foreach ($imports as $import) {
            $counts = ['new' => 0, 'update' => 0, 'review' => 0, 'invalid' => 0];

            foreach ($outcomeTotals->get($import->id, collect()) as $row) {
                if (array_key_exists($row->outcome, $counts)) {
                    $counts[$row->outcome] = (int) $row->total;
                }
            }

            $import->setAttribute('outcome_counts', $counts);
        }

        return $imports;
    }

    /**
     * Active beneficiaries younger than 18, using the same rule as AgeService:
     * a valid, non-future birthdate that is still inside the 18 year window.
     */
    private function underageCount(): int
    {
        return Beneficiary::query()
            ->active()
            ->whereNotNull('birthdate')
            ->whereDate('birthdate', '>', now()->subYears(18)->toDateString())
            ->whereDate('birthdate', '<=', now()->toDateString())
            ->count();
    }

    /**
     * Percentage of a whole, or null when there is nothing to divide by.
     */
    private function percent(int $part, int $total): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round($part / $total * 100, 1);
    }
}
