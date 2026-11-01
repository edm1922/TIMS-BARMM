<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sync\InstallationPackageService;
use Illuminate\Console\Command;
use Throwable;

class BuildInstallationPackage extends Command
{
    protected $signature = 'offline-package:build
                            {--label= : Device label recorded with the package}
                            {--note= : Optional note recorded with the package}
                            {--action= : Action to perform after build (download or publish)}
                            {--actor= : Identifier of the administrator who requested the package}
                            {--ip= : Address of the administrator who requested the package}';

    protected $description = 'Build an offline installation package in the background';

    public function handle(InstallationPackageService $packages): int
    {
        $label = $this->option('label');
        $note = $this->option('note');
        $action = $this->option('action') ?? 'download';
        $actor = $this->option('actor') !== null
            ? User::query()->find((int) $this->option('actor'))
            : null;
        $ip = $this->option('ip');

        $packages->writeStatus([
            'state' => 'running',
            'label' => $label,
            'note' => $note,
            'action' => $action,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'processed_files' => 0,
            'total_files' => 0,
            'bytes_processed' => 0,
            'total_bytes' => 0,
            'current_file' => null,
            'elapsed_seconds' => 0,
            'filename' => null,
            'size' => null,
            'sha256' => null,
            'files' => null,
            'error' => null,
            'pid' => getmypid(),
        ]);

        try {
            $package = $packages->build(
                $label,
                $note,
                function (array $progress) use ($packages): void {
                    $packages->writeStatus($progress + ['state' => 'running']);
                },
            );
        } catch (Throwable $exception) {
            $packages->writeStatus([
                'state' => 'failed',
                'label' => $label,
                'note' => $note,
                'action' => $action,
                'finished_at' => now()->toIso8601String(),
                'error' => $exception->getMessage(),
            ]);

            AuditLogger::log('enroll_server.package_generation_failed', 'enroll_server', null, [
                'device_label' => $label,
                'error' => $exception->getMessage(),
            ], $actor, $ip);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $packages->writeStatus([
            'state' => 'completed',
            'label' => $label,
            'note' => $note,
            'action' => $action,
            'finished_at' => now()->toIso8601String(),
            'filename' => $package['filename'],
            'size' => $package['size'],
            'sha256' => $package['sha256'],
            'files' => $package['files'],
            'current_file' => null,
        ]);

        AuditLogger::log('enroll_server.package_generated', 'enroll_server', null, [
            'filename' => $package['filename'],
            'size' => $package['size'],
            'files' => $package['files'],
            'sha256' => $package['sha256'],
            'device_label' => $label,
        ], $actor, $ip);

        if ($action === 'publish') {
            try {
                $packages->publish($package, $label, config('sync.central_url'));
            } catch (Throwable $exception) {
                $packages->writeStatus([
                    'state' => 'failed',
                    'label' => $label,
                    'note' => $note,
                    'action' => $action,
                    'finished_at' => now()->toIso8601String(),
                    'filename' => $package['filename'],
                    'size' => $package['size'],
                    'sha256' => $package['sha256'],
                    'files' => $package['files'],
                    'error' => 'The package was built but could not be published: '.$exception->getMessage(),
                ]);

                AuditLogger::log('enroll_server.package_publish_failed', 'enroll_server', null, [
                    'filename' => $package['filename'],
                    'device_label' => $label,
                    'error' => $exception->getMessage(),
                ], $actor, $ip);

                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->info('Installation package generated: '.$package['filename']);

        return self::SUCCESS;
    }
}
