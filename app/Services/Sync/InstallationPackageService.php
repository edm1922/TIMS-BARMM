<?php

namespace App\Services\Sync;

use App\Models\OfflineServer;
use App\Models\OfflineServerUpdate;
use App\Services\AuditLogger;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class InstallationPackageService
{
    /**
     * Directories copied into an offline installation package.
     *
     * @var array<int, string>
     */
    private const DEFAULT_DIRECTORIES = [
        'app',
        'bootstrap',
        'config',
        'database',
        'public',
        'resources',
        'routes',
        'scripts',
        'vendor',
    ];

    /**
     * Individual files copied into an offline installation package.
     *
     * @var array<int, string>
     */
    private const DEFAULT_FILES = [
        'artisan',
        'composer.json',
        'composer.lock',
        '.env.example',
    ];

    /**
     * Path fragments that are never packaged. They hold secrets, generated
     * data, backups, or machine-specific state.
     *
     * @var array<int, string>
     */
    private const DENY_FRAGMENTS = [
        '.git/',
        '.github/',
        '.idea/',
        '.vscode/',
        'node_modules/',
        'storage/app/private/packages/',
        'storage/app/private/backups/',
        'storage/app/private/offline-updates/',
        'storage/app/private/updates/',
        'storage/framework/cache/data/',
        'storage/framework/sessions/',
        'storage/framework/testing/',
        'storage/framework/views/',
        'storage/logs/',
        'tests/',
        'database/*.sqlite',
        // These caches are machine-specific and can carry this installation's
        // database credentials. They must never override the .env written by
        // the offline installer.
        'bootstrap/cache/config.php',
        'bootstrap/cache/packages.php',
        'bootstrap/cache/services.php',
        'bootstrap/cache/routes-v7.php',
        'bootstrap/cache/events.php',
        'bootstrap/cache/schedule.php',
    ];

    /**
     * File extensions that are never packaged.
     *
     * @var array<int, string>
     */
    private const DENY_EXTENSIONS = [
        'env',
        'key',
        'pem',
        'pfx',
        'p12',
        'log',
        'sqlite',
        'sqlite3',
    ];

    /**
     * Name of the file that serializes package builds.
     */
    private const LOCK_FILE = '.build.lock';

    /**
     * Name of the file that records the progress of the running build.
     */
    private const STATUS_FILE = '.build-status.json';

    /**
     * Number of files processed between two progress reports.
     */
    private const PROGRESS_INTERVAL = 25;

    /**
     * Seconds a build may stay queued before it is treated as failed.
     */
    private const QUEUE_TIMEOUT = 60;

    /**
     * Largest file that is read into memory to be hashed and archived in a
     * single pass. Anything bigger is streamed by ZipArchive so the request
     * never holds more than one such file in memory.
     */
    private const BUFFERED_FILE_LIMIT = 4194304;

    public function __construct(private readonly Filesystem $files) {}

    /**
     * Build an offline installation package.
     *
     * @param  (callable(array<string, mixed>): void)|null  $progress  Called while the archive is written.
     * @return array{filename: string, path: string, size: int, sha256: string, files: int, created_at: string}
     */
    public function build(?string $deviceLabel = null, ?string $note = null, ?callable $progress = null): array
    {
        $directory = $this->packageDirectory();

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $slug = $this->slug($deviceLabel);
        $filename = sprintf(
            'tims-barmm-offline-%s-%s-%s.zip',
            $slug,
            now()->format('Ymd-His'),
            Str::lower(Str::random(8)),
        );
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        $lock = $this->acquireLock($directory);

        try {
            $this->removePartialFiles($directory);

            return $this->writePackage($path, $filename, $deviceLabel, $note, $progress);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Write the archive to a temporary file and move it into place only once
     * it is complete. A failed build therefore never leaves a partial package
     * that looks complete to the administrator, and it never disturbs the
     * packages that were generated earlier.
     *
     * @param  (callable(array<string, mixed>): void)|null  $progress
     * @return array{filename: string, path: string, size: int, sha256: string, files: int, created_at: string}
     */
    private function writePackage(string $path, string $filename, ?string $deviceLabel, ?string $note, ?callable $progress = null): array
    {
        $temporaryPath = $path.'.partial';

        $zip = new ZipArchive;

        if ($zip->open($temporaryPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The installation package could not be created.');
        }

        $checksums = [];
        $count = 0;
        $collected = $this->collectEntries();

        // The .env.example shipped in the package is generated so that it
        // carries this Central Server's address as the trusted bootstrap URL.
        // The on-disk copy is replaced, never duplicated.
        $collected['files'] = array_values(array_filter(
            $collected['files'],
            fn (array $entry): bool => $entry['relative'] !== '.env.example',
        ));

        $total = count($collected['files']) + 1;
        $totalBytes = array_sum(array_column($collected['files'], 'size'));
        $processedBytes = 0;
        $startedAt = microtime(true);

        $report = function (string $current) use (&$count, &$processedBytes, $total, $totalBytes, $progress, $startedAt): void {
            if ($progress === null) {
                return;
            }

            $progress([
                'processed_files' => $count,
                'total_files' => $total,
                'bytes_processed' => $processedBytes,
                'total_bytes' => $totalBytes,
                'current_file' => $current,
                'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
            ]);
        };

        try {
            foreach ($collected['directories'] as $relativeDirectory) {
                $zip->addEmptyDir($relativeDirectory);
            }

            foreach ($collected['files'] as $entry) {
                $checksums[$entry['relative']] = $this->addFile($zip, $entry['absolute'], $entry['relative']);
                $count++;
                $processedBytes += $entry['size'];

                if ($count % self::PROGRESS_INTERVAL === 0) {
                    $report($entry['relative']);
                }
            }

            $envExample = $this->packagedEnvExample();
            $checksums['.env.example'] = hash('sha256', $envExample);
            $zip->addFromString('.env.example', $envExample);
            $count++;
            // The generated .env.example is the last entry counted in $total,
            // so the build only reaches total_files here: without this final
            // report the status would keep the last interval mark instead of
            // the completed count.
            $report('.env.example');

            $zip->addFromString('SHA256SUMS.txt', $this->checksumManifest($checksums));
            $zip->addFromString('INSTALL.txt', $this->installInstructions($deviceLabel, $note));
        } catch (Throwable $exception) {
            @$zip->close();
            @unlink($temporaryPath);

            throw $exception;
        }

        if (! $zip->close()) {
            @unlink($temporaryPath);

            throw new RuntimeException('The installation package could not be finalized.');
        }

        if (! @rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException('The installation package could not be moved into the package directory.');
        }

        $this->prune();

        return [
            'filename' => $filename,
            'path' => $path,
            'size' => (int) $this->files->size($path),
            'sha256' => (string) hash_file('sha256', $path),
            'files' => $count,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Collect every file that belongs in the package before any of them is
     * written. Knowing the full set up front is what allows the build to
     * report a real percentage instead of an unbounded spinner.
     *
     * @return array{directories: array<int, string>, files: array<int, array{relative: string, absolute: string, size: int}>}
     */
    private function collectEntries(): array
    {
        $directories = [];
        $files = [];

        foreach ($this->directories() as $relativeDirectory) {
            $absolute = base_path($relativeDirectory);

            if (! $this->files->isDirectory($absolute)) {
                continue;
            }

            $directories[] = rtrim($relativeDirectory, '/\\');

            foreach ($this->packageFiles($absolute) as $absolutePath) {
                $relative = $this->relativePath($absolutePath);

                if (! $this->shouldInclude($relative)) {
                    continue;
                }

                $files[] = [
                    'relative' => $relative,
                    'absolute' => $absolutePath,
                    'size' => (int) @filesize($absolutePath),
                ];
            }
        }

        foreach ($this->filesToInclude() as $relative) {
            $absolute = base_path($relative);

            if (! $this->files->isFile($absolute) || ! $this->shouldInclude($relative)) {
                continue;
            }

            $files[] = [
                'relative' => $relative,
                'absolute' => $absolute,
                'size' => (int) @filesize($absolute),
            ];
        }

        return ['directories' => $directories, 'files' => $files];
    }

    /**
     * Generated packages, newest first.
     *
     * @return array<int, array{filename: string, path: string, size: int, created_at: int}>
     */
    public function packages(): array
    {
        $directory = $this->packageDirectory();

        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $packages = [];

        foreach ($this->files->files($directory) as $file) {
            if ($file->getExtension() !== 'zip') {
                continue;
            }

            $packages[] = [
                'filename' => $file->getFilename(),
                'path' => $file->getPathname(),
                'size' => (int) $file->getSize(),
                'created_at' => (int) $file->getMTime(),
            ];
        }

        usort($packages, fn (array $a, array $b) => $b['created_at'] <=> $a['created_at']);

        return $packages;
    }

    /**
     * Publish a generated package so that offline installations can fetch it.
     *
     * The central installation builds and publishes to itself, so it registers
     * the package locally instead of calling its own API (it has no server
     * token to authenticate with). An offline installation publishes upstream
     * through the sync API using its enrollment token.
     *
     * @param  array{filename: string, path: string, size: int, sha256: string, files: int}  $package
     */
    public function publish(array $package, string $label, ?string $centralUrl = null): void
    {
        $client = new SyncClient;
        $client->setCentralUrl($centralUrl);

        if (! $client->isEnrolled()) {
            $this->registerLocally($package, $label);

            AuditLogger::log('enroll_server.package_published', 'enroll_server', null, [
                'filename' => $package['filename'],
                'size' => $package['size'],
                'files' => $package['files'],
                'sha256' => $package['sha256'],
                'device_label' => $label,
                'destination' => 'local',
            ]);

            return;
        }

        $multipart = [
            [
                'name' => 'package',
                'contents' => fopen($package['path'], 'r'),
                'filename' => $package['filename'],
                'headers' => [
                    'Content-Type' => 'application/zip',
                ],
            ],
            [
                'name' => 'label',
                'contents' => $label,
            ],
            [
                'name' => 'size',
                'contents' => (string) $package['size'],
            ],
            [
                'name' => 'sha256',
                'contents' => $package['sha256'],
            ],
            [
                'name' => 'files',
                'contents' => (string) $package['files'],
            ],
        ];

        $response = $client->multipartRequest()->post('/api/sync/v1/update-package', $multipart);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to publish package to central server: '.$response->body());
        }

        AuditLogger::log('enroll_server.package_published', 'enroll_server', null, [
            'filename' => $package['filename'],
            'size' => $package['size'],
            'files' => $package['files'],
            'sha256' => $package['sha256'],
            'device_label' => $label,
            'destination' => 'central',
        ]);
    }

    /**
     * Register a package for every active offline server on this installation.
     *
     * @param  array{filename: string, path: string, size: int, sha256: string, files: int}  $package
     */
    private function registerLocally(array $package, string $label): int
    {
        $directory = storage_path('app/private/offline-updates');
        $this->files->ensureDirectoryExists($directory);

        $target = $directory.DIRECTORY_SEPARATOR.$package['filename'];

        if ($package['path'] !== $target && $this->files->isFile($package['path'])) {
            $this->files->copy($package['path'], $target);
        }

        $servers = OfflineServer::query()
            ->where('status', OfflineServer::STATUS_ACTIVE)
            ->get();

        foreach ($servers as $server) {
            OfflineServerUpdate::query()->updateOrCreate(
                [
                    'offline_server_id' => $server->id,
                    'filename' => $package['filename'],
                ],
                [
                    'path' => 'offline-updates/'.$package['filename'],
                    'size' => $package['size'],
                    'sha256' => $package['sha256'],
                    'files' => $package['files'],
                    'label' => $label,
                    'status' => 'available',
                ],
            );
        }

        return $servers->count();
    }

    public function path(string $filename): ?string
    {
        $safe = basename($filename);

        if ($safe !== $filename || ! str_ends_with(strtolower($safe), '.zip')) {
            return null;
        }

        $path = $this->packageDirectory().DIRECTORY_SEPARATOR.$safe;

        return $this->files->isFile($path) ? $path : null;
    }

    public function delete(string $filename): bool
    {
        $path = $this->path($filename);

        if ($path === null) {
            return false;
        }

        $this->files->delete($path);

        return true;
    }

    /**
     * Environment checks shown before a package is generated.
     *
     * @return array<int, array{label: string, status: string, detail: string}>
     */
    public function prerequisites(): array
    {
        $phpOk = PHP_VERSION_ID >= 80200;
        $extensions = ['pdo_mysql', 'openssl', 'mbstring', 'tokenizer', 'ctype', 'json', 'fileinfo', 'xml', 'zip'];
        $missing = array_values(array_filter(
            $extensions,
            fn (string $extension) => ! extension_loaded($extension),
        ));
        $vendorPresent = $this->files->isFile(base_path('vendor/autoload.php'));
        $envExamplePresent = $this->files->isFile(base_path('.env.example'));
        $scriptsPresent = $this->files->isFile(base_path('scripts/setup-offline.ps1'));
        $centralUuid = trim((string) config('sync.central_server_uuid'));
        $freeSpace = @disk_free_space(base_path());

        return [
            [
                'label' => 'PHP 8.2 or newer',
                'status' => $phpOk ? 'ok' : 'error',
                'detail' => 'This server runs PHP '.PHP_VERSION.'. The package itself must be installed on PHP 8.2+.',
            ],
            [
                'label' => 'Required PHP extensions',
                'status' => $missing === [] ? 'ok' : 'error',
                'detail' => $missing === []
                    ? 'All extensions required by the application and the package builder are loaded.'
                    : 'Missing on this server: '.implode(', ', $missing).'.',
            ],
            [
                'label' => 'Composer dependencies',
                'status' => $vendorPresent ? 'ok' : 'warning',
                'detail' => $vendorPresent
                    ? 'vendor/ is present and will be copied into the package.'
                    : 'vendor/ is missing, so the package will not contain dependencies. Run composer install first.',
            ],
            [
                'label' => 'Configuration template',
                'status' => $envExamplePresent ? 'ok' : 'error',
                'detail' => $envExamplePresent
                    ? '.env.example will be copied so the installer can create a local .env.'
                    : '.env.example is missing and cannot be packaged.',
            ],
            [
                'label' => 'Setup scripts',
                'status' => $scriptsPresent ? 'ok' : 'warning',
                'detail' => $scriptsPresent
                    ? 'scripts/setup-offline.ps1 will be copied into the package.'
                    : 'scripts/setup-offline.ps1 is missing from the package.',
            ],
            [
                'label' => 'Central server identity',
                'status' => $centralUuid !== '' ? 'ok' : 'error',
                'detail' => $centralUuid !== ''
                    ? 'Central identity: '.$centralUuid
                    : 'CENTRAL_SERVER_UUID is blank. Set a stable UUID in .env before building packages, otherwise batch/beneficiary origins collide.',
            ],
            [
                'label' => 'Free disk space',
                'status' => $freeSpace === false ? 'warning' : 'ok',
                'detail' => $freeSpace === false
                    ? 'Free disk space could not be determined.'
                    : number_format((float) $freeSpace / 1048576, 0).' MB available on the server.',
            ],
        ];
    }

    public function packageDirectory(): string
    {
        $configured = config('sync.installation_package.directory');

        return is_string($configured) && trim($configured) !== ''
            ? rtrim($configured, '/\\')
            : storage_path('app/private/packages');
    }

    /**
     * Absolute path of the file that records the progress of the running build.
     */
    public function statusPath(): string
    {
        return $this->packageDirectory().DIRECTORY_SEPARATOR.self::STATUS_FILE;
    }

    /**
     * Record the state of a build so the browser can follow it.
     *
     * @param  array<string, mixed>  $status
     */
    public function writeStatus(array $status): void
    {
        $directory = $this->packageDirectory();

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $status = array_merge($this->rawStatus(), $status);

        $this->files->put(
            $this->statusPath(),
            json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            true,
        );
    }

    /**
     * The state of the last build, or a neutral status when none was started.
     *
     * @return array<string, mixed>
     */
    public function readStatus(): array
    {
        return $this->normalizeStatus($this->rawStatus());
    }

    /**
     * The stored status without the recovery rules applied.
     *
     * @return array<string, mixed>
     */
    private function rawStatus(): array
    {
        $path = $this->statusPath();

        if (! $this->files->isFile($path)) {
            return $this->defaultStatus();
        }

        $decoded = json_decode((string) $this->files->get($path), true);

        return is_array($decoded) ? array_merge($this->defaultStatus(), $decoded) : $this->defaultStatus();
    }

    /**
     * Whether a build is currently running on this server.
     */
    public function isBuildRunning(): bool
    {
        $status = $this->readStatus();

        return in_array($status['state'], ['queued', 'running'], true);
    }

    /**
     * Turn a build that never started or that died into a failed build, so the
     * administrator is never left watching a progress bar that cannot move.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function normalizeStatus(array $status): array
    {
        $state = (string) ($status['state'] ?? 'idle');

        if ($state === 'running') {
            $pid = (int) ($status['pid'] ?? 0);

            if ($pid > 0 && ! $this->processIsAlive($pid)) {
                $status['state'] = 'failed';
                $status['error'] = 'The package build stopped before it finished. Start it again.';
                $status['finished_at'] = $status['finished_at'] ?? now()->toIso8601String();
            }
        }

        if ($state === 'queued') {
            $started = is_string($status['started_at']) ? strtotime($status['started_at']) : false;

            if ($started !== false && (time() - $started) >= self::QUEUE_TIMEOUT) {
                $status['state'] = 'failed';
                $status['error'] = 'The package build did not start. Start it again.';
                $status['finished_at'] = $status['finished_at'] ?? now()->toIso8601String();
            }
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultStatus(): array
    {
        return [
            'state' => 'idle',
            'label' => null,
            'note' => null,
            'processed_files' => 0,
            'total_files' => 0,
            'bytes_processed' => 0,
            'total_bytes' => 0,
            'current_file' => null,
            'elapsed_seconds' => 0,
            'started_at' => null,
            'finished_at' => null,
            'filename' => null,
            'size' => null,
            'sha256' => null,
            'files' => null,
            'error' => null,
            'pid' => null,
        ];
    }

    /**
     * Whether a process with the given identifier is still running.
     */
    private function processIsAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return $this->windowsProcessIsAlive($pid);
    }

    /**
     * Windows has no posix extension, so the task list is inspected instead.
     */
    private function windowsProcessIsAlive(int $pid): bool
    {
        $output = @shell_exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL');

        if (! is_string($output)) {
            // The probe itself is unavailable (restricted host, disabled
            // shell functions). Trust the recorded state instead of declaring
            // a live build dead and letting a second build start.
            return true;
        }

        return str_contains($output, (string) $pid);
    }

    /**
     * @return array<int, string>
     */
    private function directories(): array
    {
        $configured = config('sync.installation_package.directories');

        $directories = is_array($configured) && $configured !== []
            ? array_values($configured)
            : self::DEFAULT_DIRECTORIES;

        if (! config('sync.installation_package.include_vendor', true)) {
            $directories = array_values(array_filter(
                $directories,
                fn (string $directory): bool => $directory !== 'vendor',
            ));
        }

        return $directories;
    }

    /**
     * @return array<int, string>
     */
    private function filesToInclude(): array
    {
        $configured = config('sync.installation_package.files');

        return is_array($configured) && $configured !== []
            ? array_values($configured)
            : self::DEFAULT_FILES;
    }

    private function shouldInclude(string $relative): bool
    {
        $normalized = str_replace('\\', '/', $relative);

        if ($normalized === '.env.example') {
            return true;
        }

        // .htaccess files carry the mod_rewrite rules Laravel needs, so they
        // must survive the dot-file filter.
        if (basename($normalized) === '.htaccess') {
            return true;
        }

        foreach (self::DENY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return false;
            }
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));

        if (in_array($extension, self::DENY_EXTENSIONS, true)) {
            return false;
        }

        $basename = basename($normalized);

        if (str_starts_with($basename, '.env')) {
            return false;
        }

        return true;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', base_path()), '/');
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, $root.'/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return ltrim(str_replace($root, '', $normalized), '/');
    }

    /**
     * List every file below a directory.
     *
     * The native walk replaces the framework file finder because the finder
     * sorts and wraps every entry it finds, which dominated the request on
     * installations that ship the full dependency tree. Dot files and dot
     * directories are skipped exactly as the finder skipped them, so the
     * packaged file set is unchanged.
     *
     * @return array<int, string>
     */
    protected function packageFiles(string $directory): array
    {
        $paths = [];
        $pending = [$directory];

        while ($pending !== []) {
            $current = array_pop($pending);
            $entries = @scandir($current);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                // Dot files are skipped, but .htaccess must be packaged: it
                // carries the mod_rewrite rules Laravel needs for clean URLs.
                if (str_starts_with($entry, '.') && $entry !== '.htaccess') {
                    continue;
                }

                $path = $current.DIRECTORY_SEPARATOR.$entry;

                if (is_dir($path)) {
                    if (! is_link($path)) {
                        $pending[] = $path;
                    }

                    continue;
                }

                if (is_file($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * Add one file to the archive and return its SHA-256 checksum.
     *
     * A file that fits in the buffer limit is read once: the same bytes are
     * hashed and archived, so the package builder never reads a file twice.
     */
    private function addFile(ZipArchive $zip, string $absolutePath, string $relative): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('The file "'.$relative.'" could not be added to the installation package.');
        }

        $size = @filesize($absolutePath);

        if ($size !== false && $size <= self::BUFFERED_FILE_LIMIT) {
            $contents = @file_get_contents($absolutePath);

            if ($contents !== false) {
                if (! $zip->addFromString($relative, $contents)) {
                    throw new RuntimeException('The file "'.$relative.'" could not be added to the installation package.');
                }

                $this->preserveTimestamp($zip, $relative, $absolutePath);

                return hash('sha256', $contents);
            }
        }

        if (! $zip->addFile($absolutePath, $relative)) {
            throw new RuntimeException('The file "'.$relative.'" could not be added to the installation package.');
        }

        return (string) hash_file('sha256', $absolutePath);
    }

    /**
     * Keep the original modification time of the archived file.
     */
    private function preserveTimestamp(ZipArchive $zip, string $relative, string $absolutePath): void
    {
        if (! method_exists($zip, 'setMtimeName')) {
            return;
        }

        $mtime = @filemtime($absolutePath);

        if ($mtime !== false) {
            @$zip->setMtimeName($relative, $mtime);
        }
    }

    /**
     * Take the exclusive build lock.
     *
     * Two administrators pressing the button at the same time would otherwise
     * read and compress the same files twice at once, which slows both
     * requests down and can leave them fighting over the same archive name.
     *
     * @return resource
     */
    private function acquireLock(string $directory)
    {
        $lock = @fopen($directory.DIRECTORY_SEPARATOR.self::LOCK_FILE, 'c');

        if ($lock === false) {
            throw new RuntimeException('The installation package directory could not be locked.');
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new RuntimeException('Another installation package is already being generated. Wait for it to finish, then try again.');
        }

        return $lock;
    }

    /**
     * @param  resource  $lock
     */
    private function releaseLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Remove archives left behind by an interrupted build.
     *
     * The build lock is held while this runs, so no other build can own
     * these files.
     */
    private function removePartialFiles(string $directory): void
    {
        if (! $this->files->isDirectory($directory)) {
            return;
        }

        foreach ($this->files->files($directory) as $file) {
            if (str_ends_with($file->getFilename(), '.partial')) {
                $this->files->delete($file->getPathname());
            }
        }
    }

    /**
     * @param  array<string, string>  $checksums
     */
    private function checksumManifest(array $checksums): string
    {
        ksort($checksums);

        $lines = [];

        foreach ($checksums as $relative => $checksum) {
            $lines[] = $checksum.'  '.$relative;
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function installInstructions(?string $deviceLabel, ?string $note): string
    {
        $label = $deviceLabel !== null && trim($deviceLabel) !== '' ? trim($deviceLabel) : 'Unnamed installation';

        $lines = [
            'TIMS-BARMM OFFLINE INSTALLATION PACKAGE',
            '=========================================',
            '',
            'Installation label : '.$label,
            'Built at           : '.now()->format('Y-m-d H:i:s'),
            'Package version    : '.config('app.version', '1.0.0'),
            'Protocol version   : '.config('sync.protocol_version', 1),
            'Schema version     : '.config('sync.schema_version', 1),
        ];

        if ($note !== null && trim($note) !== '') {
            $lines[] = 'Note               : '.trim($note);
        }

        // A loopback APP_URL cannot be reached from another device. Say so in
        // the package itself instead of letting enrollment fail on the device.
        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (is_string($appHost) && in_array(strtolower($appHost), ['localhost', '127.0.0.1', '::1'], true)) {
            $lines[] = '';
            $lines[] = 'WARNING: CENTRAL SERVER ADDRESS';
            $lines[] = '------------------------------';
            $lines[] = '  This package was built while the central APP_URL was '.$appUrl.',';
            $lines[] = '  which is a loopback address. On another device it points at the';
            $lines[] = '  device itself, so enrollment will fail. Set APP_URL in the central';
            $lines[] = "  .env to the central server's LAN address and rebuild this package";
            $lines[] = '  before installing it.';
        }

        $lines[] = '';
        $lines[] = 'WHAT IS IN THIS PACKAGE';
        $lines[] = '---------------------';
        $lines[] = '  app/ bootstrap/ config/ database/ public/ resources/ routes/';
        $lines[] = '  scripts/ vendor/ artisan composer.json .env.example';
        $lines[] = '';
        $lines[] = 'This package intentionally excludes .env files, keys, logs, backups,';
        $lines[] = 'generated caches, and the git history. No credential is embedded.';
        $lines[] = '';
        $lines[] = 'HOW TO INSTALL (Windows with XAMPP)';
        $lines[] = '--------------------------------';
        $lines[] = '  1. Install XAMPP on the target device and start Apache and MySQL.';
        $lines[] = '  2. Copy this folder to C:\xampp\htdocs\TIMS-BARMM';
        $lines[] = '  3. Open PowerShell as Administrator in this folder and run:';
        $lines[] = '       powershell -ExecutionPolicy Bypass -File .\scripts\setup-offline.ps1 -SkipChecksumVerification';
        $lines[] = '     No URLs, database passwords, or admin credentials are prompted for.';
        $lines[] = '     The LAN/hotspot address and subnet are detected automatically.';
        $lines[] = '     Add -Subnet "x.x.x.x/yy" or -AppUrl only if that detection is wrong.';
        $lines[] = '     The application is served at http://<this-PC-LAN-IP>/ (no path suffix).';
        $lines[] = '     The script refuses to overwrite an existing populated database.';
        $lines[] = '     On an existing installation, use -Repair to apply pending migrations';
        $lines[] = '     only. It leaves data, .env, Apache, and firewall settings unchanged.';
        $lines[] = '     Use -Reinstall only for a deliberate recovery, which backs up first.';
        $lines[] = '     -SkipChecksumVerification skips the per-file checksum pass. Remove it';
        $lines[] = '     to verify every packaged file (the installer reports its progress).';
        $lines[] = '  4. The script opens the application in the default browser when finished.';
        $lines[] = '  5. On first visit the browser shows the First-time setup page. Enter the';
        $lines[] = '     enrollment token, create the Manager account, and choose the area.';
        $lines[] = '     The trusted Central Server address is already in .env.example as';
        $lines[] = '     SYNC_BOOTSTRAP_URL and is verified before it is used.';
        $lines[] = '';
        $lines[] = 'VERIFYING THE PACKAGE';
        $lines[] = '--------------------';
        $lines[] = '  SHA256SUMS.txt lists the SHA-256 checksum of every packaged file.';
        $lines[] = '  The install command above skips the automatic per-file check, so verify';
        $lines[] = '  manually when the package travelled over removable media or a network:';
        $lines[] = '       Get-FileHash -Algorithm SHA256 .\app\Models\User.php';
        $lines[] = '  and compare the result with the matching line in SHA256SUMS.txt.';
        $lines[] = '';
        $lines[] = 'TIMS-BARMM is a system of record for offline installations.';
        $lines[] = 'Never expose an offline installation to the public internet.';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * The .env.example carried in the package. It is the on-disk template with
     * SYNC_BOOTSTRAP_URL set to this Central Server's own address, so the
     * offline installer never has to type a Central Server URL. No credential
     * or secret is ever written here.
     */
    private function packagedEnvExample(): string
    {
        $path = base_path('.env.example');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $bootstrap = rtrim((string) config('app.url'), '/');

        if (preg_match('/^SYNC_BOOTSTRAP_URL=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^SYNC_BOOTSTRAP_URL=.*$/m', 'SYNC_BOOTSTRAP_URL='.$bootstrap, $contents);
        } else {
            $contents = rtrim($contents).PHP_EOL.'SYNC_BOOTSTRAP_URL='.$bootstrap.PHP_EOL;
        }

        return $contents;
    }

    private function slug(?string $deviceLabel): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $deviceLabel));

        $slug = trim($slug, '-');

        return $slug === '' ? 'installation' : substr($slug, 0, 40);
    }

    private function prune(): void
    {
        $this->removePartialFiles($this->packageDirectory());

        $limit = (int) config('sync.installation_package.max_packages', 10);

        if ($limit < 1) {
            return;
        }

        foreach (array_slice($this->packages(), $limit) as $package) {
            $this->files->delete($package['path']);
        }
    }
}
