<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncConflict;
use App\Models\SyncEvent;
use App\Models\SyncRequest;
use App\Models\SyncRequestDecision;
use App\Models\SyncRequestItem;
use App\Models\User;
use App\Services\AuditLogger;
use InvalidArgumentException;

/**
 * Applies an administrator's decision on a submitted record.
 *
 * Approval re-runs the normal application path (with the duplicate guard
 * lifted, because a human already compared the record), rejection closes any
 * open conflict for the same record, and both write the final outcome onto the
 * operation's receipt so an offline retry replays the decision instead of
 * opening a second review.
 */
class SyncRequestReviewService
{
    public function __construct(
        private readonly SyncOperationService $operations,
        private readonly SyncRequestService $requests,
    ) {}

    /**
     * @param  array{action?: string, fields?: array<int, string>, reason?: string}  $options
     * @return array<string, mixed>
     */
    public function approve(SyncRequestItem $item, ?int $actorId, array $options = []): array
    {
        $this->assertAwaitingReview($item);
        $this->assertNotConflictWorkspaceDecision($item);

        $server = $this->serverOf($item);
        $operation = $this->requests->operationFor($item);
        $action = (string) ($options['action'] ?? '');
        $reason = isset($options['reason']) && $options['reason'] !== '' ? (string) $options['reason'] : null;

        if ($action === 'keep_central') {
            $this->reject($item, $reason ?? 'The central record was kept.', $actorId, 'keep_central');

            return ['status' => 'rejected', 'operation_uuid' => $item->operation_uuid];
        }

        if ($item->conflict_uuid !== null) {
            $result = $this->approveConflict($item, $action, $options['fields'] ?? [], $reason, $actorId);
            $decisionAction = $action === 'merge' ? 'merge' : 'accept_local';
        } elseif ($item->duplicate_rule !== null) {
            $result = $this->approveDuplicate($item, $server, $operation, $action, $reason);
            $decisionAction = $action === 'update' ? 'update' : 'link';
        } else {
            $result = $this->operations->applyApproved($server, $operation);
            $decisionAction = 'apply';
        }

        if (! in_array($result['status'] ?? '', ['applied', 'duplicate'], true)) {
            throw new InvalidArgumentException((string) ($result['message'] ?? 'The record could not be applied.'));
        }

        // Every decision is written onto the operation receipt, so a retry
        // replays it instead of returning to the review queue. The duplicate
        // confirmation path already wrote its own receipt shape.
        if (($result['code'] ?? null) !== 'duplicate_confirmed') {
            $this->operations->recordDecision($server, $operation, $result);
        }

        $item->forceFill([
            'status' => SyncRequestItem::STATUS_ACCEPTED,
            'result' => $result,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        $this->record($item, 'accepted', $actorId, $reason);
        $this->recordHistory($item, SyncRequestDecision::DECISION_ACCEPTED, $decisionAction, $reason, $actorId, $result);
        $this->requests->refresh($this->requestOf($item), $actorId);

        return $result;
    }

    /**
     * A conflict is resolved with the same rules as the conflict workspace:
     * accept the submitted values, merge selected fields, or keep central.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function approveConflict(SyncRequestItem $item, string $action, array $fields, ?string $reason, ?int $actorId): array
    {
        $conflict = SyncConflict::query()
            ->where('conflict_uuid', $item->conflict_uuid)
            ->first();

        if (! $conflict instanceof SyncConflict) {
            throw new InvalidArgumentException('The conflict for this record no longer exists.');
        }

        if ($action === 'merge' && $fields === []) {
            throw new InvalidArgumentException('Select at least one field to merge, or choose a different resolution.');
        }

        $this->operations->resolveConflict(
            $conflict,
            $action === 'merge' ? 'merge' : 'accept_local',
            $fields,
            $actorId,
        );

        return [
            'operation_uuid' => $item->operation_uuid,
            'entity_type' => $item->entity_type,
            'entity_uuid' => $item->entity_uuid,
            'status' => 'applied',
            'code' => null,
            'message' => $reason,
            'revision' => $conflict->entity_type === 'beneficiary'
                ? (int) Beneficiary::query()->where('global_uuid', $conflict->entity_uuid)->value('revision')
                : (int) BeneficiaryBatch::withTrashed()->where('global_uuid', $conflict->entity_uuid)->value('revision'),
        ];
    }

    /**
     * A duplicate cannot be inserted blindly: the identifier it matches is
     * unique. The reviewer therefore chooses between confirming the match
     * (the submission is the same person, nothing is written) and explicitly
     * applying the submitted values onto the matched central record.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function approveDuplicate(SyncRequestItem $item, $server, array $operation, string $action, ?string $reason): array
    {
        $match = $this->matchedRecord($item);

        if ($match === null) {
            throw new InvalidArgumentException('The matching central record no longer exists; reject the submission instead.');
        }

        if ($action === 'update') {
            $merged = $operation;
            $merged['entity_uuid'] = $match->global_uuid;
            $merged['operation'] = 'updated';
            $merged['base_revision'] = (int) $match->revision;

            $result = $this->operations->applyApproved($server, $merged);

            if (($result['status'] ?? '') !== 'applied') {
                throw new InvalidArgumentException((string) ($result['message'] ?? 'The matched record could not be updated.'));
            }

            $result['merged_into'] = $match->global_uuid;

            return $result;
        }

        // Confirming the match is a successful duplicate outcome: the offline
        // server stops retrying without a second person being created.
        $result = [
            'operation_uuid' => $item->operation_uuid,
            'entity_type' => $item->entity_type,
            'entity_uuid' => $item->entity_uuid,
            'status' => 'duplicate',
            'code' => 'duplicate_confirmed',
            'message' => $reason ?? 'Confirmed as the existing record; nothing was inserted.',
            'matched_beneficiary_uuid' => $match->global_uuid,
            'matched_beneficiary_code' => $item->entity_type === 'beneficiary' ? $match->beneficiary_code : null,
        ];

        $this->operations->recordDecision($server, $operation, $result);

        return $result;
    }

    private function matchedRecord(SyncRequestItem $item): Beneficiary|BeneficiaryBatch|null
    {
        if ($item->entity_type === 'beneficiary_batch') {
            $name = trim((string) ($item->payload['name'] ?? ''));

            return $name === ''
                ? null
                : BeneficiaryBatch::withTrashed()->where('name', $name)->first();
        }

        $codes = array_values(array_filter((array) $item->duplicate_matches));

        if ($codes === []) {
            return null;
        }

        return Beneficiary::query()
            ->where(function ($query) use ($codes): void {
                $query->whereIn('beneficiary_code', $codes)
                    ->orWhereIn('source_id_no', $codes);
            })
            ->first();
    }

    /**
     * Reject one submitted record with a recorded reason. Nothing is written
     * to the beneficiary tables; the offline server keeps its local record and
     * receives the rejection on its next retry.
     */
    public function reject(SyncRequestItem $item, string $reason, ?int $actorId, string $action = 'reject'): void
    {
        $this->assertAwaitingReview($item);
        $this->assertNotConflictWorkspaceDecision($item);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        $server = $this->serverOf($item);
        $operation = $this->requests->operationFor($item);

        if ($item->conflict_uuid !== null) {
            $conflict = SyncConflict::query()
                ->where('conflict_uuid', $item->conflict_uuid)
                ->first();

            if ($conflict instanceof SyncConflict && $conflict->status === 'open') {
                $this->operations->resolveConflict($conflict, 'keep_central', [], $actorId);
            }
        }

        $result = [
            'operation_uuid' => $item->operation_uuid,
            'entity_type' => $item->entity_type,
            'entity_uuid' => $item->entity_uuid,
            'status' => 'rejected',
            'code' => 'reviewer_rejected',
            'message' => $reason,
        ];

        $this->operations->recordDecision($server, $operation, $result);

        $item->forceFill([
            'status' => SyncRequestItem::STATUS_REJECTED,
            'result' => $result,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'decision_reason' => $reason,
        ])->save();

        $this->record($item, 'rejected', $actorId, $reason);
        $this->recordHistory($item, SyncRequestDecision::DECISION_REJECTED, $action, $reason, $actorId, $result);
        $this->requests->refresh($this->requestOf($item), $actorId);
    }

    /**
     * Append the decision to the immutable history. The row carries the
     * reviewer, time, action, reason, source device, request and operation
     * IDs, and the affected record, so a later resubmission never erases the
     * earlier decision.
     *
     * @param  array<string, mixed>  $result
     */
    private function recordHistory(
        SyncRequestItem $item,
        string $decision,
        string $action,
        ?string $reason,
        ?int $actorId,
        array $result,
    ): void {
        $request = $this->requestOf($item);

        SyncRequestDecision::create([
            'sync_request_id' => $request->id,
            'sync_request_item_id' => $item->id,
            'request_uuid' => $request->request_uuid,
            'operation_uuid' => $item->operation_uuid,
            'entity_type' => $item->entity_type,
            'entity_uuid' => $item->entity_uuid,
            'source_server_uuid' => $request->server?->server_uuid,
            'decision' => $decision,
            'action' => $action,
            'reason' => $reason,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'metadata' => [
                'code' => $result['code'] ?? null,
                'revision' => $result['revision'] ?? null,
                'merged_into' => $result['merged_into'] ?? null,
            ],
        ]);
    }

    /**
     * Deletion approvals and batch mappings are decided in the conflict
     * workspace, which has the type-specific choices (keep_record /
     * approve_deletion, map_batch / discard_batch). The per-record accept and
     * reject actions cannot express those decisions, so refuse them here
     * instead of failing halfway or leaving the conflict open.
     */
    private function assertNotConflictWorkspaceDecision(SyncRequestItem $item): void
    {
        if ($item->conflict_uuid === null) {
            return;
        }

        $operation = SyncConflict::query()
            ->where('conflict_uuid', $item->conflict_uuid)
            ->value('operation');

        if (in_array($operation, ['deleted', 'mapping'], true)) {
            throw new InvalidArgumentException(
                'Deletion approvals and batch mappings are decided in Conflict Resolution. Open the linked conflict to decide it.'
            );
        }
    }

    private function assertAwaitingReview(SyncRequestItem $item): void
    {
        if (! $item->isAwaitingReview()) {
            throw new InvalidArgumentException('This record is not awaiting review.');
        }
    }

    private function serverOf(SyncRequestItem $item)
    {
        $server = $this->requestOf($item)->server;

        if ($server === null) {
            throw new InvalidArgumentException('The offline server that submitted this request no longer exists.');
        }

        return $server;
    }

    private function requestOf(SyncRequestItem $item): SyncRequest
    {
        $request = $item->request;

        if (! $request instanceof SyncRequest) {
            throw new InvalidArgumentException('This record does not belong to a sync request.');
        }

        return $request;
    }

    private function record(SyncRequestItem $item, string $decision, ?int $actorId, ?string $reason): void
    {
        $request = $this->requestOf($item);

        SyncEvent::create([
            'offline_server_id' => $request->offline_server_id,
            'type' => 'request.item_'.$decision,
            'severity' => 'info',
            'message' => 'A submitted sync record was '.$decision.' during review.',
            'context' => [
                'request_uuid' => $request->request_uuid,
                'operation_uuid' => $item->operation_uuid,
                'entity_type' => $item->entity_type,
                'entity_uuid' => $item->entity_uuid,
                'decision' => $decision,
                'decided_by' => $actorId,
                'reason' => $reason,
            ],
        ]);

        AuditLogger::log(
            'sync.request_item_'.$decision,
            $item->entity_type,
            null,
            [
                'request_uuid' => $request->request_uuid,
                'operation_uuid' => $item->operation_uuid,
                'entity_uuid' => $item->entity_uuid,
                'decision' => $decision,
                'reason' => $reason,
            ],
            $actorId !== null ? User::query()->find($actorId) : null,
        );
    }
}
