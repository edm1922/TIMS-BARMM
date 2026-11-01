<?php

namespace App\Services\Sync;

use App\Models\SyncSetting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;
use ZipArchive;

class AppUpdateService
{
    /**
     * Sync setting that keeps the last known update state so the page can
     * show what was checked, downloaded, and applied without re-contacting
     * the central server on every request.
     */
    public const STATUS_KEY = 'app_update_status';

    /**
     * Sync setting that marks an update as currently being applied.
     */
    public const LOCK_KEY = 'app_update_applying';

    /**
     * Progress file written by the updater script. It lives under storage/
     * because the update replaces application code but never storage/.
     */
    public const PROGRESS_FILE = 'app-update-progress.json';

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $raw = SyncSetting::get(self::STATUS_KEY);
        $stored = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (! is_array($stored)) {
            $stored = [];
        }

        return array_merge([
            'has_update' => false,
            'label' => null,
            'filename' => null,
            'size' => null,
            'sha256' => null,
            'checked_at' => null,
            'downloaded_at' => null,
            'verified' => false,
            'applied_at' => null,
            'applied_sha256' => null,
            'applied_label' => null,
            'applied_filename' => null,
            'applied_size' => null,
            'error' => null,
        ], $stored);
    }

    /**
     * True when a verified package is downloaded and ready to apply.
     */
    public function isReady(): bool
    {
        $status = $this->status();

        if (! $status['verified'] || empty($status['filename']) || empty($status['sha256'])) {
            return false;
        }

        if ($status['applied_sha256'] === $status['sha256']) {
            return false;
        }

        $path = $this->packagePath((string) $status['filename']);

        return $path !== null && $this->files->isFile($path);
    }

    public function isApplying(): bool
    {
        $raw = SyncSetting::get(self::LOCK_KEY);

        if ($raw === null) {
            return false;
        }

        $lock = json_decode($raw, true);
        $startedAt = is_array($lock) ? ($lock['started_at'] ?? null) : null;

        // A crashed or killed updater must not lock the page forever.
        if (is_string($startedAt)) {
            try {
                if (Carbon::parse($startedAt)->lt(now()->subMinutes(30))) {
                    SyncSetting::forget(self::LOCK_KEY);

                    return false;
                }
            } catch (Throwable) {
                SyncSetting::forget(self::LOCK_KEY);

                return false;
            }
        }

        return true;
    }

    /**
     * Ask the central server for the newest published package and download
     * it when it has not been applied yet.
     *
     * @return array{state: string, message: string}
     */
    public function check(SyncClient $client): array
    {
        $status = $this->status();
        $status['checked_at'] = now()->toIso8601String();
        $status['error'] = null;

        if (! $client->isEnrolled()) {
            $status['error'] = 'This server is not enrolled with a central server yet.';

            return $this->fail($status);
        }

        $data = $client->checkAppUpdate();

        if ($data === null) {
            $status['error'] = 'The central server could not be reached. Try again when it is available.';

            return $this->fail($status);
        }

        if (! (bool) ($data['has_update'] ?? false)) {
            $status['has_update'] = false;

            // Keep the last known package/build details so the operator can
            // still see the current build after a "no update" check. The
            // published version is simply unchanged.
            $this->store($status);

            return ['state' => 'current', 'message' => 'No new application update is published for this server.'];
        }

        $filename = (string) ($data['filename'] ?? '');
        $sha256 = strtolower((string) ($data['sha256'] ?? ''));
        $size = (int) ($data['size'] ?? 0);
        $label = (string) ($data['latest_version'] ?? $filename);

        if ($filename === '' || ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            $status['error'] = 'The central server returned an incomplete update manifest.';

            return $this->fail($status);
        }

        $path = $this->packagePath($filename);

        if ($path === null) {
            $status['error'] = 'The central server returned an unsafe package name.';

            return $this->fail($status);
        }

        $status = array_merge($status, [
            'has_update' => true,
            'label' => $label,
            'filename' => $filename,
            'size' => $size,
            'sha256' => $sha256,
        ]);

        if ($status['applied_sha256'] === $sha256) {
            $this->store($status);

            return ['state' => 'current', 'message' => 'The latest published package has already been applied to this server.'];
        }

        if ($this->matches($path, $size, $sha256)) {
            $status['verified'] = true;
            $status['downloaded_at'] ??= now()->toIso8601String();
            $this->store($status);

            return ['state' => 'ready', 'message' => 'Update '.$label.' is ready. Click Apply Update when you are ready.'];
        }

        $response = $client->downloadAppUpdate($filename);

        if ($response === null || ! $response->successful()) {
            $status['error'] = 'The update package could not be downloaded from the central server.';

            return $this->fail($status);
        }

        $body = $response->body();

        if ($size > 0 && strlen($body) !== $size) {
            $status['error'] = 'The downloaded package size does not match the manifest.';

            return $this->fail($status);
        }

        if (! hash_equals($sha256, hash('sha256', $body))) {
            $status['error'] = 'The downloaded package failed its SHA256 verification.';

            return $this->fail($status);
        }

        $this->files->ensureDirectoryExists($this->updatesDirectory());
        $this->files->put($path, $body);

        $status['size'] = strlen($body);
        $status['verified'] = true;
        $status['downloaded_at'] = now()->toIso8601String();
        $this->store($status);

        return ['state' => 'ready', 'message' => 'Update '.$label.' downloaded and verified. Click Apply Update to install it.'];
    }

    /**
     * Validate the downloaded package and build the command that applies it.
     *
     * @return array{state: string, message: string, path?: string, command?: array<int, string>}
     */
    public function prepareApply(): array
    {
        $status = $this->status();

        if ($this->isApplying()) {
            return ['state' => 'error', 'message' => 'An update is already being applied on this server.'];
        }

        if (! $status['verified'] || empty($status['filename']) || empty($status['sha256'])) {
            return ['state' => 'error', 'message' => 'No verified update is ready. Check for updates first.'];
        }

        $path = $this->packagePath((string) $status['filename']);

        if ($path === null || ! $this->files->isFile($path)) {
            return ['state' => 'error', 'message' => 'The downloaded package is missing. Check for updates again.'];
        }

        if (! hash_equals((string) $status['sha256'], (string) hash_file('sha256', $path))) {
            return ['state' => 'error', 'message' => 'The downloaded package no longer matches its checksum.'];
        }

        // Prefer the updater shipped inside the package: it is the version
        // designed for this release (and may report progress the installed
        // copy does not). Fall back to the installed script.
        $script = $this->updaterScriptFor($path) ?? base_path('scripts/update-offline.ps1');

        if (! $this->files->isFile($script)) {
            return ['state' => 'error', 'message' => 'The updater script scripts/update-offline.ps1 is missing.'];
        }

        return [
            'state' => 'ready',
            'message' => 'Update is ready to apply.',
            'path' => $path,
            'command' => [
                'powershell.exe',
                '-NoProfile',
                '-ExecutionPolicy', 'Bypass',
                '-File', $script,
                '-SourceZip', $path,
                '-ProjectRoot', base_path(),
                '-XamppRoot', (string) config('sync.xampp_root', 'C:\\xampp'),
            ],
        ];
    }

    /**
     * Apply the downloaded package with the same script administrators run by
     * hand, so backups, maintenance mode, and rollback stay in one place.
     *
     * @return array{state: string, message: string}
     */
    public function apply(SyncClient $client): array
    {
        $prepared = $this->prepareApply();

        if ($prepared['state'] !== 'ready') {
            return ['state' => 'error', 'message' => $prepared['message']];
        }

        $status = $this->status();

        $this->writeProgress('starting', 1, 'Starting the updater…');

        SyncSetting::set(self::LOCK_KEY, (string) json_encode([
            'filename' => $status['filename'],
            'sha256' => $status['sha256'],
            'started_at' => now()->toIso8601String(),
        ]));

        try {
            $this->launch($prepared['command'], storage_path('logs/app-update.log'));
        } catch (Throwable $exception) {
            SyncSetting::forget(self::LOCK_KEY);
            $this->writeProgress('failed', 0, 'Update could not start.', $exception->getMessage());

            return ['state' => 'error', 'message' => 'The update could not be started: '.$exception->getMessage()];
        }

        $status['applied_at'] = now()->toIso8601String();
        $status['applied_sha256'] = $status['sha256'];
        $status['applied_label'] = $status['label'];
        $status['applied_filename'] = $status['filename'];
        $status['applied_size'] = $status['size'];
        $status['error'] = null;
        $this->store($status);

        // Best effort: stop the central server from offering this package again.
        $client->ackAppUpdate((string) $status['sha256']);

        return [
            'state' => 'started',
            'message' => 'Update started. This server may be unavailable for a few minutes while files are replaced and migrations run.',
        ];
    }

    /**
     * Detach the updater from the request so it keeps running after the
     * response is sent. Mirrors the installer build spawner.
     *
     * @param  array<int, string>  $command
     */
    protected function launch(array $command, string $log): void
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $null = $isWindows ? 'NUL' : '/dev/null';

        // The updater requires Administrator (it stops services and writes
        // under the Apache tree), and a web request is never elevated. Ask
        // Windows for an elevated process so the operator sees the UAC prompt
        // instead of a silent exit.
        if ($isWindows && ! $this->isElevated()) {
            $this->launchElevated($command, $log);

            return;
        }

        $quoted = implode(' ', array_map(
            fn (string $argument): string => escapeshellarg($argument),
            $command,
        ));

        $line = $isWindows
            ? 'cmd /c start /B "" '.$quoted
            : 'nohup '.$quoted;

        @file_put_contents(
            $log,
            '['.now()->toIso8601String().'] starting '.$line.PHP_EOL,
            FILE_APPEND,
        );

        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $process = @proc_open($line, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('The updater process could not be started.');
        }

        proc_close($process);
    }

    /**
     * Start a small launcher that asks Windows for an elevated updater.
     *
     * @param  array<int, string>  $command
     */
    private function launchElevated(array $command, string $log): void
    {
        [$workerScript, $script] = $this->elevationScripts($command, $log);

        $directory = storage_path('app/private/updates');
        $this->files->ensureDirectoryExists($directory);
        $this->files->put($directory.'/run-update-elevated.ps1', $workerScript);
        $this->files->put($directory.'/request-elevation.ps1', $script);

        @file_put_contents(
            $log,
            '['.now()->toIso8601String().'] requesting elevation for '.implode(' ', $command).PHP_EOL,
            FILE_APPEND,
        );

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $line = 'cmd /c start /B "" powershell.exe -NoProfile -ExecutionPolicy Bypass -File '.escapeshellarg($directory.'/request-elevation.ps1');

        $process = @proc_open($line, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('The elevated updater could not be started.');
        }

        proc_close($process);
    }

    /**
     * @param  array<int, string>  $command
     * @return array{0: string, 1: string} worker and launcher scripts
     */
    protected function elevationScripts(array $command, string $log): array
    {
        $executable = (string) array_shift($command);
        $quote = fn (string $value): string => "'".str_replace("'", "''", $value)."'";

        // The elevated worker wraps the updater so its output still lands in
        // storage/logs (an elevated process cannot inherit our handles).
        $worker = <<<'PS'
$ErrorActionPreference = 'Continue'
$log = %LOG%
try {
    & %EXE% %ARGS% *>&1 | Tee-Object -FilePath $log -Append
    $code = $LASTEXITCODE
} catch {
    ($_ | Out-String) | Add-Content -LiteralPath $log
    $code = 1
}
('[' + (Get-Date).ToString('o') + '] updater exited with code ' + $code) | Add-Content -LiteralPath $log
PS;

        $workerScript = str_replace(
            ['%LOG%', '%EXE%', '%ARGS%'],
            [$quote($log), $quote($executable), implode(' ', array_map($quote, array_values($command)))],
            $worker,
        );

        $launcher = <<<'PS'
$ErrorActionPreference = 'Stop'
try {
    Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', %WORKER%) -Verb RunAs -WindowStyle Hidden
} catch {
    $payload = [ordered]@{
        state      = 'failed'
        percent    = 0
        step       = 'Update could not start'
        message    = 'Windows refused the elevation request: ' + $_.Exception.Message
        updated_at = (Get-Date).ToString('o')
    }
    [System.IO.File]::WriteAllText(%PROGRESS%, ($payload | ConvertTo-Json -Compress), (New-Object System.Text.UTF8Encoding($false)))
    exit 1
}
PS;

        $script = str_replace(
            ['%WORKER%', '%PROGRESS%'],
            [
                $quote(storage_path('app/private/updates/run-update-elevated.ps1')),
                $quote($this->progressPath()),
            ],
            $launcher,
        );

        return [$workerScript, $script];
    }

    private function isElevated(): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return true;
        }

        if (! function_exists('exec')) {
            return false;
        }

        exec('net session > NUL 2>&1', $output, $code);

        return $code === 0;
    }

    private function matches(string $path, int $size, string $sha256): bool
    {
        if (! $this->files->isFile($path)) {
            return false;
        }

        if ($size > 0 && $this->files->size($path) !== $size) {
            return false;
        }

        return hash_equals($sha256, (string) hash_file('sha256', $path));
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array{state: string, message: string}
     */
    private function fail(array $status): array
    {
        $message = (string) ($status['error'] ?? 'The update check failed.');
        $status['verified'] = false;
        $this->store($status);

        return ['state' => 'error', 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function store(array $status): void
    {
        SyncSetting::set(self::STATUS_KEY, (string) json_encode($status));
    }

    /**
     * Live progress reported by the updater script.
     *
     * @return array{state: string, percent: int, step: string, message: string|null, updated_at: string|null}
     */
    public function progress(): array
    {
        $defaults = [
            'state' => 'idle',
            'percent' => 0,
            'step' => 'Waiting for an update to start.',
            'message' => null,
            'updated_at' => null,
        ];

        $path = $this->progressPath();

        if (! $this->files->isFile($path)) {
            return $defaults;
        }

        $decoded = json_decode(ltrim($this->files->get($path), "\xEF\xBB\xBF"), true);

        if (! is_array($decoded)) {
            return $defaults;
        }

        $progress = array_merge($defaults, $decoded);
        $progress['percent'] = max(0, min(100, (int) $progress['percent']));

        // A running update that stopped reporting is treated as failed so the
        // page does not spin forever after a crash.
        if (in_array($progress['state'], ['starting', 'running'], true) && $this->isStale($progress['updated_at'])) {
            if ($this->isApplying()) {
                SyncSetting::forget(self::LOCK_KEY);
            }

            $progress['state'] = 'failed';
            $progress['message'] = 'The updater stopped reporting progress. Check storage/logs/app-update.log and the backup folder.';

            return $progress;
        }

        // The script cannot clear the lock itself (it may replace the code that
        // understands it), so the first successful poll does it.
        if (in_array($progress['state'], ['completed', 'failed'], true) && $this->isApplying()) {
            SyncSetting::forget(self::LOCK_KEY);
        }

        return $progress;
    }

    private function isStale(mixed $updatedAt): bool
    {
        if (! is_string($updatedAt) || $updatedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($updatedAt)->lt(now()->subMinutes(15));
        } catch (Throwable) {
            return true;
        }
    }

    public function progressPath(): string
    {
        return storage_path('app/private/'.self::PROGRESS_FILE);
    }

    /**
     * Extract the updater script from the package so the version that applies
     * this release is the one shipped with it, not the one already installed.
     */
    private function updaterScriptFor(string $zipPath): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $entry = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (is_string($name) && preg_match('#(^|/)scripts/update-offline\.ps1$#', str_replace('\\', '/', $name))) {
                    $entry = $name;
                    break;
                }
            }

            if ($entry === null) {
                return null;
            }

            $contents = $zip->getFromName($entry);

            if (! is_string($contents) || trim($contents) === '') {
                return null;
            }

            $destination = storage_path('app/private/updates/update-offline.ps1');
            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->put($destination, $contents);

            return $destination;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{state: string, percent: int, step: string, message: string|null, updated_at: string|null}
     */
    private function writeProgress(string $state, int $percent, string $step, ?string $message = null): array
    {
        $progress = [
            'state' => $state,
            'percent' => max(0, min(100, $percent)),
            'step' => $step,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ];

        $this->files->ensureDirectoryExists(dirname($this->progressPath()));
        $this->files->put(
            $this->progressPath(),
            (string) json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return $progress;
    }

    public function packagePath(string $filename): ?string
    {
        $safe = basename($filename);

        if ($safe !== $filename || ! str_ends_with(strtolower($safe), '.zip')) {
            return null;
        }

        return $this->updatesDirectory().DIRECTORY_SEPARATOR.$safe;
    }

    private function updatesDirectory(): string
    {
        return storage_path('app/private/updates');
    }
}
