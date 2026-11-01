<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
use App\Services\DuplicateDetector;
use Illuminate\Console\Command;

class BackfillDuplicateRules extends Command
{
    protected $signature = 'beneficiaries:backfill-duplicate-rules';

    protected $description = 'Store the duplicate detection rule on candidates flagged before the rule was recorded';

    public function handle(DuplicateDetector $detector): int
    {
        $updated = 0;
        $unmatched = 0;

        Beneficiary::query()
            ->where('duplicate_status', 'candidate')
            ->whereNull('duplicate_rule')
            ->chunkById(200, function ($candidates) use ($detector, &$updated, &$unmatched): void {
                foreach ($candidates as $candidate) {
                    $result = $detector->findFor($candidate, $candidate->id);

                    if ($result === null) {
                        $unmatched++;

                        continue;
                    }

                    $candidate->forceFill(['duplicate_rule' => $result['rule']])->save();
                    $updated++;
                }
            });

        $this->info("Stored a duplicate rule for {$updated} candidate(s).");

        if ($unmatched > 0) {
            $this->warn("{$unmatched} candidate(s) no longer match another record; their rule was left empty.");
        }

        return self::SUCCESS;
    }
}
