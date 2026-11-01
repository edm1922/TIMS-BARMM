<?php

namespace App\Console\Commands;

use App\Services\Sync\SyncRunner;
use App\Models\SyncSetting;
use Illuminate\Console\Command;

class TimsSync extends Command
{
    protected $signature = 'tims:sync
        {--upload-only : Only push local changes to the central server}
        {--download-only : Only pull central changes down}
        {--quiet-on-skip : Suppress output when no operations are pending}';

    protected $description = 'Run the TIMS-BARMM sync cycle (upload pending operations, download central changes)';

    public function handle(SyncRunner $runner): int
    {
        if (config('sync.instance_mode') !== 'offline') {
            $this->warn('This server is not running in offline mode. Sync skipped.');
            return Command::SUCCESS;
        }

        if (! SyncSetting::get('central_url')) {
            $this->warn('Sync not configured: central_url not set. Enroll first via the web UI.');
            return Command::SUCCESS;
        }

        $uploadOnly   = $this->option('upload-only');
        $downloadOnly = $this->option('download-only');
        $quietOnSkip  = $this->option('quiet-on-skip');

        try {
            if ($downloadOnly) {
                $result = $runner->download();
                $this->printDownloadResult($result, $quietOnSkip);
                return Command::SUCCESS;
            }

            if ($uploadOnly) {
                $result = $runner->upload();
                $this->printUploadResult($result, $quietOnSkip);
                return Command::SUCCESS;
            }

            // Full sync cycle.
            $result = $runner->run();
            $up   = $result['upload'];
            $down = $result['download'];

            $hasPendingWork = ($up['applied'] + $up['failed'] + $down['applied'] + $down['conflicts']) > 0;
            if ($quietOnSkip && ! $hasPendingWork) {
                return Command::SUCCESS;
            }

            $this->line(sprintf(
                '[%s] Sync OK — Upload: %d applied, %d failed | Download: %d applied, %d conflicts',
                now()->format('Y-m-d H:i:s'),
                $up['applied'],
                $up['failed'],
                $down['applied'],
                $down['conflicts'],
            ));

            if ($down['conflicts'] > 0) {
                $this->warn("  {$down['conflicts']} conflict(s) require manual review (Admin > Local Conflicts).");
            }

            if ($up['failed'] > 0) {
                $this->warn("  {$up['failed']} operation(s) failed and will retry automatically.");
            }

        } catch (\Throwable $e) {
            $this->error('[' . now()->format('Y-m-d H:i:s') . '] Sync failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, int> $result
     */
    private function printUploadResult(array $result, bool $quiet): void
    {
        if ($quiet && $result['applied'] === 0 && $result['failed'] === 0) {
            return;
        }
        $this->line(sprintf(
            '[%s] Upload — %d applied, %d failed',
            now()->format('Y-m-d H:i:s'),
            $result['applied'],
            $result['failed'],
        ));
    }

    /**
     * @param array<string, int> $result
     */
    private function printDownloadResult(array $result, bool $quiet): void
    {
        if ($quiet && $result['applied'] === 0 && $result['conflicts'] === 0) {
            return;
        }
        $this->line(sprintf(
            '[%s] Download — %d applied, %d skipped, %d conflicts',
            now()->format('Y-m-d H:i:s'),
            $result['applied'],
            $result['skipped'],
            $result['conflicts'],
        ));
    }
}
