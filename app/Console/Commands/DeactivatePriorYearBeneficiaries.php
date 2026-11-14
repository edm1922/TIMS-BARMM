<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeactivatePriorYearBeneficiaries extends Command
{
    protected $signature = 'beneficiaries:deactivate-prior-years
                            {--apply : Persist the change (default is a dry run)}
                            {--year= : Calendar year to treat as current}';

    protected $description = 'Mark beneficiaries whose coverage year is before the current calendar year as inactive';

    public function handle(): int
    {
        $currentYear = $this->option('year') !== null
            ? (int) $this->option('year')
            : (int) now()->year;

        $candidates = Beneficiary::query()
            ->whereNull('archived_at')
            ->where('is_active', true)
            ->whereNotNull('coverage_year')
            ->where('coverage_year', '<', $currentYear);

        $count = (clone $candidates)->count();

        if ($count === 0) {
            $this->info('No prior-year active beneficiaries to deactivate.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->line('Dry run only: '.$count.' prior-year beneficiary(ies) would be marked inactive. Re-run with --apply.');

            return self::SUCCESS;
        }

        $deactivated = 0;

        DB::transaction(function () use ($candidates, &$deactivated): void {
            $candidates->orderBy('id')->chunkById(500, function ($beneficiaries) use (&$deactivated): void {
                foreach ($beneficiaries as $beneficiary) {
                    $beneficiary->forceFill([
                        'is_active' => false,
                    ])->save();

                    $deactivated++;
                }
            });
        });

        AuditLogger::log('beneficiary.deactivated_prior_year', null, null, [
            'current_year' => $currentYear,
            'count' => $deactivated,
        ]);

        $this->info('Marked '.$deactivated.' prior-year beneficiary(ies) inactive for coverage year '.$currentYear.'.');

        return self::SUCCESS;
    }
}
