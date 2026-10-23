<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
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

    public const OPEN_BATCH_LIMIT = 5;

    /**
     * Duplicate states that are reported as duplicates (never merged automatically).
     */
    private const DUPLICATE_STATUSES = ['candidate', 'confirmed_duplicate'];

    /**
     * @return array{
     *     counts: array<string, int>,
     *     assignment: array{total: int, assigned: int, unassigned: int, assigned_percent: ?float, unassigned_percent: ?float},
     *     open_batches: Collection<int, BeneficiaryBatch>,
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

        $openBatchesAll = BeneficiaryBatch::query()
            ->open()
            ->withMemberCount()
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

        $totalBatchMembers = (int) $openBatchesAll->sum('beneficiaries_count');
        $circumference = round(2 * M_PI * 48, 2);
        $currentOffset = 0.0;
        $batchDistributionItems = [];

        foreach ($openBatchesAll as $index => $batch) {
            $count = (int) $batch->memberCount();
            $percent = $totalBatchMembers > 0 ? round(($count / $totalBatchMembers) * 100, 1) : null;
            $length = $totalBatchMembers > 0 ? round($circumference * ($count / $totalBatchMembers), 2) : 0.0;
            $color = $batchPalette[$index % count($batchPalette)];

            $batchDistributionItems[] = [
                'id' => $batch->id,
                'name' => $batch->name,
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
            'open_batches' => $openBatchesAll->take(self::OPEN_BATCH_LIMIT),
            'recent_imports' => ImportBatch::with(['user', 'beneficiaryBatch'])
                ->latest()
                ->limit(self::RECENT_IMPORT_LIMIT)
                ->get(),
        ];
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
