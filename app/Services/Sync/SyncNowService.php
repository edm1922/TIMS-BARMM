<?php

namespace App\Services\Sync;

use App\Models\SyncSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncNowService
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly SyncRunner $runner,
        private readonly SnapshotDownloadService $snapshots,
        private readonly AuditSyncService $auditSync,
    ) {}

    /**
     * Execute the full Sync Now orchestration workflow.
     *
     * @return array{status: string, message: string, steps: array<string, mixed>, summary: array<string, int>}
     */
    public function execute(): array
    {
        // Prevent concurrent sync runs using a cache lock
        $lock = Cache::lock('sync-now-execution', 300); // 5 minute max lock

        if (! $lock->get()) {
            return [
                'status' => 'error',
                'message' => 'A synchronization is already in progress. Please wait for it to complete.',
                'steps' => [],
                'summary' => [],
            ];
        }

        try {
            $steps = [];
            $summary = [
                'uploaded' => 0,
                'downloaded' => 0,
                'conflicts' => 0,
                'audit_pushed' => 0,
                'audit_accepted' => 0,
                'encoders_reported' => 0,
                'awaiting_review' => 0,
                'reviewed_accepted' => 0,
                'reviewed_rejected' => 0,
            ];

            // Step 1: Check connection
            $connection = $this->checkConnectionStep();
            $steps[] = $connection;
            $connected = $connection['status'] === 'ok';
            if ($connection['status'] !== 'ok') {
                return $this->fail('Connection check failed', $steps, $summary);
            }

            // Step 2: Validate device authorization
            $auth = $this->validateAuthorizationStep();
            $steps[] = $auth;
            if ($auth['status'] !== 'ok') {
                return $this->fail('Authorization validation failed', $steps, $summary);
            }

            // Step 3: Upload pending operations
            $upload = $this->uploadStep();
            $steps[] = $upload;
            $summary['uploaded'] = $upload['applied'] ?? 0;
            $summary['conflicts'] = $upload['conflicts'] ?? 0;
            $summary['rejected'] = $upload['rejected'] ?? 0;
            $summary['awaiting_review'] = $upload['awaiting_review'] ?? 0;

            // Step 3b: Apply the central server's decisions for records it held
            // for review in an earlier run.
            $review = $this->reconcileStep();
            $steps[] = $review;
            $summary['reviewed_accepted'] = $review['accepted'] ?? 0;
            $summary['reviewed_rejected'] = $review['rejected'] ?? 0;

            // Step 4: Push audit trail and encoder registry
            $audit = $this->pushAuditStep();
            $steps[] = $audit;
            $summary['audit_pushed'] = $audit['pushed'] ?? 0;
            $summary['audit_accepted'] = $audit['accepted'] ?? 0;
            $summary['encoders_reported'] = $audit['registry'] ?? 0;

            // Step 5: Retrieve central updates (delta changes + reference data)
            $download = $this->downloadStep();
            $steps[] = $download;
            $summary['downloaded'] = $download['applied'] ?? 0;
            $summary['conflicts'] += $download['conflicts'] ?? 0;

            // Step 6: Process acknowledgments and conflicts (handled in runner)
            $ack = $this->acknowledgeStep($download['cursor'] ?? 0);
            $steps[] = $ack;

            // Step 7: Verify result and update sync status
            $this->updateSyncStatus($download['cursor'] ?? 0);

            // Step 8: Report the post-run queue state so the central Offline
            // Servers page reflects this run immediately (best effort).
            if ($connected) {
                $this->runner->reportHeartbeat();
            }

            $allOk = collect($steps)->every(fn ($s) => $s['status'] === 'ok');
            $message = $allOk
                ? sprintf('Sync complete: %s uploaded, %s downloaded, %s conflict(s), %s rejected, %s audit events accepted.',
                    number_format($summary['uploaded']),
                    number_format($summary['downloaded']),
                    number_format($summary['conflicts']),
                    number_format($summary['rejected']),
                    number_format($summary['audit_accepted']))
                : 'Sync completed with warnings. Check details below.';

            $result = [
                'status' => $allOk ? 'success' : 'warning',
                'message' => $message,
                'steps' => $steps,
                'summary' => $summary,
            ];
        } finally {
            $lock->release();
        }

        return $result;
    }

    private function checkConnectionStep(): array
    {
        $connection = $this->client->checkConnection();

        if ($connection['status'] === 'connected') {
            return ['step' => 'connection', 'status' => 'ok', 'message' => $connection['message'], 'detail' => $connection['detail']];
        }

        $messages = [
            'offline' => 'Central server is unreachable.',
            'auth_error' => 'Authorization expired or revoked. Reauthorization required.',
            'sync_error' => 'Central server returned an error.',
            'not_configured' => 'Central server URL not configured.',
        ];

        return [
            'step' => 'connection',
            'status' => 'error',
            'message' => $messages[$connection['status']] ?? $connection['message'],
            'detail' => $connection['detail'],
        ];
    }

    private function validateAuthorizationStep(): array
    {
        if (! $this->client->isEnrolled()) {
            return [
                'step' => 'authorization',
                'status' => 'error',
                'message' => 'This server is not enrolled with a central server yet.',
                'detail' => null,
            ];
        }

        $authorizedAt = SyncSetting::get('authorized_at');
        $expiresAt = SyncSetting::get('authorization_expires_at');

        if ($expiresAt && now()->gt(Carbon::parse($expiresAt))) {
            return [
                'step' => 'authorization',
                'status' => 'error',
                'message' => 'Authorization period has expired. Request reauthorization from central administrator.',
                'detail' => ['expires_at' => $expiresAt],
            ];
        }

        return [
            'step' => 'authorization',
            'status' => 'ok',
            'message' => 'Device authorization is valid.',
            'detail' => ['authorized_at' => $authorizedAt, 'expires_at' => $expiresAt],
        ];
    }

    private function uploadStep(): array
    {
        try {
            $result = $this->runner->upload();

            $message = sprintf('Uploaded %d operations (%d conflicts, %d rejected, %d failed).',
                $result['applied'],
                $result['conflicts'],
                $result['rejected'],
                $result['failed']);

            if (($result['awaiting_review'] ?? 0) > 0) {
                $message .= sprintf(' %d record(s) are awaiting central review — they are not accepted yet.', $result['awaiting_review']);
            }

            return [
                'step' => 'upload',
                'status' => 'ok',
                'message' => $message,
                'applied' => $result['applied'],
                'conflicts' => $result['conflicts'],
                'rejected' => $result['rejected'],
                'failed' => $result['failed'],
                'awaiting_review' => $result['awaiting_review'] ?? 0,
            ];
        } catch (Throwable $exception) {
            return [
                'step' => 'upload',
                'status' => 'error',
                'message' => 'Upload failed: '.$exception->getMessage(),
                'detail' => null,
            ];
        }
    }

    /**
     * Apply the central server's review decisions for records it held.
     */
    private function reconcileStep(): array
    {
        try {
            $result = $this->runner->reconcileOutcomes();

            return [
                'step' => 'review_outcomes',
                'status' => 'ok',
                'message' => sprintf('Central review decisions applied: %d accepted, %d rejected.', $result['accepted'], $result['rejected']),
                'accepted' => $result['accepted'],
                'rejected' => $result['rejected'],
            ];
        } catch (Throwable $exception) {
            return [
                'step' => 'review_outcomes',
                'status' => 'warning',
                'message' => 'Central review decisions could not be applied: '.$exception->getMessage(),
                'detail' => null,
            ];
        }
    }

    private function pushAuditStep(): array
    {
        try {
            $result = $this->auditSync->push($this->client);

            return [
                'step' => 'audit',
                'status' => $result['error'] ? 'warning' : 'ok',
                'message' => $result['error']
                    ? 'Audit push failed: '.$result['error']
                    : sprintf('Pushed %d audit events (%d accepted), reported %d encoders.',
                        $result['pushed'],
                        $result['accepted'],
                        $result['registry']),
                'pushed' => $result['pushed'],
                'accepted' => $result['accepted'],
                'registry' => $result['registry'],
                'error' => $result['error'],
            ];
        } catch (Throwable $exception) {
            return [
                'step' => 'audit',
                'status' => 'error',
                'message' => 'Audit push failed: '.$exception->getMessage(),
                'detail' => null,
            ];
        }
    }

    private function downloadStep(): array
    {
        try {
            $result = $this->runner->download();

            return [
                'step' => 'download',
                'status' => 'ok',
                'message' => sprintf('Downloaded %d changes (%d conflicts, %d skipped).',
                    $result['applied'],
                    $result['conflicts'],
                    $result['skipped']),
                'applied' => $result['applied'],
                'conflicts' => $result['conflicts'],
                'skipped' => $result['skipped'],
                'cursor' => $result['cursor'],
            ];
        } catch (Throwable $exception) {
            return [
                'step' => 'download',
                'status' => 'error',
                'message' => 'Download failed: '.$exception->getMessage(),
                'detail' => null,
            ];
        }
    }

    private function acknowledgeStep(int $downloadCursor): array
    {
        try {
            $this->client->ack($downloadCursor, (int) SyncSetting::get('last_upload_revision', 0));
            SyncSetting::forget('last_sync_error');

            return [
                'step' => 'acknowledge',
                'status' => 'ok',
                'message' => 'Central acknowledgment recorded.',
                'detail' => null,
            ];
        } catch (Throwable $exception) {
            SyncSetting::set('last_sync_error', 'Local sync completed, but central acknowledgment failed: '.$exception->getMessage());

            return [
                'step' => 'acknowledge',
                'status' => 'warning',
                'message' => 'Central acknowledgment failed: '.$exception->getMessage(),
                'detail' => null,
            ];
        }
    }

    private function updateSyncStatus(int $cursor): void
    {
        SyncSetting::set('last_sync_at', now()->toIso8601String());
        if ($cursor > 0) {
            SyncSetting::set('last_change_cursor', (string) $cursor);
            SyncSetting::set('last_download_revision', (string) $cursor);
        }
    }

    private function fail(string $message, array $steps, array $summary): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'steps' => $steps,
            'summary' => $summary,
        ];
    }
}
