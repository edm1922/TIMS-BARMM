<?php

namespace App\Services\Sync;

use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Services\Reports\ReportErrorReporter;
use App\Support\SyncIdentity;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncDiagnostics
{
    public function __construct(private readonly SyncClient $client) {}

    /**
     * @return array<int, array{label: string, status: string, detail: string}>
     */
    public function run(bool $checkCentral = true): array
    {
        $databaseOk = $this->databaseOk();
        $appUrl = (string) config('app.url');
        $localIp = $this->localIp($appUrl);

        $checks = [
            $this->result(
                'Instance mode',
                SyncIdentity::isOffline() ? 'ok' : 'warning',
                SyncIdentity::isOffline()
                    ? 'Running in offline mode.'
                    : 'Running in online mode. Local sync queue is disabled.',
            ),
            $this->result(
                'Local server UUID',
                SyncIdentity::originUuid() !== '' ? 'ok' : 'error',
                (string) SyncIdentity::originUuid(),
            ),
            $this->result(
                'Application URL',
                $appUrl !== '' ? 'ok' : 'warning',
                $appUrl !== '' ? $appUrl : 'Set APP_URL so clients know where to connect.',
            ),
            $this->result(
                'Local network address',
                $localIp !== null ? 'ok' : 'warning',
                $localIp ?? 'Could not determine a LAN address for this machine.',
            ),
            $this->result(
                'Web server',
                PHP_SAPI === 'cli' ? 'warning' : 'ok',
                (string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
            ),
            $this->result(
                'Local scope',
                SyncSetting::get('scope_code') !== null ? 'ok' : 'warning',
                SyncSetting::get('scope_code') !== null
                    ? ucfirst(str_replace('_', ' ', (string) SyncSetting::get('scope_type'))).' '.SyncSetting::get('scope_code')
                    : 'No local scope is recorded yet.',
            ),
            $this->result(
                'Metadata database',
                $databaseOk ? 'ok' : 'error',
                $databaseOk ? 'The local database is reachable.' : 'The local database is not reachable.',
            ),
            $this->databaseUpdates(),
            $this->result(
                'Central configuration',
                $this->client->isEnrolled() ? 'ok' : 'warning',
                $this->client->isEnrolled()
                    ? 'A central URL and server token are stored.'
                    : 'Enroll this server to configure central synchronization.',
            ),
            $this->result(
                'Pending uploads',
                SyncOperation::query()->pending()->count() > 0 ? 'warning' : 'ok',
                SyncOperation::query()->pending()->count().' operation(s) waiting to upload.',
            ),
            $this->result(
                'Last snapshot',
                SyncSetting::get('last_snapshot_at') !== null ? 'ok' : 'warning',
                (string) (SyncSetting::get('last_snapshot_at') ?? 'No snapshot has been downloaded yet.'),
            ),
            $this->result(
                'Reference data',
                SyncSetting::get('reference_version') !== null ? 'ok' : 'warning',
                SyncSetting::get('reference_version') !== null
                    ? 'Imported at central revision '.SyncSetting::get('reference_version').'.'
                    : 'Locations and trainings have not been imported yet.',
            ),
        ];

        if ($checkCentral && $this->client->isEnrolled()) {
            try {
                $response = $this->client->compatibility();

                $checks[] = $this->result(
                    'Central connectivity',
                    $response->successful() ? 'ok' : 'error',
                    $response->successful()
                        ? 'The central server responded successfully.'
                        : 'The central server returned HTTP '.$response->status().'.',
                );
            } catch (Throwable $exception) {
                $checks[] = $this->result('Central connectivity', 'error', $exception->getMessage());
            }
        }

        return $checks;
    }

    private function localIp(string $appUrl): ?string
    {
        $host = parse_url($appUrl, PHP_URL_HOST);

        if (is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false && $host !== '127.0.0.1' && $host !== '::1') {
            return $host;
        }

        $address = gethostbyname((string) gethostname());

        return filter_var($address, FILTER_VALIDATE_IP) !== false && $address !== '127.0.0.1'
            ? $address
            : null;
    }

    private function databaseOk(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Database updates that this installation has not applied yet. A server
     * whose code was replaced without running the migrations fails on pages
     * that need the newer tables.
     *
     * @return array{label: string, status: string, detail: string}
     */
    private function databaseUpdates(): array
    {
        $pending = ReportErrorReporter::pendingMigrations();

        if ($pending === []) {
            return $this->result('Database updates', 'ok', 'All database updates are applied.');
        }

        return $this->result(
            'Database updates',
            'warning',
            count($pending).' update(s) pending: '.implode(', ', array_slice($pending, 0, 3))
                .(count($pending) > 3 ? '...' : ''),
        );
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function result(string $label, string $status, string $detail): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
