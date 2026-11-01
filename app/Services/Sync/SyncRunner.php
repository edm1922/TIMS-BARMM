<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncOperation;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SyncRunner
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly LocalChangeApplier $applier,
    ) {}

    /**
     * @return array{upload: array<string, int>, download: array<string, int>}
     */
    public function run(): array
    {
        if (! $this->client->isEnrolled()) {
            throw new RuntimeException('This server is not enrolled with a central server yet.');
        }

        $upload = $this->upload();
        $download = $this->download();

        try {
            $this->client->ack($download['cursor'], (int) SyncSetting::get('last_upload_revision', 0));
            SyncSetting::forget('last_sync_error');
        } catch (Throwable $exception) {
            SyncSetting::set('last_sync_error', 'Local sync completed, but the central acknowledgement failed: '.$exception->getMessage());
        }

        SyncSetting::set('last_sync_at', now()->toIso8601String());

        $this->reportHeartbeat();
        $this->pushAuditTrail();

        return ['upload' => $upload, 'download' => $download];
    }

    /**
     * Push the local audit trail and the encoder registry to the central
     * server so the central record stays complete and attributable.
     */
    private function pushAuditTrail(): void
    {
        if (! $this->client->isEnrolled()) {
            return;
        }

        try {
            $result = app(AuditSyncService::class)->push($this->client);

            if ($result['error'] !== null) {
                SyncSetting::set('last_audit_sync_error', $result['error']);
            } else {
                SyncSetting::forget('last_audit_sync_error');
                SyncSetting::set('last_audit_sync_at', now()->toIso8601String());
            }
        } catch (Throwable $exception) {
            SyncSetting::set('last_audit_sync_error', $exception->getMessage());
        }
    }

    /**
     * Report the real queue state to the central server so administrators see
     * actual pending/failed numbers instead of guessing. Failures here never
     * break the sync cycle. Public because Sync Now reports after its own
     * orchestration, keeping the Offline Servers page fresh on every run.
     */
    public function reportHeartbeat(): void
    {
        if (! $this->client->isEnrolled()) {
            return;
        }

        try {
            $this->client->heartbeat([
                'pending' => SyncOperation::query()->pending()->count(),
                'failed' => SyncOperation::query()->where('status', SyncOperation::STATUS_FAILED)->count(),
                'conflicts' => SyncOperation::query()->where('status', SyncOperation::STATUS_CONFLICT)->count(),
                'awaiting_review' => SyncOperation::query()->where('status', SyncOperation::STATUS_AWAITING_REVIEW)->count(),
                'synced' => SyncOperation::query()->where('status', SyncOperation::STATUS_SYNCED)->count(),
                'last_error' => SyncSetting::get('last_sync_error'),
                'reported_at' => now()->toIso8601String(),
            ], $this->batchRoster());
        } catch (Throwable) {
            // Reporting is best effort; the local queue keeps working offline.
        }
    }

    /**
     * The offline server's per-batch roster: every batch (including
     * soft-deleted ones, whose members are the "ghost" records that confuse
     * dashboards) with its live member count, plus the unassigned bucket.
     * Central compares this against its own scoped state to surface missing,
     * divergent, and unknown batches without ever touching member data.
     *
     * @return array<int, array<string, mixed>>
     */
    private function batchRoster(): array
    {
        $counts = Beneficiary::query()
            ->whereNull('archived_at')
            ->select('beneficiary_batch_id')
            ->selectRaw('COUNT(*) AS members')
            ->groupBy('beneficiary_batch_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->beneficiary_batch_id === null ? 'unassigned' : (string) $row->beneficiary_batch_id => (int) $row->members,
            ]);

        $roster = BeneficiaryBatch::query()
            ->withTrashed()
            ->orderBy('id')
            ->get(['id', 'global_uuid', 'name', 'deleted_at'])
            ->map(fn (BeneficiaryBatch $batch): array => [
                'uuid' => $batch->global_uuid,
                'name' => $batch->name,
                'deleted' => $batch->trashed(),
                'members' => (int) ($counts[$batch->id] ?? 0),
            ])
            ->values()
            ->all();

        $roster[] = [
            'uuid' => null,
            'name' => 'Not in any batch',
            'deleted' => false,
            'members' => (int) ($counts['unassigned'] ?? 0),
        ];

        return $roster;
    }

    /**
     * @return array<string, int>
     */
    public function upload(): array
    {
        $operations = SyncOperation::query()
            ->pending()
            ->orderBy('id')
            ->limit((int) config('sync.upload_batch_size', 50))
            ->get();

        if ($operations->isEmpty()) {
            return ['attempted' => 0, 'applied' => 0, 'conflicts' => 0, 'rejected' => 0, 'failed' => 0, 'awaiting_review' => 0];
        }

        $response = $this->client->operations($operations->map(fn (SyncOperation $operation) => [
            'operation_uuid' => $operation->operation_uuid,
            'entity_type' => $operation->entity_type,
            'entity_uuid' => $operation->entity_uuid,
            'operation' => $operation->operation,
            'base_revision' => $operation->base_revision,
            'payload' => $operation->payload,
        ])->all());

        if (! $response->successful()) {
            $message = (string) $response->json('error.message', 'The central server rejected the upload.');

            foreach ($operations as $operation) {
                $this->retryLater($operation, $message);
            }

            SyncSetting::set('last_sync_error', $message);

            return [
                'attempted' => $operations->count(),
                'applied' => 0,
                'conflicts' => 0,
                'rejected' => 0,
                'failed' => $operations->count(),
                'awaiting_review' => 0,
            ];
        }

        $results = collect($response->json('data', []))->keyBy('operation_uuid');
        $counts = ['attempted' => $operations->count(), 'applied' => 0, 'conflicts' => 0, 'rejected' => 0, 'failed' => 0, 'awaiting_review' => 0];

        foreach ($operations as $operation) {
            $result = $results->get($operation->operation_uuid);

            if (! is_array($result)) {
                $this->retryLater($operation, 'The central server did not return a result for this operation.');
                $counts['failed']++;

                continue;
            }

            $status = (string) ($result['status'] ?? '');

            if ($status === 'applied') {
                $operation->markSynced();
                $this->recordUploadedRevision($operation, (int) ($result['revision'] ?? 0));
                $counts['applied']++;

                continue;
            }

            if ($status === 'duplicate') {
                // A confirmed duplicate is a decision: the record matches an
                // existing central record and nothing was inserted. Any other
                // duplicate is a candidate the central server is holding for
                // review, so it must not be presented as synchronized.
                if (($result['code'] ?? null) === 'duplicate_confirmed') {
                    $operation->markSynced();
                    $counts['applied']++;

                    continue;
                }

                $operation->markAwaitingReview(
                    (string) ($result['message'] ?? 'The central server is reviewing this record.'),
                );
                $counts['awaiting_review']++;

                continue;
            }

            if ($status === 'awaiting_review') {
                // Held by central policy (e.g. deletion approval): the
                // operation keeps its UUID and is decided later, either by
                // the replayed receipt or the delta log — never by a local
                // re-apply.
                $operation->markAwaitingReview(
                    (string) ($result['message'] ?? 'Held for central approval.'),
                );
                $counts['awaiting_review']++;

                continue;
            }

            if ($status === 'conflict') {
                $operation->forceFill([
                    'status' => SyncOperation::STATUS_CONFLICT,
                    'last_error' => (string) ($result['message'] ?? 'The central record has a newer revision.'),
                    'last_error_code' => $result['code'] ?? null,
                ])->save();

                $this->markEntityConflict($operation);
                $counts['conflicts']++;

                continue;
            }

            if ($status === 'rejected') {
                $operation->forceFill([
                    'status' => SyncOperation::STATUS_REJECTED,
                    'last_error' => (string) ($result['message'] ?? $result['code'] ?? 'Rejected by the central server.'),
                    'last_error_code' => $result['code'] ?? null,
                ])->save();
                $counts['rejected']++;

                continue;
            }

            $this->retryLater($operation, 'The central server returned an unknown operation result.');
            $counts['failed']++;
        }

        SyncSetting::set('last_upload_revision', (string) ($operations->max('revision') ?? '0'));

        return $counts;
    }

    /**
     * Pull the central server's per-record review decisions and converge the
     * local queue on them. A record held for review was never marked synced,
     * so this is how an administrator's accept/reject decision reaches the
     * offline server. The cursor is the last item id consumed, which keeps
     * repeated runs idempotent.
     *
     * @return array{accepted: int, rejected: int, cursor: int}
     */
    public function reconcileOutcomes(): array
    {
        $cursor = (int) (SyncSetting::get('last_request_outcome_cursor') ?? 0);
        $counts = ['accepted' => 0, 'rejected' => 0, 'cursor' => $cursor];

        while (true) {
            $response = $this->client->requestOutcomes($cursor);

            if (! $response->successful()) {
                $message = (string) $response->json('error.message', 'The central review outcomes could not be downloaded.');

                SyncSetting::set('last_sync_error', $message);

                throw new RuntimeException($message);
            }

            $outcomes = (array) $response->json('data', []);
            $next = $response->json('meta.next_cursor');
            $hasMore = (bool) $response->json('meta.has_more', false);

            foreach ($outcomes as $outcome) {
                if (! is_array($outcome)) {
                    continue;
                }

                $operation = SyncOperation::query()
                    ->where('operation_uuid', (string) ($outcome['operation_uuid'] ?? ''))
                    ->first();

                if ($operation instanceof SyncOperation) {
                    $status = (string) ($outcome['status'] ?? '');

                    if ($status === 'accepted' && $operation->status !== SyncOperation::STATUS_SYNCED) {
                        $operation->markSynced();
                        $this->recordUploadedRevision($operation, (int) ($outcome['revision'] ?? 0));
                        $counts['accepted']++;
                    } elseif ($status === 'rejected' && $operation->status !== SyncOperation::STATUS_REJECTED) {
                        $operation->forceFill([
                            'status' => SyncOperation::STATUS_REJECTED,
                            'last_error' => (string) ($outcome['message'] ?? 'Rejected during central review.'),
                            'last_error_code' => (string) ($outcome['code'] ?? 'reviewer_rejected'),
                        ])->save();
                        $counts['rejected']++;
                    }
                }

                if (is_numeric($outcome['item_id'] ?? null)) {
                    $counts['cursor'] = (int) $outcome['item_id'];
                    SyncSetting::set('last_request_outcome_cursor', (string) $counts['cursor']);
                }
            }

            if (! $hasMore || ! is_numeric($next)) {
                break;
            }

            $cursor = (int) $next;
        }

        return $counts;
    }

    /**
     * Requeue operations rejected only because of the former scope rule.
     * Unrelated rejections stay terminal, so this never loops on bad data.
     */
    public function retryScopeRejectedOperations(): int
    {
        $operations = SyncOperation::query()
            ->rejectedByFormerScope()
            ->orderBy('id')
            ->get();

        foreach ($operations as $operation) {
            $operation->requeue();
        }

        return $operations->count();
    }

    /**
     * @return array<string, int>
     */
    public function download(): array
    {
        $cursor = (int) (SyncSetting::get('last_change_cursor')
            ?? SyncSetting::get('last_download_revision')
            ?? 0);

        $counts = ['applied' => 0, 'conflicts' => 0, 'skipped' => 0, 'cursor' => $cursor];

        while (true) {
            $response = $this->client->changes($cursor);

            if (! $response->successful()) {
                $message = (string) $response->json('error.message', 'The central changes could not be downloaded.');

                SyncSetting::set('last_sync_error', $message);

                throw new RuntimeException($message);
            }

            $changes = (array) $response->json('data', []);
            $nextCursor = $response->json('meta.next_cursor');
            $hasMore = (bool) $response->json('meta.has_more', false);

            if ($changes !== []) {
                DB::transaction(function () use ($changes, $nextCursor, &$counts): void {
                    foreach ($changes as $change) {
                        $result = $this->applier->apply((array) $change);
                        $counts[$result === 'conflict' ? 'conflicts' : $result]++;
                    }

                    if (is_numeric($nextCursor)) {
                        $counts['cursor'] = (int) $nextCursor;
                        SyncSetting::set('last_change_cursor', (string) $nextCursor);
                        SyncSetting::set('last_download_revision', (string) $nextCursor);
                    }
                });
            }

            if (! $hasMore || ! is_numeric($nextCursor)) {
                break;
            }

            $cursor = (int) $nextCursor;
        }

        return $counts;
    }

    private function recordUploadedRevision(SyncOperation $operation, int $revision): void
    {
        if ($revision <= 0) {
            return;
        }

        $model = $operation->entity_type === 'beneficiary'
            ? Beneficiary::query()->where('global_uuid', $operation->entity_uuid)->first()
            : BeneficiaryBatch::withTrashed()->where('global_uuid', $operation->entity_uuid)->first();

        if ($model === null) {
            return;
        }

        $model->forceFill([
            'revision' => $revision,
            'server_revision' => $revision,
            'sync_status' => 'synced',
            'conflict_status' => 'none',
        ])->save();
    }

    private function markEntityConflict(SyncOperation $operation): void
    {
        $model = $operation->entity_type === 'beneficiary'
            ? Beneficiary::query()->where('global_uuid', $operation->entity_uuid)->first()
            : BeneficiaryBatch::withTrashed()->where('global_uuid', $operation->entity_uuid)->first();

        $model?->forceFill([
            'sync_status' => 'conflict',
            'conflict_status' => 'open',
        ])->save();
    }

    private function retryLater(SyncOperation $operation, string $message): void
    {
        $seconds = min(3600, 60 * (2 ** min(6, $operation->attempts)));

        $operation->markFailed($message, $seconds);
    }
}
