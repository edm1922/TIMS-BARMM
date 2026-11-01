<?php

namespace Tests\Feature;

use App\Models\OfflineServer;
use App\Models\User;
use App\Services\Sync\InstallationPackageService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class InstallationPackageTest extends TestCase
{
    use RefreshDatabase;

    private string $packageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packageDirectory = storage_path('framework/testing/offline-packages-'.Str::random(8));

        config()->set('sync.installation_package.directory', $this->packageDirectory);
        config()->set('sync.installation_package.directories', ['app', 'scripts', 'storage']);
        config()->set('sync.installation_package.files', ['artisan', '.env.example']);
        config()->set('sync.installation_package.background', false);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->packageDirectory)) {
            File::deleteDirectory($this->packageDirectory);
        }

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @return array<int, string>
     */
    private function packageEntries(string $filename): array
    {
        $zip = new ZipArchive;
        $zip->open($this->packageDirectory.'/'.$filename);
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    private function packageArchives(): array
    {
        $archives = [];

        foreach (File::files($this->packageDirectory) as $file) {
            if (str_ends_with($file->getFilename(), '.zip')) {
                $archives[] = $file->getFilename();
            }
        }

        return $archives;
    }

    public function test_the_installation_page_uses_the_shared_sync_dashboard_layout(): void
    {
        $this->actingAs($this->admin())
            ->get(route('enroll-server.index'))
            ->assertOk()
            ->assertSee('Enroll Server')
            ->assertSee('Installation procedure')
            ->assertSee('Server prerequisites')
            ->assertSee('Generated packages')
            ->assertSee('Registered offline installations')
            ->assertSee('id="build-btn"', false)
            ->assertSee('id="build-progress-bar"', false)
            ->assertSee('id="build-package-form"', false);
    }

    public function test_the_offline_installation_page_is_available_to_admins(): void
    {
        $this->actingAs($this->admin())
            ->get(route('enroll-server.index'))
            ->assertOk()
            ->assertSee('Enroll Server');
    }

    public function test_staff_cannot_open_the_offline_installation_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STAFF]))
            ->get(route('enroll-server.index'))
            ->assertForbidden();
    }

    public function test_an_administrator_can_generate_an_installation_package(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), [
                'device_label' => 'Lanao del Sur Laptop 1',
                'note' => 'Rollout batch 1',
            ])
            ->assertRedirect(route('enroll-server.index'))
            ->assertSessionHas('status');

        $files = File::files($this->packageDirectory);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.zip', $files[0]->getFilename());
        $this->assertStringContainsString('lanao-del-sur-laptop-1', $files[0]->getFilename());
    }

    public function test_the_generated_package_contains_the_application_and_the_install_instructions(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Device A'])
            ->assertRedirect(route('enroll-server.index'));

        $filename = File::files($this->packageDirectory)[0]->getFilename();
        $entries = $this->packageEntries($filename);

        $this->assertContains('artisan', $entries);
        $this->assertContains('.env.example', $entries);
        $this->assertContains('INSTALL.txt', $entries);
        $this->assertContains('SHA256SUMS.txt', $entries);
        $this->assertTrue((bool) collect($entries)->first(fn (string $entry) => str_starts_with($entry, 'app/Models/User.php')));
        $this->assertTrue((bool) collect($entries)->first(fn (string $entry) => str_starts_with($entry, 'scripts/setup-offline.ps1')));
    }

    public function test_generated_packages_exclude_cached_config_credentials(): void
    {
        config()->set('sync.installation_package.directories', ['bootstrap']);
        config()->set('sync.installation_package.files', ['.env.example']);

        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Cache Test'])
            ->assertRedirect(route('enroll-server.index'));

        $filename = File::files($this->packageDirectory)[0]->getFilename();
        $entries = $this->packageEntries($filename);

        $this->assertNotContains('bootstrap/cache/config.php', $entries);
        $this->assertNotContains('bootstrap/cache/packages.php', $entries);
        $this->assertNotContains('bootstrap/cache/services.php', $entries);
    }

    public function test_the_generated_package_never_contains_credentials_or_machine_state(): void
    {
        $probeEnv = base_path('storage/.env.probe');
        $probeLog = base_path('storage/logs/probe.log');
        $probeUpdate = base_path('storage/app/private/offline-updates/probe-update.zip');

        File::ensureDirectoryExists(base_path('storage/logs'), 0755, true);
        File::ensureDirectoryExists(dirname($probeUpdate), 0755, true);
        File::put($probeEnv, "DB_PASSWORD=should-never-be-packaged\n");
        File::put($probeLog, 'secret log line');
        File::put($probeUpdate, 'previous update archive');

        try {
            $this->actingAs($this->admin())
                ->post(route('enroll-server.packages.store'), ['device_label' => 'Device B'])
                ->assertRedirect(route('enroll-server.index'));

            $filename = File::files($this->packageDirectory)[0]->getFilename();
            $entries = $this->packageEntries($filename);

            $this->assertNotContains('.env', $entries);
            $this->assertNotContains('storage/.env.probe', $entries);
            $this->assertNotContains('storage/logs/probe.log', $entries);
            $this->assertNotContains('storage/app/private/offline-updates/probe-update.zip', $entries);
            $this->assertFalse((bool) collect($entries)->first(fn (string $entry) => str_starts_with($entry, 'storage/logs/')));
            $this->assertFalse((bool) collect($entries)->first(fn (string $entry) => str_starts_with($entry, '.git/')));
        } finally {
            File::delete($probeEnv);
            File::delete($probeLog);
            File::delete($probeUpdate);
        }
    }

    public function test_the_install_instructions_explain_the_safe_reinstall_procedure(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Device C'])
            ->assertRedirect(route('enroll-server.index'));

        $filename = File::files($this->packageDirectory)[0]->getFilename();

        $zip = new ZipArchive;
        $zip->open($this->packageDirectory.'/'.$filename);
        $instructions = (string) $zip->getFromName('INSTALL.txt');
        $zip->close();

        $this->assertStringContainsString('setup-offline.ps1', $instructions);
        $this->assertStringContainsString('-SkipChecksumVerification', $instructions);
        $this->assertStringContainsString('served at http://<this-PC-LAN-IP>/', $instructions);
        $this->assertStringContainsString('-Repair', $instructions);
        $this->assertStringContainsString('-Reinstall', $instructions);
        $this->assertStringContainsString('SHA256SUMS.txt', $instructions);
        $this->assertStringContainsString('WARNING: CENTRAL SERVER ADDRESS', $instructions);
        $this->assertStringNotContainsString('DB_PASSWORD', $instructions);
    }

    public function test_the_package_page_warns_when_the_central_app_url_is_loopback(): void
    {
        $this->actingAs($this->admin())
            ->get(route('enroll-server.index'))
            ->assertOk()
            ->assertSee('Packages built now will not be able to enroll devices');
    }

    public function test_an_administrator_can_download_a_generated_package(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Device D'])
            ->assertRedirect(route('enroll-server.index'));

        $filename = File::files($this->packageDirectory)[0]->getFilename();

        $response = $this->actingAs($this->admin())
            ->get(route('enroll-server.packages.download', $filename));

        $response->assertOk();
        $this->assertSame(
            realpath($this->packageDirectory.'/'.$filename),
            realpath((string) $response->baseResponse->getFile()->getPathname()),
        );
    }

    public function test_downloading_an_unknown_package_returns_not_found(): void
    {
        $this->actingAs($this->admin())
            ->get(route('enroll-server.packages.download', 'missing-package.zip'))
            ->assertNotFound();
    }

    public function test_a_package_cannot_be_downloaded_through_a_path_traversal(): void
    {
        $this->actingAs($this->admin())
            ->get('/enroll-server/packages/..%2F..%2F.env/download')
            ->assertNotFound();
    }

    public function test_an_administrator_can_delete_a_generated_package(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Device E'])
            ->assertRedirect(route('enroll-server.index'));

        $filename = File::files($this->packageDirectory)[0]->getFilename();

        $this->actingAs($this->admin())
            ->delete(route('enroll-server.packages.destroy', $filename))
            ->assertRedirect(route('enroll-server.index'));

        $this->assertCount(0, File::files($this->packageDirectory));
    }

    public function test_deleting_a_missing_package_is_idempotent(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('enroll-server.packages.destroy', 'already-removed.zip'))
            ->assertRedirect(route('enroll-server.index'))
            ->assertSessionHas('error');
    }

    public function test_a_package_can_be_built_without_typing_a_label(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), [])
            ->assertRedirect(route('enroll-server.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $files = File::files($this->packageDirectory);

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.zip', $files[0]->getFilename());
        $this->assertStringContainsString('offline-installation-package', $files[0]->getFilename());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'enroll_server.package_generated',
        ]);
    }

    public function test_confirm_build_publishes_the_package_for_offline_servers(): void
    {
        $server = OfflineServer::factory()->create(['status' => OfflineServer::STATUS_ACTIVE]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('enroll-server.index'))
            ->assertOk()
            ->assertSee('name="action" value="publish"', false);

        $this->actingAs($admin)
            ->post(route('enroll-server.packages.store'), ['action' => 'publish'])
            ->assertRedirect(route('enroll-server.index'))
            ->assertSessionHas('status');

        $filename = File::files($this->packageDirectory)[0]->getFilename();

        $this->assertDatabaseHas('offline_server_updates', [
            'offline_server_id' => $server->id,
            'filename' => $filename,
            'status' => 'available',
        ]);
    }

    public function test_generating_a_package_is_recorded_in_the_audit_log(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Device F'])
            ->assertRedirect(route('enroll-server.index'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'enroll_server.package_generated',
            'user_id' => auth()->id(),
        ]);
    }

    public function test_the_checksum_manifest_matches_the_files_that_were_packaged(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Manifest'])
            ->assertRedirect(route('enroll-server.index'));

        $archives = $this->packageArchives();
        $this->assertCount(1, $archives);

        $zip = new ZipArchive;
        $zip->open($this->packageDirectory.'/'.$archives[0]);
        $manifest = (string) $zip->getFromName('SHA256SUMS.txt');
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = (string) $zip->getNameIndex($i);
        }

        $lines = array_values(array_filter(
            array_map('trim', explode(PHP_EOL, $manifest)),
            fn (string $line): bool => $line !== '',
        ));

        $fileEntries = array_values(array_filter(
            $entries,
            fn (string $entry): bool => ! str_ends_with($entry, '/'),
        ));

        // Every packaged file is listed, and only the two generated
        // documents are missing from the manifest.
        $this->assertCount(count($fileEntries) - 2, $lines);

        // Hash one archived entry at a time. Holding every file's bytes in
        // memory at once exhausts the suite's 512 MB limit on a full package.
        foreach ($lines as $line) {
            [$expected, $relative] = explode('  ', $line, 2);

            $index = $zip->locateName($relative);
            $this->assertNotFalse($index, $relative.' is missing from the archive.');

            $actual = hash('sha256', (string) $zip->getFromIndex($index));

            $this->assertSame($expected, $actual, $relative.' has the wrong checksum.');
            $this->assertContains($relative, $entries);
        }

        $zip->close();
    }

    public function test_the_packaged_env_example_carries_the_trusted_bootstrap_url(): void
    {
        config()->set('app.url', 'https://central.tims.test');

        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Bootstrap'])
            ->assertRedirect(route('enroll-server.index'));

        $filename = File::files($this->packageDirectory)[0]->getFilename();

        $zip = new ZipArchive;
        $zip->open($this->packageDirectory.'/'.$filename);
        $envExample = (string) $zip->getFromName('.env.example');
        $zip->close();

        $this->assertStringContainsString('SYNC_BOOTSTRAP_URL=https://central.tims.test', $envExample);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $envExample);
        $this->assertMatchesRegularExpression('/^ADMIN_PASSWORD=\s*$/m', $envExample);
    }

    public function test_dot_files_and_dot_directories_are_never_packaged(): void
    {
        $dotFile = base_path('app/.package-probe.txt');
        $dotDirectory = base_path('app/.package-probe-directory');

        File::ensureDirectoryExists($dotDirectory);
        File::put($dotFile, 'probe');
        File::put($dotDirectory.'/probe.txt', 'probe');

        try {
            $this->actingAs($this->admin())
                ->post(route('enroll-server.packages.store'), ['device_label' => 'Dots'])
                ->assertRedirect(route('enroll-server.index'));

            $entries = $this->packageEntries($this->packageArchives()[0]);

            $this->assertFalse((bool) collect($entries)->first(
                fn (string $entry): bool => str_contains($entry, 'package-probe')
            ));
        } finally {
            File::delete($dotFile);
            File::deleteDirectory($dotDirectory);
        }
    }

    public function test_htaccess_files_are_packaged_so_clean_urls_work(): void
    {
        config()->set('sync.installation_package.directories', ['public']);

        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Htaccess'])
            ->assertRedirect(route('enroll-server.index'));

        $entries = $this->packageEntries($this->packageArchives()[0]);

        $this->assertContains('public/.htaccess', $entries,
            'public/.htaccess carries the mod_rewrite rules Laravel needs for clean URLs.');
    }

    public function test_the_package_directory_itself_is_never_packaged(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Recursive'])
            ->assertRedirect(route('enroll-server.index'));

        $entries = $this->packageEntries($this->packageArchives()[0]);

        $this->assertFalse((bool) collect($entries)->first(
            fn (string $entry): bool => str_contains($entry, 'offline-packages-')
        ));
    }

    public function test_two_packages_generated_in_quick_succession_do_not_collide(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'First'])
            ->assertRedirect(route('enroll-server.index'));

        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Second'])
            ->assertRedirect(route('enroll-server.index'));

        $archives = $this->packageArchives();

        $this->assertCount(2, $archives);
        $this->assertNotSame($archives[0], $archives[1]);
    }

    public function test_a_failed_generation_leaves_no_partial_package_and_keeps_the_previous_one(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Survivor'])
            ->assertRedirect(route('enroll-server.index'));

        $existing = $this->packageArchives();
        $this->assertCount(1, $existing);

        $failing = new class(new Filesystem) extends InstallationPackageService
        {
            protected function packageFiles(string $directory): array
            {
                $files = parent::packageFiles($directory);
                $files[] = base_path('app/a-file-that-does-not-exist.php');

                return $files;
            }
        };

        $this->app->instance(InstallationPackageService::class, $failing);

        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Broken'])
            ->assertStatus(302)
            ->assertSessionHasErrors('device_label');

        $this->assertSame($existing, $this->packageArchives());

        foreach (File::files($this->packageDirectory) as $file) {
            $this->assertStringEndsWith('.zip', $file->getFilename());
        }

        // The package that was generated earlier is still complete.
        $entries = $this->packageEntries($existing[0]);
        $this->assertContains('INSTALL.txt', $entries);
        $this->assertContains('SHA256SUMS.txt', $entries);
        $this->assertTrue((bool) collect($entries)->first(
            fn (string $entry): bool => str_starts_with($entry, 'app/Models/User.php')
        ));
    }

    public function test_a_second_generation_is_refused_while_a_build_is_running(): void
    {
        File::ensureDirectoryExists($this->packageDirectory);

        $lock = fopen($this->packageDirectory.'/.build.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->actingAs($this->admin())
                ->post(route('enroll-server.packages.store'), ['device_label' => 'Blocked'])
                ->assertStatus(302)
                ->assertSessionHasErrors('device_label');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertCount(0, $this->packageArchives());
    }

    public function test_temporary_build_artifacts_are_neither_listed_nor_downloadable(): void
    {
        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Artifacts'])
            ->assertRedirect(route('enroll-server.index'));

        $archives = $this->packageArchives();
        $this->assertCount(1, $archives);

        $this->actingAs($this->admin())
            ->get(route('enroll-server.packages.download', $archives[0].'.partial'))
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->get(route('enroll-server.packages.download', '.build.lock'))
            ->assertNotFound();
    }

    public function test_the_build_status_endpoint_reports_the_state_of_the_last_build(): void
    {
        $this->actingAs($this->admin())
            ->get(route('enroll-server.packages.status'))
            ->assertOk()
            ->assertJsonPath('state', 'idle');
    }

    public function test_the_build_status_endpoint_is_not_available_to_staff(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_STAFF]))
            ->get(route('enroll-server.packages.status'))
            ->assertForbidden();
    }

    public function test_a_running_build_refuses_to_start_another_one(): void
    {
        $packages = $this->app->make(InstallationPackageService::class);
        $packages->writeStatus([
            'state' => 'running',
            'label' => 'First',
            'started_at' => now()->toIso8601String(),
            'pid' => getmypid(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('enroll-server.packages.store'), ['device_label' => 'Second'])
            ->assertStatus(302)
            ->assertSessionHasErrors('device_label');

        $this->assertCount(0, $this->packageArchives());
    }

    public function test_the_background_build_records_its_progress_and_completes(): void
    {
        $this->artisan('offline-package:build', ['--label' => 'Background'])
            ->assertSuccessful();

        $status = $this->app->make(InstallationPackageService::class)->readStatus();

        $this->assertSame('completed', $status['state']);
        $this->assertSame('Background', $status['label']);
        $this->assertGreaterThan(0, $status['total_files']);
        $this->assertSame($status['total_files'], $status['processed_files']);
        $this->assertNotNull($status['filename']);
        $this->assertNull($status['error']);

        $archives = $this->packageArchives();
        $this->assertCount(1, $archives);
        $this->assertSame($status['filename'], $archives[0]);
    }

    public function test_a_failed_background_build_is_recorded_as_failed(): void
    {
        $failing = new class(new Filesystem) extends InstallationPackageService
        {
            protected function packageFiles(string $directory): array
            {
                $files = parent::packageFiles($directory);
                $files[] = base_path('app/a-file-that-does-not-exist.php');

                return $files;
            }
        };

        $this->app->instance(InstallationPackageService::class, $failing);

        $this->artisan('offline-package:build', ['--label' => 'Broken'])->assertFailed();

        $status = $failing->readStatus();

        $this->assertSame('failed', $status['state']);
        $this->assertNotNull($status['error']);
        $this->assertCount(0, $this->packageArchives());
    }

    public function test_a_build_that_never_started_is_reported_as_failed(): void
    {
        $packages = $this->app->make(InstallationPackageService::class);
        $packages->writeStatus([
            'state' => 'queued',
            'label' => 'Stalled',
            'started_at' => now()->subMinutes(5)->toIso8601String(),
            'pid' => null,
        ]);

        $status = $packages->readStatus();

        $this->assertSame('failed', $status['state']);
        $this->assertFalse($packages->isBuildRunning());
    }
}
