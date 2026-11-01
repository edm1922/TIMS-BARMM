<?php

namespace App\Services\Sync;

use App\Models\OfflineServer;
use App\Models\SyncRequest;
use App\Models\SyncRequestItem;
use App\Support\SyncIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records every upload as a traceable review request.
 *
 * Receipt and acceptance are deliberately separate states: a clean record is
 * applied on receipt, while a duplicate, conflict, or invalid record is held
 * as `awaiting_review` and is only applied when an administrator approves it.
 * The request row aggregates those per-record outcomes so the central server
 * can show one reviewable unit per Sync Now submission.
 */
class SyncRequestService
{
    /**
     * Create the request and one item per new operation. Operations that were
     * already recorded (a retry of an unresolved record) reuse their existing
     * item, so repeated uploads never create a second request or duplicate
     * items. Returns null when the batch contains nothing new.
     *
     * @param  array<int, array<string, mixed>>  $operations
     */
    public function create(OfflineServer $server, array $operations, ?int $submittedBy = null): ?SyncRequest
    {
        $known = SyncRequestItem::query()
            ->whereIn('operation_uuid', array_column($operations, 'operation_uuid'))
            ->pluck('operation_uuid')
            ->all();

        $new = array_values(array_filter(
            $operations,
            fn (array $operation): bool => ! in_array((string) $operation['operation_uuid'], $known, true),
        ));

        if ($new === []) {
            return null;
        }

        try {
            return DB::transaction(function () use ($server, $new, $submittedBy): SyncRequest {
                $request = SyncRequest::create([
                    'request_uuid' => SyncIdentity::newUuid(),
                    'offline_server_id' => $server->id,
                    'submitted_by' => $submittedBy,
                    'status' => SyncRequest::STATUS_SUBMITTED,
                    'total_operations' => count($new),
                    'submitted_at' => now(),
                    'last_activity_at' => now(),
                ]);

                foreach ($new as $operation) {
                    SyncRequestItem::create([
                        'sync_request_id' => $request->id,
                        'operation_uuid' => (string) $operation['operation_uuid'],
                        'entity_type' => (string) $operation['entity_type'],
                        'entity_uuid' => (string) $operation['entity_uuid'],
                        'operation' => (string) $operation['operation'],
                        'base_revision' => isset($operation['base_revision']) ? (int) $operation['base_revision'] : null,
                        'payload' => is_array($operation['payload'] ?? null) ? $operation['payload'] : [],
                        'status' => SyncRequestItem::STATUS_SUBMITTED,
                    ]);
                }

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent upload of the same operations won the race; the
            // receipt path remains authoritative and this request is skipped.
            return null;
        }
    }

    /**
     * Store the per-record outcome and refresh the request aggregates.
     *
     * @param  array<int, array<string, mixed>>  $operations
     * @param  array<int, array<string, mixed>>  $results
     */
    public function recordResults(SyncRequest $request, array $operations, array $results): void
    {
        $byOperation = [];

        foreach ($results as $result) {
            if (is_array($result) && isset($result['operation_uuid'])) {
                $byOperation[(string) $result['operation_uuid']] = $result;
            }
        }

        foreach ($operations as $operation) {
            $uuid = (string) ($operation['operation_uuid'] ?? '');

            if ($uuid === '' || ! isset($byOperation[$uuid])) {
                continue;
            }

            $item = SyncRequestItem::query()
                ->where('sync_request_id', $request->id)
                ->where('operation_uuid', $uuid)
                ->first();

            if ($item instanceof SyncRequestItem) {
                $this->applyResult($item, $byOperation[$uuid]);
            }
        }

        $this->refresh($request);
    }

    /**
     * Translate one central result into the item state model. Only duplicate
     * and conflict outcomes need a human: everything the server could apply
     * cleanly is already accepted, and what it rejected stays rejected with
     * the reason attached.
     *
     * @param  array<string, mixed>  $result
     */
    public function applyResult(SyncRequestItem $item, array $result): void
    {
        $status = (string) ($result['status'] ?? '');
        $code = isset($result['code']) && $result['code'] !== null ? (string) $result['code'] : null;

        $attributes = [
            'result' => $result,
            'failure_code' => $code,
        ];

        if ($status === 'applied' || ($status === 'duplicate' && $code === 'entity_already_exists')) {
            $attributes['status'] = SyncRequestItem::STATUS_APPLIED;
        } elseif ($status === 'awaiting_review') {
            // Held by central policy (deletion approval, batch mapping): the
            // record waits for an administrator decision, exactly like the
            // review-held states below. The conflict UUID must be persisted so
            // the inbox can deduplicate the item against its conflict row and
            // the reviewer is sent to the one workspace that can decide it.
            $attributes['status'] = SyncRequestItem::STATUS_AWAITING_REVIEW;
            $attributes['conflict_uuid'] = $result['conflict_uuid'] ?? null;
        } elseif ($status === 'conflict') {
            $attributes['status'] = SyncRequestItem::STATUS_AWAITING_REVIEW;
            $attributes['conflict_uuid'] = $result['conflict_uuid'] ?? null;
        } elseif ($status === 'duplicate') {
            $attributes['status'] = SyncRequestItem::STATUS_AWAITING_REVIEW;
            $attributes['duplicate_rule'] = $result['duplicate_rule'] ?? $code;
            $attributes['duplicate_matches'] = $result['matches'] ?? null;
        } elseif ($status === 'rejected') {
            $attributes['status'] = SyncRequestItem::STATUS_REJECTED;
        } else {
            $attributes['status'] = SyncRequestItem::STATUS_FAILED;
        }

        $item->forceFill($attributes)->save();
    }

    /**
     * Recompute the request counters and its derived status from its items.
     */
    public function refresh(SyncRequest $request, ?int $reviewerId = null): void
    {
        $items = $request->items()->get();

        $counts = [
            'total_operations' => $items->count(),
            'applied_count' => $items->where('status', SyncRequestItem::STATUS_APPLIED)->count(),
            'accepted_count' => $items->whereIn('status', [SyncRequestItem::STATUS_APPLIED, SyncRequestItem::STATUS_ACCEPTED])->count(),
            'rejected_count' => $items->where('status', SyncRequestItem::STATUS_REJECTED)->count(),
            'pending_count' => $items->whereIn('status', [SyncRequestItem::STATUS_SUBMITTED, SyncRequestItem::STATUS_AWAITING_REVIEW])->count(),
            'duplicate_count' => $items->filter(fn (SyncRequestItem $item): bool => $item->duplicate_rule !== null)->count(),
            'conflict_count' => $items->filter(fn (SyncRequestItem $item): bool => $item->conflict_uuid !== null)->count(),
            'validation_error_count' => $items->filter(fn (SyncRequestItem $item): bool => $this->isValidationError($item))->count(),
        ];

        $status = match (true) {
            $counts['total_operations'] === 0 => SyncRequest::STATUS_SUBMITTED,
            $counts['pending_count'] > 0 => SyncRequest::STATUS_AWAITING_REVIEW,
            $counts['rejected_count'] === $counts['total_operations'] => SyncRequest::STATUS_REJECTED,
            $counts['rejected_count'] > 0 => SyncRequest::STATUS_PARTIALLY_ACCEPTED,
            $counts['accepted_count'] === $counts['total_operations'] => SyncRequest::STATUS_ACCEPTED,
            default => SyncRequest::STATUS_FAILED,
        };

        $attributes = $counts + [
            'status' => $status,
            'last_activity_at' => now(),
        ];

        if (! $request->needsReview() && $status !== SyncRequest::STATUS_SUBMITTED) {
            $attributes['reviewed_at'] = $request->reviewed_at ?? now();
            $attributes['reviewed_by'] = $reviewerId ?? $request->reviewed_by;
        }

        $request->forceFill($attributes)->save();
    }

    private function isValidationError(SyncRequestItem $item): bool
    {
        return $item->status === SyncRequestItem::STATUS_REJECTED
            && in_array($item->failure_code, ['validation_failed', 'invalid_payload', 'not_found', 'unsupported_operation', 'unsupported_entity'], true);
    }

    /**
     * Rebuild the operation payload an item was created from, so it can be
     * handed back to the apply path or the idempotency receipt.
     *
     * @return array<string, mixed>
     */
    public function operationFor(SyncRequestItem $item): array
    {
        return [
            'operation_uuid' => $item->operation_uuid,
            'entity_type' => $item->entity_type,
            'entity_uuid' => $item->entity_uuid,
            'operation' => $item->operation,
            'base_revision' => $item->base_revision,
            'payload' => $item->payload ?? [],
        ];
    }
}
