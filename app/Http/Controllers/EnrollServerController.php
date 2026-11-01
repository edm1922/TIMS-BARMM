<?php

namespace App\Http\Controllers;

use App\Models\OfflineServer;
use App\Services\AuditLogger;
use App\Services\Sync\InstallationPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EnrollServerController extends Controller
{
    public function index(InstallationPackageService $packages): View
    {
        return view('enroll-server.index', [
            'prerequisites' => $packages->prerequisites(),
            'packages' => $packages->packages(),
            'servers' => OfflineServer::query()->latest('id')->get(),
            'buildStatus' => $packages->readStatus(),
            'bootstrapWarning' => $this->bootstrapWarning(),
        ]);
    }

    /**
     * A package built while APP_URL is a loopback address tells every device
     * to enroll against itself. Warn before the administrator generates one.
     */
    private function bootstrapWarning(): ?string
    {
        $appUrl = (string) config('app.url');
        $host = parse_url($appUrl, PHP_URL_HOST);

        if (! is_string($host) || ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        $path = (string) (parse_url($appUrl, PHP_URL_PATH) ?: '');

        return 'The central APP_URL is '.$appUrl.', so every package built now tells the offline device to enroll against localhost — which resolves to the device itself and fails. Set APP_URL in the central .env to this server\'s LAN address (for example http://192.168.1.122'.$path.') and then rebuild the package.';
    }

    /**
     * Start generating an offline installation package for a target device.
     *
     * The archive holds the whole dependency tree, so it is built by a
     * background process. The browser follows the build through the status
     * endpoint instead of waiting on one long request.
     */
    public function store(Request $request, InstallationPackageService $packages): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            // The confirmation dialog no longer asks for a label or note: a
            // build-time label is generated below. Older clients may still
            // send these fields, which are accepted for compatibility.
            'device_label' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'action' => ['nullable', 'in:download,publish'],
        ]);

        if ($packages->isBuildRunning()) {
            $message = 'Another installation package is already being generated. Wait for it to finish, then try again.';

            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'error' => $message], 422);
            }

            return back()->withInput()->withErrors([
                'device_label' => $message,
            ]);
        }

        $label = trim((string) ($validated['device_label'] ?? ''));

        if ($label === '') {
            $label = 'Offline installation package '.now()->format('Y-m-d H:i');
        }

        $note = $validated['note'] ?? null;
        $action = $validated['action'] ?? 'download';

        $packages->writeStatus([
            'state' => 'queued',
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
            'pid' => null,
        ]);

        if (! config('sync.installation_package.background', true) || ! $this->spawnBuild($request, $label, $note, $action)) {
            try {
                $package = $packages->build($label, $note);
            } catch (Throwable $exception) {
                $packages->writeStatus([
                    'state' => 'failed',
                    'label' => $label,
                    'note' => $note,
                    'action' => $action,
                    'finished_at' => now()->toIso8601String(),
                    'error' => $exception->getMessage(),
                ]);

                $message = 'The package could not be generated: '.$exception->getMessage();

                if ($request->expectsJson()) {
                    return response()->json(['status' => 'error', 'error' => $message], 422);
                }

                return back()->withInput()->withErrors([
                    'device_label' => $message,
                ]);
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
            ]);

            if ($action === 'publish') {
                try {
                    $packages->publish($package, $label);
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

                    $message = 'The package was built but could not be published: '.$exception->getMessage();

                    if ($request->expectsJson()) {
                        return response()->json(['status' => 'error', 'error' => $message], 422);
                    }

                    return back()->withInput()->withErrors(['device_label' => $message]);
                }
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'completed',
                    'filename' => $package['filename'],
                    'size' => $package['size'],
                    'sha256' => $package['sha256'],
                    'files' => $package['files'],
                ]);
            }

            return redirect()
                ->route('enroll-server.index')
                ->with('status', 'Installation package generated: '.$package['filename'].($action === 'publish' ? ' and published for offline servers.' : '. Download it and transfer it to the target device.'));
        }

        if ($request->expectsJson()) {
            return response()->json(['status' => 'queued']);
        }

        return redirect()
            ->route('enroll-server.index')
            ->with('status', 'The installation package is being built. This page reports the progress automatically.');
    }

    /**
     * Report the progress of the running package build.
     */
    public function status(InstallationPackageService $packages): JsonResponse
    {
        return response()->json($packages->readStatus());
    }

    /**
     * Download a generated installation package.
     */
    public function download(string $file, InstallationPackageService $packages): BinaryFileResponse
    {
        $path = $packages->path($file);

        abort_if($path === null, 404);

        AuditLogger::log('enroll_server.package_downloaded', 'enroll_server', null, [
            'filename' => basename($path),
        ]);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Remove a generated installation package from the server.
     */
    public function destroy(string $file, InstallationPackageService $packages): RedirectResponse
    {
        if (! $packages->delete($file)) {
            // The file may already have been removed by another tab, a
            // concurrent build prune, or the user double-clicking Delete.
            // Treat it as an idempotent action instead of a raw 404.
            return redirect()
                ->route('enroll-server.index')
                ->with('error', 'That installation package is no longer on the server. Refresh the list and try again.');
        }

        AuditLogger::log('enroll_server.package_deleted', 'enroll_server', null, [
            'filename' => $file,
        ]);

        return redirect()
            ->route('enroll-server.index')
            ->with('status', 'The installation package was deleted.');
    }

    /**
     * Start the background build and detach it from the request.
     *
     * The child process outlives the web request, so the browser is released
     * immediately instead of holding a connection open for the whole archive.
     */
    private function spawnBuild(Request $request, string $label, ?string $note, string $action): bool
    {
        $arguments = [
            'offline-package:build',
            '--label='.$label,
            '--action='.$action,
            '--actor='.$request->user()->getAuthIdentifier(),
            '--ip='.$request->ip(),
        ];

        if ($note !== null && $note !== '') {
            $arguments[] = '--note='.$note;
        }

        $php = $this->phpExecutable();
        $artisan = base_path('artisan');
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $null = $isWindows ? 'NUL' : '/dev/null';
        $log = storage_path('logs/offline-package-build.log');

        $quoted = implode(' ', array_map(
            fn (string $argument): string => escapeshellarg($argument),
            [$php, $artisan, ...$arguments],
        ));

        $command = $isWindows
            ? 'cmd /c start /B "" '.$quoted
            : 'nohup '.$quoted.' >'.$null.' 2>&1 &';

        @file_put_contents(
            $log,
            '['.now()->toIso8601String().'] starting '.$command.PHP_EOL
                .'    PHP_BINARY='.PHP_BINARY.PHP_EOL
                .'    PHP_BINDIR='.PHP_BINDIR.PHP_EOL,
            FILE_APPEND,
        );

        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            return false;
        }

        proc_close($process);

        return true;
    }

    /**
     * Locate the command line PHP binary.
     *
     * PHP_BINARY points at the web server executable when the application runs
     * under the Apache module, so the binary is taken from the PHP install
     * directory instead. The candidate is only accepted when it carries the
     * extensions the build needs, because a PHP binary without the MySQL
     * driver dies before it can write any progress.
     */
    private function phpExecutable(): string
    {
        $candidates = [];

        $binary = PHP_BINARY;

        if (is_string($binary) && $binary !== '') {
            $candidates[] = $binary;
        }

        $ini = php_ini_loaded_file();

        if (is_string($ini) && $ini !== '') {
            $candidates[] = dirname($ini).DIRECTORY_SEPARATOR.'php.exe';
        }

        $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.'php.exe';
        $candidates[] = PHP_BINDIR.DIRECTORY_SEPARATOR.'php-cli.exe';
        $candidates[] = 'C:\\xampp\\php\\php.exe';

        $fallback = null;

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            $fallback ??= $candidate;

            if ($this->phpBinaryHasExtensions($candidate)) {
                return $candidate;
            }
        }

        return $fallback ?? $binary;
    }

    /**
     * Whether the given PHP binary can load the extensions the package
     * builder depends on.
     */
    private function phpBinaryHasExtensions(string $binary): bool
    {
        // Single quotes are used on purpose: escapeshellarg() strips double
        // quotes on Windows, which would break the PHP snippet.
        $code = "echo (int) (extension_loaded('pdo_mysql') && extension_loaded('zip'));";

        $output = @shell_exec(escapeshellarg($binary).' -r '.escapeshellarg($code).' 2>NUL');

        return is_string($output) && trim($output) === '1';
    }
}
