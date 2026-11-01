<?php

namespace App\Console\Commands;

use App\Services\Sync\GhostBatchCleaner;
use Illuminate\Console\Command;

class SyncCleanGhostBatches extends Command
{
    protected $signature = 'sync:clean-ghost-batches
        {--apply : Archive the ghost records (default is a dry-run preview)}
        {--force : Skip the interactive confirmation when applying}';

    protected $description = 'Find active beneficiaries filed in deleted release batches (offline ghosts) and archive them';

    public function handle(GhostBatchCleaner $cleaner): int
    {
        $preview = $cleaner->preview();

        if ($preview['total'] === 0) {
            $this->info('No ghost records found. Every active beneficiary is filed in a live batch.');

            return Command::SUCCESS;
        }

        $this->warn($preview['total'].' active '.($preview['total'] === 1 ? 'beneficiary is' : 'beneficiaries are')
            .' filed in deleted batches:');

        $this->table(
            ['Batch', 'Deleted at', 'Active members'],
            array_map(fn (array $group): array => [
                $group['name'],
                $group['deleted_at'] ?? '-',
                (string) $group['active_members'],
            ], $preview['groups']),
        );

        if (! $this->option('apply')) {
            $this->line('Dry run only. Re-run with --apply to archive these records.');

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Archive these ghost records now?', false)) {
            $this->line('Cancelled. Nothing was changed.');

            return Command::SUCCESS;
        }

        $result = $cleaner->clean();

        $this->info($result['archived'].' ghost '.($result['archived'] === 1 ? 'record' : 'records').' archived.');

        return Command::SUCCESS;
    }
}
