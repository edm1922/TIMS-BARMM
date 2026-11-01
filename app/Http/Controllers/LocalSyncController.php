<?php

namespace App\Http\Controllers;

use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use App\Services\AuditLogger;
use App\Services\OfflineAuthorization;
use App\Services\Sync\AppUpdateService;
use App\Services\Sync\AuditSyncService;
use App\Services\Sync\GhostBatchCleaner;
use App\Services\Sync\LocalEnrollmentService;
use App\Services\Sync\ScopeService;
use App\Services\Sync\SnapshotDownloadService;
use App\Services\Sync\SyncClient;
use App\Services\Sync\SyncDiagnostics;
use App\Services\Sync\SyncNowService;
use App\Services\Sync\SyncOperationService;
use App\Services\Sync\SyncRunner;
use App\Support\SyncIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class LocalSyncController extends Controller
{
    public function index(SyncClient $client, SyncDiagnostics $diagnostics, OfflineAuthorization $authorization, AppUpdateService $updates, GhostBatchCleaner $ghosts): View
    {
        return view('sync.local.index', [
            'mode' => SyncIdentity::mode(),
            'isOffline' => SyncIdentity::isOffline(),
            'authorization' => [
                'status' => $authorization->status(),
                'authorized_at' => $authorization->authorizedAt()?->toIso8601String(),
                'expires_at' => $authorization->expiresAt()?->toIso8601String(),
                'expired' => $authorization->isExpired(),
                'prepared_at' => $authorization->preparedAt()?->toIso8601String(),
                'prepared' => $authorization->isPrepared(),
            ],
            'settings' => [
                'central_url' => SyncSetting::get('central_url'),
                'server_uuid' => SyncSetting::get('server_uuid'),
                'server_name' => SyncSetting::get('server_name'),
                'scope_type' => SyncSetting::get('scope_type'),
                'scope_code' => SyncSetting::get('scope_code'),
                'enrolled_at' => SyncSetting::get('enrolled_at'),
                'last_snapshot_at' => SyncSetting::get('last_snapshot_at'),
                'last_snapshot_revision' => SyncSetting::get('last_snapshot_revision'),
                'last_sync_at' => SyncSetting::get('last_sync_at'),
                'last_sync_error' => SyncSetting::get('last_sync_error'),
                'last_download_revision' => SyncSetting::get('last_download_revision'),
            ],
            'enrolled' => $client->isEnrolled(),
            'bootstrapUrl' => $this->bootstrapUrl(),
            'bootstrapIsLoopback' => $this->bootstrapIsLoopback(),
            'audit' => [
                'pending' => app(AuditSyncService::class)->pendingCount(),
                'last_sync_at' => SyncSetting::get('last_audit_sync_at'),
                'error' => SyncSetting::get('last_audit_sync_error'),
            ],
            'queue' => [
                'pending' => SyncOperation::query()->pending()->count(),
                'failed' => SyncOperation::query()->where('status', SyncOperation::STATUS_FAILED)->count(),
                'conflicts' => SyncOperation::query()->where('status', SyncOperation::STATUS_CONFLICT)->count(),
                'awaiting_review' => SyncOperation::query()->where('status', SyncOperation::STATUS_AWAITING_REVIEW)->count(),
                'synced' => SyncOperation::query()->where('status', SyncOperation::STATUS_SYNCED)->count(),
                'rejected' => SyncOperation::query()->where('status', SyncOperation::STATUS_REJECTED)->count(),
                'retryable' => SyncOperation::query()->rejectedByFormerScope()->count(),
            ],
            'operations' => SyncOperation::query()->latest('id')->limit(20)->get(),
            'ghosts' => $ghosts->preview(),
            'checks' => $diagnostics->run(),
            'appUpdate' => [
                'status' => $updates->status(),
                'progress' => $updates->progress(),
                'ready' => $updates->isReady(),
                'applying' => $updates->isApplying(),
            ],
        ]);
    }

    public function enroll(Request $request, LocalEnrollmentService $enrollment): RedirectResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:255'],
            'server_name' => ['required', 'string', 'max:120'],
        ]);

        $centralUrl = $this->bootstrapUrl();

        if ($centralUrl === null) {
            return back()->withInput()->withErrors([
                'enrollment_token' => 'This installation was not provisioned with a trusted Central Server address. Reinstall from a package that includes SYNC_BOOTSTRAP_URL, or set it in .env.',
            ]);
        }

        $scopeType = (string) (SyncSetting::get('scope_type') ?: ScopeService::SCOPE_TYPE_REGION);
        $scopeCode = trim((string) SyncSetting::get('scope_code'));

        if ($scopeType === ScopeService::SCOPE_TYPE_REGION || $scopeCode === '') {
            $scopeType = ScopeService::SCOPE_TYPE_REGION;
            $scopeCode = ScopeService::REGION_SENTINEL;
        }

        try {
            $result = $enrollment->enroll(
                $centralUrl,
                $data['enrollment_token'],
                $data['server_name'],
                $scopeType,
                $scopeCode,
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['enrollment_token' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync.local.index')
            ->with('status', 'Server enrolled successfully. Local UUID: '.$result['server_uuid']);
    }

    /**
     * The deployment-level trusted Central Server address. It is written into
     * the installation package, so the operator never types it per device.
     */
    private function bootstrapUrl(): ?string
    {
        $url = SyncSetting::get('bootstrap_url') ?: config('sync.bootstrap_url');
        $url = is_string($url) ? trim($url) : '';

        return $url === '' ? null : rtrim($url, '/');
    }

    /**
     * A loopback Central Server address can only ever reach this device, never
     * the real central installation. The page warns instead of letting the
     * operator retry enrollment against itself.
     */
    private function bootstrapIsLoopback(): bool
    {
        $url = $this->bootstrapUrl();

        if ($url === null) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Archive active beneficiaries that are still filed in a locally deleted
     * release batch. These "ghosts" no longer exist in that form on the central
     * server, so sync cannot remove them; cleaning reconciles the local totals.
     */
    public function cleanGhostBatches(GhostBatchCleaner $cleaner): RedirectResponse
    {
        $result = $cleaner->clean();

        if ($result['archived'] === 0) {
            return back()->with('success', 'No ghost records found — nothing to clean.');
        }

        return back()->with(
            'success',
            $result['archived'].' ghost '.($result['archived'] === 1 ? 'record was' : 'records were')
            .' archived. Beneficiary totals now match the central server.',
        );
    }

    public function download(SnapshotDownloadService $snapshots): RedirectResponse
    {
        try {
            $result = $snapshots->download();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['download' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync.local.index')
            ->with('status', sprintf(
                'Snapshot downloaded: %s beneficiaries across %s page(s), revision %s.',
                number_format($result['beneficiaries']),
                $result['pages'],
                $result['revision_to'],
            ));
    }

    /**
     * Ask the central server for a newly published application package and
     * download it when it has not been applied yet.
     */
    public function checkAppUpdate(SyncClient $client, AppUpdateService $updates): RedirectResponse
    {
        $result = $updates->check($client);

        return redirect()
            ->route('sync.local.index')
            ->with($result['state'] === 'error' ? 'error' : 'status', $result['message']);
    }

    /**
     * Run the offline updater against the verified package. The updater keeps
     * its own backup, maintenance mode, migrations, and rollback.
     */
    public function applyAppUpdate(SyncClient $client, AppUpdateService $updates): RedirectResponse
    {
        $result = $updates->apply($client);

        return redirect()
            ->route('sync.local.index')
            ->with($result['state'] === 'error' ? 'error' : 'status', $result['message']);
    }

    /**
     * Progress for the application update, polled by the setup page while the
     * updater runs. The updater enables maintenance mode, so callers must
     * tolerate 503 responses while the server is being replaced.
     */
    public function appUpdateStatus(AppUpdateService $updates): JsonResponse
    {
        $progress = $updates->progress();

        return response()->json([
            'ready' => $updates->isReady(),
            'applying' => $updates->isApplying(),
            'progress' => $progress,
        ]);
    }

    public function run(SyncRunner $runner): RedirectResponse
    {
        try {
            $result = $runner->run();
        } catch (Throwable $exception) {
            return back()->withErrors(['sync' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync.local.index')
            ->with('status', sprintf(
                'Sync complete: %s uploaded, %s downloaded, %s conflict(s).',
                number_format($result['upload']['applied']),
                number_format($result['download']['applied']),
                number_format($result['download']['conflicts']),
            ));
    }

    /**
     * Requeue operations that only failed because of the former location-scope
     * rule. They keep their operation UUID, so the central server applies them
     * idempotently.
     */
    public function retryRejected(SyncRunner $runner): RedirectResponse
    {
        $count = $runner->retryScopeRejectedOperations();

        if ($count === 0) {
            return redirect()
                ->route('sync.local.index')
                ->with('status', 'No rejected operations are eligible for retry.');
        }

        return redirect()
            ->route('sync.local.index')
            ->with('status', number_format($count).' rejected operation(s) returned to the queue. Run Sync Now to upload them.');
    }

    public function syncNow(SyncNowService $syncNow): RedirectResponse
    {
        $result = $syncNow->execute();

        // The full step-by-step outcome is flashed for the summary panel on
        // the setup page. The plain status/error flashes are preserved so the
        // generic alerts (and existing behavior) keep working unchanged.
        session()->flash('sync_result', $result);

        $alertType = $result['status'] === 'success' ? 'status' : ($result['status'] === 'warning' ? 'status' : 'error');
        $message = $result['message'];

        return redirect()
            ->route('sync.local.index')
            ->with($alertType, $message);
    }

    /**
     * Redeem a reauthorization token issued by the central administrator.
     *
     * Only the authorization window changes: audit history, sync history, and
     * pending operations are preserved.
     */
    public function reauthorize(Request $request, SyncClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'reauthorization_token' => ['required', 'string', 'max:255'],
        ]);

        try {
            $response = $client->reauthorize((string) $validated['reauthorization_token']);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'reauthorization_token' => 'The central server could not be reached: '.$exception->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            $message = (string) $response->json(
                'error.message',
                'The central server rejected the reauthorization token.',
            );

            return back()->withInput()->withErrors(['reauthorization_token' => $message]);
        }

        $data = (array) $response->json('data', []);

        SyncSetting::set('authorized_at', (string) ($data['authorized_at'] ?? ''));
        SyncSetting::set('authorization_expires_at', (string) ($data['authorization_expires_at'] ?? ''));
        SyncSetting::set('authorization_status', (string) ($data['authorization_status'] ?? 'active'));
        SyncSetting::flushCache();

        AuditLogger::log('offline_server.reauthorized', 'offline_server', null, [
            'server_uuid' => SyncSetting::get('server_uuid'),
            'authorization_expires_at' => $data['authorization_expires_at'] ?? null,
        ]);

        return redirect()
            ->route('sync.local.index')
            ->with('status', 'This installation is authorized until '.($data['authorization_expires_at'] ?? 'the new expiry date').'.');
    }

    /**
     * Prepare this installation for an offline encoding session.
     *
     * The preparation sequence:
     *  1. Authenticate with central server and verify device status
     *  2. Redeem the preparation token to get new time-limited authorization
     *  3. Upload pending operations
     *  4. Push audit trail and encoder registry to the central server
     *  5. Download the latest authorized data snapshot
     *  6. Verify snapshot completeness
     *  7. Mark device as prepared for deployment
     *
     * If any step fails, the authorization period is NOT extended and the
     * administrator receives a clear error. The device is only marked ready
     * for deployment when every step succeeds.
     */
    public function prepare(Request $request, SyncClient $client, SyncRunner $runner, SnapshotDownloadService $snapshots): RedirectResponse
    {
        $validated = $request->validate([
            'preparation_token' => ['required', 'string', 'max:255'],
        ]);

        $token = (string) $validated['preparation_token'];

        // Step 1: Redeem the preparation token to get new time-limited authorization
        // This must happen FIRST because subsequent sync operations require valid authorization
        try {
            $response = $client->prepare($token);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'preparation_token' => 'The central server could not be reached: '.$exception->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            $message = (string) $response->json(
                'error.message',
                'The central server rejected the preparation token.',
            );

            return back()->withInput()->withErrors(['preparation_token' => $message]);
        }

        $data = (array) $response->json('data', []);

        // Hold the new authorization in memory; only persist if ALL steps succeed
        $newAuthorizedAt = (string) ($data['authorized_at'] ?? '');
        $newExpiresAt = (string) ($data['authorization_expires_at'] ?? '');
        $newStatus = (string) ($data['authorization_status'] ?? 'active');

        // Step 2: Upload pending operations (now with valid authorization)
        try {
            $upload = $runner->upload();
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'preparation_token' => 'Upload failed: '.$exception->getMessage(),
            ]);
        }

        // Step 3: Push audit trail and encoder registry
        try {
            $audit = app(AuditSyncService::class)->push($client);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'preparation_token' => 'Audit push failed: '.$exception->getMessage(),
            ]);
        }

        // Step 4: Download latest authorized data snapshot
        try {
            $download = $snapshots->download();
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'preparation_token' => 'Snapshot download failed: '.$exception->getMessage(),
            ]);
        }

        // All steps succeeded — now persist the new authorization and mark prepared
        SyncSetting::set('authorized_at', $newAuthorizedAt);
        SyncSetting::set('authorization_expires_at', $newExpiresAt);
        SyncSetting::set('authorization_status', $newStatus);
        SyncSetting::set('prepared_at', now()->toIso8601String());
        SyncSetting::flushCache();

        AuditLogger::log('offline_server.prepared', 'offline_server', null, [
            'server_uuid' => SyncSetting::get('server_uuid'),
            'authorization_expires_at' => $newExpiresAt,
            'upload' => $upload,
            'audit' => $audit,
            'download' => $download,
        ]);

        return redirect()
            ->route('sync.local.index')
            ->with('status', sprintf(
                'This installation is prepared for deployment. Authorized until %s. Uploaded: %s, Audit events: %s, Beneficiaries downloaded: %s.',
                $newExpiresAt ?? 'the new expiry date',
                number_format($upload['applied'] ?? 0),
                number_format($audit['accepted'] ?? 0),
                number_format($download['beneficiaries'] ?? 0),
            ));
    }

    /**
     * Local conflicts are shown in the unified Conflict Resolution workspace.
     * The legacy route is preserved so existing links keep working.
     */
    public function conflicts(): RedirectResponse
    {
        return redirect()->route('sync-conflicts.index', ['source' => 'local']);
    }

    /**
     * Resolve a local conflict.
     */
    public function resolveLocalConflict(
        Request $request,
        SyncConflict $syncConflict,
        SyncOperationService $operations,
    ): RedirectResponse {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['keep_central', 'accept_local', 'merge'])],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ]);

        try {
            $operations->resolveConflict(
                $syncConflict,
                $validated['action'],
                $validated['fields'] ?? [],
                $request->user()?->id,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['resolution' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync-conflicts.index', ['source' => 'local'])
            ->with('success', 'Conflict resolved. Sync again to push the resolution to the central server.');
    }

    /**
     * Check central server connection status for the UI indicator.
     */
    public function checkConnection(SyncClient $client): JsonResponse
    {
        return response()->json($client->checkConnection());
    }
}
