<?php

namespace Tests\Feature;

use App\Models\OfflineServer;
use App\Models\OfflineServerUpdate;
use App\Models\SyncSetting;
use App\Models\User;
use App\Services\Sync\AppUpdateService;
use App\Services\Sync\InstallationPackageService;
use App\Services\Sync\SyncClient;
use App\Services\Sync\TokenService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AppUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const FILENAME = 'tims-barmm-offline-update.zip';

    protected function setUp(): void
    {
        parent::setUp();

        SyncSetting::flushCache();
        $this->deletePackages();
    }

    protected function tearDown(): void
    {
        $this->deletePackages();

        parent::tearDown();
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_MANAGER]);
    }

    private function configureOffline(): void
    {
        config([
            'sync.instance_mode' => 'offline',
            'sync.local_server_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);

        SyncSetting::flushCache();
    }

    private function configureEnrolled(): void
    {
        SyncSetting::set('central_url', 'https://central.test');
        SyncSetting::set('server_uuid', '22222222-2222-4222-8222-222222222222');
        SyncSetting::setEncrypted('server_token', 'tims_sync_test_token');
        SyncSetting::flushCache();
    }

    private function packagePath(): string
    {
        return storage_path('app/private/updates/'.self::FILENAME);
    }

    private function deletePackages(): void
    {
        $files = new Filesystem;

        if ($files->isDirectory(storage_path('app/private/updates'))) {
            $files->deleteDirectory(storage_path('app/private/updates'));
        }

        $files->delete(storage_path('app/private/offline-updates/'.self::FILENAME));
        $files->delete(storage_path('app/'.self::FILENAME.'.source.zip'));
        $files->delete(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE));
    }

    /**
     * @return array{filename: string, path: string, size: int, sha256: string, files: int}
     */
    private function fakeBuiltPackage(): array
    {
        $source = storage_path('app/'.self::FILENAME.'.source.zip');
        (new Filesystem)->ensureDirectoryExists(dirname($source));
        file_put_contents($source, 'PK-fake-update-body');

        return [
            'filename' => self::FILENAME,
            'path' => $source,
            'size' => (int) filesize($source),
            'sha256' => (string) hash_file('sha256', $source),
            'files' => 3,
        ];
    }

    public function test_publishing_on_the_central_server_registers_the_package_for_active_servers(): void
    {
        config(['sync.instance_mode' => 'online']);
        SyncSetting::flushCache();

        $active = OfflineServer::factory()->create();
        $revoked = OfflineServer::factory()->revoked()->create();

        $package = $this->fakeBuiltPackage();

        app(InstallationPackageService::class)->publish($package, 'Offline 2.0.0');

        $this->assertDatabaseHas('offline_server_updates', [
            'offline_server_id' => $active->id,
            'filename' => self::FILENAME,
            'status' => 'available',
            'label' => 'Offline 2.0.0',
        ]);
        $this->assertDatabaseMissing('offline_server_updates', [
            'offline_server_id' => $revoked->id,
        ]);
        $this->assertFileExists(storage_path('app/private/offline-updates/'.self::FILENAME));
    }

    public function test_publishing_from_an_offline_server_uploads_the_package_as_multipart(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        Http::fake([
            'https://central.test/api/sync/v1/update-package' => Http::response(['data' => []], 201),
        ]);

        app(InstallationPackageService::class)->publish($this->fakeBuiltPackage(), 'Offline 2.0.0');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://central.test/api/sync/v1/update-package'
                && $request->method() === 'POST'
                && str_contains($request->body(), 'name="package"');
        });
    }

    public function test_central_accepts_a_published_package_upload(): void
    {
        $data = $this->enroll();

        $file = UploadedFile::fake()->create(self::FILENAME, 5, 'application/zip');

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->post('/api/sync/v1/update-package', [
                'package' => $file,
                'label' => 'Offline 2.0.0',
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'files' => 3,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('offline_server_updates', [
            'filename' => self::FILENAME,
            'status' => 'available',
        ]);
        $this->assertTrue(Storage::disk('private')->exists('offline-updates/'.self::FILENAME));
    }

    public function test_central_serves_a_published_package_for_download(): void
    {
        $data = $this->enroll();
        $server = OfflineServer::query()->firstOrFail();

        $body = 'PK-central-package';

        Storage::disk('private')->put('offline-updates/'.self::FILENAME, $body);

        OfflineServerUpdate::query()->create([
            'offline_server_id' => $server->id,
            'filename' => self::FILENAME,
            'path' => 'offline-updates/'.self::FILENAME,
            'size' => strlen($body),
            'sha256' => hash('sha256', $body),
            'files' => 3,
            'label' => 'Offline 2.0.0',
            'status' => 'available',
        ]);

        $response = $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->get('/api/sync/v1/app-update/download/'.self::FILENAME);

        $response->assertOk();
        $response->assertDownload(self::FILENAME);
        $this->assertSame($body, $response->streamedContent());
    }

    public function test_central_check_update_returns_a_published_package(): void
    {
        $data = $this->enroll();
        $server = OfflineServer::query()->firstOrFail();

        OfflineServerUpdate::query()->create([
            'offline_server_id' => $server->id,
            'filename' => self::FILENAME,
            'path' => 'offline-updates/'.self::FILENAME,
            'size' => 1024,
            'sha256' => str_repeat('c', 64),
            'files' => 10,
            'label' => 'Offline 2.0.0',
            'status' => 'available',
        ]);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->getJson('/api/sync/v1/app-update/check')
            ->assertOk()
            ->assertJsonPath('data.has_update', true)
            ->assertJsonPath('data.filename', self::FILENAME)
            ->assertJsonPath('data.latest_version', 'Offline 2.0.0');
    }

    public function test_a_newly_enrolled_server_inherits_the_latest_available_update(): void
    {
        $first = $this->enroll();
        $firstServer = OfflineServer::query()->firstOrFail();

        Storage::disk('private')->put('offline-updates/'.self::FILENAME, 'PK-central-package');

        OfflineServerUpdate::query()->create([
            'offline_server_id' => $firstServer->id,
            'filename' => self::FILENAME,
            'path' => 'offline-updates/'.self::FILENAME,
            'size' => 18,
            'sha256' => hash('sha256', 'PK-central-package'),
            'files' => 3,
            'label' => 'Offline 2.0.0',
            'status' => 'available',
        ]);

        $second = $this->enroll();
        $secondServer = OfflineServer::query()->latest('id')->firstOrFail();

        $this->withHeader((string) config('sync.server_token_header'), $second['server_token'])
            ->getJson('/api/sync/v1/app-update/check')
            ->assertOk()
            ->assertJsonPath('data.has_update', true)
            ->assertJsonPath('data.filename', self::FILENAME);

        $this->assertDatabaseHas('offline_server_updates', [
            'offline_server_id' => $secondServer->id,
            'filename' => self::FILENAME,
            'status' => 'available',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function enroll(): array
    {
        $issued = app(TokenService::class)->generateEnrollmentToken();

        $response = $this->postJson('/api/sync/v1/enroll', [
            'enrollment_token' => $issued['token'],
            'name' => 'Lanao del Sur Offline',
            'app_version' => '1.0.0',
            'protocol_version' => 1,
            'schema_version' => 1,
            'scope_type' => 'province',
            'scope_code' => '1900702000',
        ]);

        $response->assertCreated();

        return $response->json('data');
    }

    public function test_check_reports_no_update_when_the_central_server_has_nothing(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        Http::fake([
            'https://central.test/api/sync/v1/app-update/check' => Http::response([
                'data' => ['has_update' => false, 'current_version' => '1.0.0'],
            ]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('sync.local.app-update.check'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status');

        $status = app(AppUpdateService::class)->status();

        $this->assertFalse($status['has_update']);
        $this->assertFalse(app(AppUpdateService::class)->isReady());
    }

    public function test_check_downloads_and_verifies_a_published_package(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $body = 'PK-fake-update-body';
        $sha = hash('sha256', $body);

        Http::fake([
            'https://central.test/api/sync/v1/app-update/check' => Http::response([
                'data' => [
                    'has_update' => true,
                    'filename' => self::FILENAME,
                    'size' => strlen($body),
                    'sha256' => $sha,
                    'latest_version' => 'Offline 2.0.0',
                ],
            ]),
            'https://central.test/api/sync/v1/app-update/download/*' => Http::response($body, 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('sync.local.app-update.check'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('status', 'Update Offline 2.0.0 downloaded and verified. Click Apply Update to install it.');

        $this->assertFileExists($this->packagePath());
        $this->assertSame($body, file_get_contents($this->packagePath()));

        $status = app(AppUpdateService::class)->status();

        $this->assertTrue($status['verified']);
        $this->assertSame($sha, $status['sha256']);
        $this->assertTrue(app(AppUpdateService::class)->isReady());
    }

    public function test_check_rejects_a_package_that_fails_checksum_verification(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $body = 'PK-fake-update-body';

        Http::fake([
            'https://central.test/api/sync/v1/app-update/check' => Http::response([
                'data' => [
                    'has_update' => true,
                    'filename' => self::FILENAME,
                    'size' => strlen($body),
                    'sha256' => str_repeat('a', 64),
                    'latest_version' => 'Offline 2.0.0',
                ],
            ]),
            'https://central.test/api/sync/v1/app-update/download/*' => Http::response($body),
        ]);

        $this->actingAs($this->manager())
            ->post(route('sync.local.app-update.check'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('error', 'The downloaded package failed its SHA256 verification.');

        $this->assertFileDoesNotExist($this->packagePath());
        $this->assertFalse(app(AppUpdateService::class)->isReady());
    }

    public function test_apply_refuses_to_run_without_a_verified_package(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $this->actingAs($this->manager())
            ->post(route('sync.local.app-update.apply'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('error', 'No verified update is ready. Check for updates first.');

        $this->assertFalse(app(AppUpdateService::class)->isApplying());
    }

    public function test_apply_uses_the_updater_shipped_inside_the_package(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $path = $this->packagePath();
        (new Filesystem)->ensureDirectoryExists(dirname($path));

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('scripts/update-offline.ps1', 'Write-Phase "Test" 50');
        $zip->close();

        SyncSetting::set(AppUpdateService::STATUS_KEY, (string) json_encode([
            'has_update' => true,
            'label' => 'Offline 2.0.0',
            'filename' => self::FILENAME,
            'size' => filesize($path),
            'sha256' => hash_file('sha256', $path),
            'verified' => true,
            'downloaded_at' => now()->toIso8601String(),
        ]));
        SyncSetting::flushCache();

        $prepared = app(AppUpdateService::class)->prepareApply();

        $this->assertSame('ready', $prepared['state']);
        $this->assertContains(storage_path('app/private/updates/update-offline.ps1'), $prepared['command']);
        $this->assertSame(
            'Write-Phase "Test" 50',
            file_get_contents(storage_path('app/private/updates/update-offline.ps1')),
        );
    }

    public function test_the_elevated_launcher_scripts_are_valid_powershell(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows only.');
        }

        $service = app(AppUpdateService::class);
        [$worker, $launcher] = (new \ReflectionMethod($service, 'elevationScripts'))->invoke($service, [
            'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', 'C:\\path with space\\update-offline.ps1',
        ], storage_path('logs/app-update.log'));

        $this->assertStringContainsString("'C:\\path with space\\update-offline.ps1'", $worker);
        $this->assertStringContainsString('-Verb RunAs', $launcher);
        $this->assertStringContainsString('app-update-progress.json', $launcher);

        $checker = storage_path('app/private/updates/parse-check.ps1');
        (new Filesystem)->ensureDirectoryExists(dirname($checker));
        file_put_contents($checker, '$t=$null;$e=$null;[void][System.Management.Automation.Language.Parser]::ParseFile($args[0],[ref]$t,[ref]$e); if($e.Count -gt 0){$e|%{$_.Message}; exit 1}');

        foreach (['worker' => $worker, 'launcher' => $launcher] as $name => $script) {
            $path = storage_path("app/private/updates/test-{$name}.ps1");
            file_put_contents($path, $script);

            exec(
                'powershell -NoProfile -ExecutionPolicy Bypass -File '.escapeshellarg($checker).' '.escapeshellarg($path),
                $output,
                $code,
            );

            $this->assertSame(0, $code, $name.' script has parse errors: '.implode(' ', $output));
        }
    }

    public function test_apply_launches_the_updater_and_acknowledges_the_central_server(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $body = 'PK-fake-update-body';
        $sha = hash('sha256', $body);

        (new Filesystem)->ensureDirectoryExists(dirname($this->packagePath()));
        file_put_contents($this->packagePath(), $body);

        SyncSetting::set(AppUpdateService::STATUS_KEY, (string) json_encode([
            'has_update' => true,
            'label' => 'Offline 2.0.0',
            'filename' => self::FILENAME,
            'size' => strlen($body),
            'sha256' => $sha,
            'verified' => true,
            'downloaded_at' => now()->toIso8601String(),
        ]));
        SyncSetting::flushCache();

        Http::fake([
            'https://central.test/api/sync/v1/app-update/ack' => Http::response([
                'data' => ['message' => 'Update acknowledged.'],
            ]),
        ]);

        $service = new class(app(Filesystem::class)) extends AppUpdateService
        {
            /** @var array<int, array<int, string>> */
            public array $launched = [];

            protected function launch(array $command, string $log): void
            {
                $this->launched[] = $command;
            }
        };

        $result = $service->apply(app(SyncClient::class));

        $this->assertSame('started', $result['state']);
        $this->assertCount(1, $service->launched);
        $this->assertContains('-SourceZip', $service->launched[0]);
        $this->assertContains((string) $service->packagePath(self::FILENAME), $service->launched[0]);
        $this->assertTrue($service->isApplying());
        $this->assertSame($sha, $service->status()['applied_sha256']);

        $progress = $service->progress();
        $this->assertSame('starting', $progress['state']);
        $this->assertSame(1, $progress['percent']);
        $this->assertFileExists($service->progressPath());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://central.test/api/sync/v1/app-update/ack'
            && $request['sha256'] === $sha);
    }

    public function test_a_completed_progress_record_clears_the_update_lock(): void
    {
        $this->configureOffline();

        SyncSetting::set(AppUpdateService::LOCK_KEY, (string) json_encode([
            'started_at' => now()->toIso8601String(),
        ]));

        (new Filesystem)->ensureDirectoryExists(dirname(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE)));
        file_put_contents(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE), (string) json_encode([
            'state' => 'completed',
            'percent' => 100,
            'step' => 'Update complete',
        ]));

        SyncSetting::flushCache();

        $service = app(AppUpdateService::class);

        $this->assertSame('completed', $service->progress()['state']);
        $this->assertFalse($service->isApplying());
        $this->assertNull(SyncSetting::get(AppUpdateService::LOCK_KEY));
    }

    public function test_a_silent_updater_is_reported_as_failed_and_releases_the_lock(): void
    {
        $this->configureOffline();

        SyncSetting::set(AppUpdateService::LOCK_KEY, (string) json_encode([
            'started_at' => now()->subHour()->toIso8601String(),
        ]));

        (new Filesystem)->ensureDirectoryExists(dirname(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE)));
        file_put_contents(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE), (string) json_encode([
            'state' => 'running',
            'percent' => 40,
            'step' => 'Replacing application files',
            'updated_at' => now()->subHour()->toIso8601String(),
        ]));

        SyncSetting::flushCache();

        $service = app(AppUpdateService::class);
        $progress = $service->progress();

        $this->assertSame('failed', $progress['state']);
        $this->assertFalse($service->isApplying());
    }

    public function test_the_status_endpoint_keeps_working_in_maintenance_mode(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $this->artisan('down')->assertExitCode(0);

        try {
            $this->actingAs($this->manager())
                ->getJson(route('sync.local.app-update.status'))
                ->assertOk()
                ->assertJsonPath('progress.state', 'idle');

            $this->actingAs($this->manager())
                ->get(route('sync.local.index'))
                ->assertOk()
                ->assertSee('Application Update');
        } finally {
            $this->artisan('up');
        }
    }

    public function test_the_update_status_endpoint_reports_progress(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        (new Filesystem)->ensureDirectoryExists(dirname(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE)));
        file_put_contents(storage_path('app/private/'.AppUpdateService::PROGRESS_FILE), (string) json_encode([
            'state' => 'running',
            'percent' => 60,
            'step' => 'Installing dependencies',
            'updated_at' => now()->toIso8601String(),
        ]));

        $this->actingAs($this->manager())
            ->getJson(route('sync.local.app-update.status'))
            ->assertOk()
            ->assertJsonPath('progress.state', 'running')
            ->assertJsonPath('progress.percent', 60)
            ->assertJsonPath('progress.step', 'Installing dependencies');
    }

    public function test_checking_with_no_new_update_keeps_the_current_build_visible(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        SyncSetting::set(AppUpdateService::STATUS_KEY, (string) json_encode([
            'has_update' => true,
            'label' => 'Offline 1.2.3',
            'filename' => 'tims-barmm-offline-offline-1-2-3-20261031-120000-abcdef12.zip',
            'size' => 1024,
            'sha256' => str_repeat('a', 64),
            'applied_at' => now()->subHour()->toIso8601String(),
            'applied_sha256' => str_repeat('a', 64),
            'applied_label' => 'Offline 1.2.3',
        ]));
        SyncSetting::flushCache();

        Http::fake([
            'https://central.test/api/sync/v1/app-update/check' => Http::response([
                'data' => ['has_update' => false, 'current_version' => '1.0.0'],
            ]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('sync.local.app-update.check'))
            ->assertRedirect(route('sync.local.index'));

        $status = app(AppUpdateService::class)->status();

        $this->assertFalse($status['has_update']);
        $this->assertSame('Offline 1.2.3', $status['label']);
        $this->assertSame('Offline 1.2.3', $status['applied_label']);

        $this->actingAs($this->manager())
            ->get(route('sync.local.index'))
            ->assertOk()
            ->assertSee('Current build')
            ->assertSee('Offline 1.2.3');
    }

    public function test_the_setup_page_shows_the_application_update_panel(): void
    {
        $this->configureOffline();
        $this->configureEnrolled();

        $this->actingAs($this->manager())
            ->get(route('sync.local.index'))
            ->assertOk()
            ->assertSee('Application Update')
            ->assertSee('Check for Updates');
    }

    public function test_a_stale_update_lock_does_not_block_future_updates(): void
    {
        $this->configureOffline();

        SyncSetting::set(AppUpdateService::LOCK_KEY, (string) json_encode([
            'started_at' => now()->subHours(2)->toIso8601String(),
        ]));
        SyncSetting::flushCache();

        $this->assertFalse(app(AppUpdateService::class)->isApplying());
        $this->assertNull(SyncSetting::get(AppUpdateService::LOCK_KEY));
    }

    public function test_staff_cannot_trigger_an_application_update(): void
    {
        $this->configureOffline();

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($staff)
            ->post(route('sync.local.app-update.check'))
            ->assertForbidden();
    }

    public function test_check_fails_cleanly_when_the_server_is_not_enrolled(): void
    {
        $this->configureOffline();

        $this->actingAs($this->manager())
            ->post(route('sync.local.app-update.check'))
            ->assertRedirect(route('sync.local.index'))
            ->assertSessionHas('error', 'This server is not enrolled with a central server yet.');
    }

    public function test_central_ack_marks_the_package_as_applied(): void
    {
        $data = $this->enroll();
        $server = OfflineServer::query()->firstOrFail();

        $update = OfflineServerUpdate::query()->create([
            'offline_server_id' => $server->id,
            'filename' => self::FILENAME,
            'path' => 'offline-updates/'.self::FILENAME,
            'size' => 1024,
            'sha256' => str_repeat('b', 64),
            'files' => 10,
            'label' => 'Offline 2.0.0',
            'status' => 'available',
        ]);

        $this->withHeader((string) config('sync.server_token_header'), $data['server_token'])
            ->postJson('/api/sync/v1/app-update/ack', ['sha256' => str_repeat('b', 64)])
            ->assertOk();

        $this->assertSame('applied', $update->fresh()->status);
    }
}
