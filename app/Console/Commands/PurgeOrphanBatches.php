<?php

namespace App\Console\Commands;

use App\Models\BeneficiaryBatch;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeOrphanBatches extends Command
{
    /**
     * Zero-member batches left behind by early PAHAMUDDIN / DATU PIANG test
     * imports. They are soft-deleted tombstones on central and can be mirrored
     * to offline installations, where they show as orphaned 0-member batches.
     *
     * @var array<int, string>
     */
    private const DEFAULT_NAMES = [
        'PAHAMUDDIN, SGA, BARMM',
        'PAHAMUDDIN, SGA, BARMM (1,000)',
        'PAHAMUDIN, SGA, BARMM',
        'PAHAMUDIN, SGA, BARMM (1000)',
        'TEST3',
        'DATU PIANG',
        'PROFILE OF TUPAD BENEFICIARIES-PAHAMUDDIN, SGA, BARMM (1,000)',
        'PROFILE OF TUPAD BENEFICIARIES PAHAMUDIN, SGA, BARMM (1000)',
    ];

    protected $signature = 'batches:purge-orphans
                            {--names=* : Additional batch names to match}
                            {--include-active : Also remove matching active batches with zero beneficiaries}
                            {--force : Permanently delete the matched batches; without this the command is a dry run}';

    protected $description = 'Remove known zero-member orphan batches (soft-deleted tombstones) from this installation';

    public function handle(): int
    {
        $names = array_values(array_filter(array_map('trim', $this->option('names') ?? [])));
        $targets = array_map(
            fn (string $name): string => $this->normalize($name),
            array_merge(self::DEFAULT_NAMES, $names),
        );

        $candidates = BeneficiaryBatch::query()
            ->withTrashed()
            ->withCount('beneficiaries')
            ->get()
            ->filter(function (BeneficiaryBatch $batch) use ($targets): bool {
                if (! in_array($this->normalize($batch->name), $targets, true)) {
                    return false;
                }

                if ((int) $batch->beneficiaries_count !== 0) {
                    return false;
                }

                return $this->option('include-active') || $batch->trashed();
            })
            ->values();

        if ($candidates->isEmpty()) {
            $this->info('No matching zero-member orphan batches found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Trashed', 'Beneficiaries'],
            $candidates->map(fn (BeneficiaryBatch $batch): array => [
                $batch->id,
                $batch->name,
                $batch->trashed() ? 'yes' : 'no',
                $batch->beneficiaries_count,
            ])->all(),
        );

        if (! $this->option('force')) {
            $this->warn('Dry run only. Re-run with --force to permanently delete these batches.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($candidates): void {
            foreach ($candidates as $batch) {
                $batch->forceDelete();

                AuditLogger::log('beneficiary_batch.purged_orphan', 'beneficiary_batch', $batch->id, [
                    'name' => $batch->name,
                    'beneficiaries' => 0,
                    'reason' => 'Zero-member orphan batch removed by batches:purge-orphans.',
                ]);
            }
        });

        $this->info(sprintf('Permanently removed %d orphan batch(es).', $candidates->count()));

        return self::SUCCESS;
    }

    private function normalize(?string $name): string
    {
        $normalized = strtoupper(trim((string) $name));
        // Match the historical typo used in some imports.
        $normalized = str_replace('PAHAMUDIN', 'PAHAMUDDIN', $normalized);

        return (string) preg_replace('/[^A-Z0-9]+/', '', $normalized);
    }
}
