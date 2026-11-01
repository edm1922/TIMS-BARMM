<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Sync;

use App\Exceptions\Sync\InvalidEnrollmentTokenException;
use App\Http\Controllers\Controller;
use App\Models\OfflineServer;
use App\Services\Sync\AuditSyncService;
use App\Services\Sync\ReauthorizationService;
use App\Services\Sync\SnapshotDownloadService;
use App\Services\Sync\SyncClient;
use App\Services\Sync\SyncRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PreparationController extends Controller
{
    /**
     * Prepare an offline installation for a new encoding session.
     *
     * This endpoint:
     * 1. Validates the preparation token (reauthorization token)
     * 2. Verifies the device is active and not revoked
     * 3. Performs full sync: upload, audit push, snapshot download
     * 4. Issues new time-limited authorization
     * 5. Returns new authorization details
     */
    public function store(
        Request $request,
        SyncRunner $runner,
        SnapshotDownloadService $snapshots,
        ReauthorizationService $reauthorization
    ): JsonResponse {
        $data = $request->validate([
            'preparation_token' => ['required', 'string', 'max:255'],
        ]);

        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        // Verify device is not revoked
        if ($server->isRevoked()) {
            return response()->json([
                'error' => [
                    'code' => 'device_revoked',
                    'message' => 'This installation has been revoked and cannot be prepared.',
                ],
            ], 403);
        }

        if ($server->status === OfflineServer::STATUS_SUSPENDED) {
            return response()->json([
                'error' => [
                    'code' => 'device_suspended',
                    'message' => 'This installation is suspended and cannot be prepared.',
                ],
            ], 403);
        }

        // Step 1: Validate and redeem the reauthorization token to get new authorization period
        try {
            $reauthResult = $reauthorization->reauthorize($server, (string) $data['preparation_token']);
        } catch (InvalidEnrollmentTokenException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_preparation_token',
                    'message' => $exception->getMessage(),
                ],
            ], 401);
        } catch (Throwable $exception) {
            return response()->json([
                'error' => [
                    'code' => 'preparation_failed',
                    'message' => 'Failed to validate preparation token: '.$exception->getMessage(),
                ],
            ], 500);
        }

        // At this point, the server's authorization has been extended.
        // Now perform the sync operations with the new valid authorization.

        // Step 2: Upload pending operations
        try {
            $upload = $runner->upload();
        } catch (Throwable $exception) {
            return response()->json([
                'error' => [
                    'code' => 'upload_failed',
                    'message' => 'Upload failed: '.$exception->getMessage(),
                ],
            ], 500);
        }

        // Step 3: Push audit trail and encoder registry
        try {
            $audit = app(AuditSyncService::class)->push(
                app(SyncClient::class)
            );
        } catch (Throwable $exception) {
            return response()->json([
                'error' => [
                    'code' => 'audit_push_failed',
                    'message' => 'Audit push failed: '.$exception->getMessage(),
                ],
            ], 500);
        }

        // Step 4: Download latest authorized data snapshot
        try {
            $snapshotService = app(SnapshotDownloadService::class);
            $download = $snapshotService->download();
        } catch (Throwable $exception) {
            return response()->json([
                'error' => [
                    'code' => 'snapshot_download_failed',
                    'message' => 'Snapshot download failed: '.$exception->getMessage(),
                ],
            ], 500);
        }

        // All steps succeeded - return the new authorization details
        $server->refresh();

        return response()->json([
            'data' => [
                'server_uuid' => $server->server_uuid,
                'authorized_at' => $server->authorized_at?->toIso8601String(),
                'authorization_expires_at' => $server->authorization_expires_at?->toIso8601String(),
                'authorization_status' => $server->authorizationStatus(),
                'upload' => $upload,
                'audit' => [
                    'pushed' => $audit['pushed'] ?? 0,
                    'accepted' => $audit['accepted'] ?? 0,
                    'registry' => $audit['registry'] ?? 0,
                ],
                'download' => $download,
            ],
        ]);
    }
}
