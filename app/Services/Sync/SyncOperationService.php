<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\SyncChange;
use App\Models\SyncConflict;
use App\Models\SyncConflictDecision;
use App\Models\SyncEvent;
use App\Models\SyncRequestItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DuplicateDetector;
use App\Support\SyncIdentity;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class SyncOperationService
{
    private const BENEFICIARY_FIELDS = [
        'beneficiary_code',
        'source_id_number',
        'source_row_no',
        'first_name',
        'middle_name',
        'last_name',
        'extension_name',
        'birthdate',
        'birthdate_raw',
        'barangay',
        'city_municipality',
        'province',
        'district',
        'address',
        'location_code',
        'id_type',
        'id_type_other',
        'source_id_no',
        'cos_no',
        'contact_no',
        'contact_no_raw',
        'epayment_account_no',
        'beneficiary_type',
        'beneficiary_signify',
        'mode_of_payment',
        'occupation',
        'occupation_other',
        'sex',
        'civil_status',
        'age_source',
        'avg_monthly_income',
        'dependent_name',
        'dependent_contact_no',
        'employment_interest',
        'skills_training_needed',
        'notes',
    ];

    private const BENEFICIARY_BATCH_FIELDS = [
        'name',
        'description',
        'status',
    ];

    /**
     * Rejection codes that a retry may re-evaluate. They are policy decisions
     * (not data integrity failures), so an operation rejected under an older
     * policy must be able to succeed once the policy is corrected without
     * changing its operation UUID. Every other rejection stays terminal.
     *
     * @var array<int, string>
     */
    private const RETRYABLE_REJECTION_CODES = [
        'out_of_scope',
    ];

    public function __construct(
        private readonly ScopeService $scopes,
        private readonly DuplicateDetector $duplicates,
        private readonly SyncRequestService $requests,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<int, array<string, mixed>>
     */
    public function process(OfflineServer $server, array $operations): array
    {
        return array_map(fn (array $operation): array => $this->processOne($server, $operation), $operations);
    }

    /**
     * Apply an operation that an administrator explicitly approved after
     * review. The duplicate guard is bypassed because a human has already
     * compared the incoming record with its possible matches; the receipt is
     * rewritten so a later retry replays the decision instead of asking for
     * review again.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function applyApproved(OfflineServer $server, array $operation): array
    {
        $result = DB::transaction(fn (): array => $this->apply($server, $operation, true));

        $this->writeReceipt($server, $operation, $result);

        return $result;
    }

    /**
     * Persist the final outcome of a reviewed (not applied) operation so the
     * offline server receives it on its next retry and the receipt stays the
     * single source of truth for the operation UUID.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $result
     */
    public function recordDecision(OfflineServer $server, array $operation, array $result): void
    {
        $this->writeReceipt($server, $operation, $result);
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function resolveConflict(SyncConflict $conflict, string $action, array $fields = [], ?int $actorId = null): array
    {
        if ($conflict->status !== 'open') {
            throw new InvalidArgumentException('This conflict is already resolved.');
        }

        if ($conflict->operation === 'deleted') {
            return $this->resolveDeletionConflict($conflict, $action, $actorId);
        }

        if ($conflict->operation === 'mapping') {
            return $this->resolveMappingConflict($conflict, $action, $fields, $actorId);
        }

        $localPayload = $conflict->local_payload ?? [];
        $allowed = $conflict->entity_type === 'beneficiary'
            ? self::BENEFICIARY_FIELDS
            : self::BENEFICIARY_BATCH_FIELDS;

        $source = match ($action) {
            'keep_central' => [],
            'accept_local' => $localPayload,
            'merge' => Arr::only($localPayload, array_values(array_intersect($fields, $allowed))),
            default => throw new InvalidArgumentException('Unsupported conflict resolution action.'),
        };

        DB::transaction(function () use ($conflict, $action, $fields, $source, $actorId): void {
            if ($source !== []) {
                $this->applyResolvedConflictPayload($conflict, $source);
            }

            $this->clearConflictStatus($conflict);
            $this->settleReviewItem($conflict, $action, $actorId);

            $conflict->forceFill([
                'status' => 'resolved',
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution' => [
                    'action' => $action,
                    'fields' => array_values($fields),
                ],
            ])->save();

            $this->recordConflictDecision(
                $conflict,
                $action,
                $actorId,
                null,
                ['fields' => array_values($fields)],
            );

            SyncEvent::create([
                'offline_server_id' => $conflict->offline_server_id,
                'type' => 'conflict.resolved',
                'severity' => 'info',
                'message' => 'Sync conflict resolved.',
                'context' => [
                    'conflict_uuid' => $conflict->conflict_uuid,
                    'entity_type' => $conflict->entity_type,
                    'entity_uuid' => $conflict->entity_uuid,
                    'action' => $action,
                    'resolved_by' => $actorId,
                ],
            ]);
        });

        return [
            'conflict_uuid' => $conflict->conflict_uuid,
            'status' => 'resolved',
            'action' => $action,
        ];
    }

    /**
     * Converge the review queue when a field conflict is decided from the
     * conflict workspace: the linked upload item is settled, the operation's
     * receipt is rewritten, and the request outcome is written so the offline
     * installation learns the decision on its next pull. Without this the item
     * would remain pending forever (and reappear in the inbox mislabelled).
     */
    private function settleReviewItem(SyncConflict $conflict, string $action, ?int $actorId): void
    {
        $server = $conflict->server;

        if (! $server instanceof OfflineServer || $conflict->operation_uuid === null) {
            return;
        }

        $item = SyncRequestItem::query()
            ->where('operation_uuid', (string) $conflict->operation_uuid)
            ->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)
            ->first();

        if (! $item instanceof SyncRequestItem) {
            return;
        }

        $accepted = $action !== 'keep_central';
        $revision = $conflict->entity_type === 'beneficiary'
            ? (int) Beneficiary::query()->where('global_uuid', $conflict->entity_uuid)->value('revision')
            : (int) BeneficiaryBatch::withTrashed()->where('global_uuid', $conflict->entity_uuid)->value('revision');

        $result = $this->result(
            [
                'operation_uuid' => (string) $conflict->operation_uuid,
                'entity_type' => $conflict->entity_type,
                'entity_uuid' => $conflict->entity_uuid,
            ],
            $accepted ? 'applied' : 'rejected',
            $accepted ? null : 'reviewer_rejected',
            $accepted
                ? 'The submitted values were accepted after conflict review.'
                : 'The central record was kept; the submitted values were rejected.',
            ['revision' => $revision],
        );

        $item->forceFill([
            'status' => $accepted ? SyncRequestItem::STATUS_ACCEPTED : SyncRequestItem::STATUS_REJECTED,
            'result' => $result,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'decision_reason' => $result['message'],
        ])->save();

        $this->requests->refresh($item->request, $actorId);

        $this->recordDecision($server, [
            'operation_uuid' => (string) $conflict->operation_uuid,
            'entity_type' => $conflict->entity_type,
            'entity_uuid' => $conflict->entity_uuid,
        ], $result);
    }

    /**
     * Decide a held deletion request.
     *
     * keep_record: the central record is retained untouched and a change-log
     * row carrying its current values is emitted, so the requesting
     * installation restores its local copy on the next delta download. The
     * requesting operation's receipt is rewritten to 'rejected', ending its
     * retry cycle.
     *
     * approve_deletion: the central record is archived (never hard-deleted)
     * with an audited reason; the archive tombstone propagates to every
     * applicable installation through the normal delta log, and the
     * requesting receipt is rewritten to 'applied'.
     *
     * @return array<string, mixed>
     */
    private function resolveDeletionConflict(SyncConflict $conflict, string $action, ?int $actorId): array
    {
        if (! in_array($action, ['keep_record', 'approve_deletion'], true)) {
            throw new InvalidArgumentException('Unsupported deletion resolution action.');
        }

        $server = $conflict->server;

        if (! $server instanceof OfflineServer) {
            throw new InvalidArgumentException('The offline server that requested this deletion no longer exists.');
        }

        /** @var Beneficiary|null $beneficiary */
        $beneficiary = Beneficiary::query()
            ->where('global_uuid', $conflict->entity_uuid)
            ->lockForUpdate()
            ->first();

        if ($action === 'keep_record') {
            if (! $beneficiary instanceof Beneficiary) {
                throw new InvalidArgumentException('The beneficiary no longer exists on the central server.');
            }

            // Restore the requesting installation's copy through the delta
            // log: the row carries the authoritative central values.
            $this->emitRestoreChange($server, $beneficiary);

            $receiptResult = $this->result(
                ['operation_uuid' => $conflict->operation_uuid ?? '', 'entity_type' => 'beneficiary', 'entity_uuid' => $conflict->entity_uuid],
                'rejected',
                'deletion_rejected',
                'The deletion was rejected by a central administrator; the record was restored.',
            );

            AuditLogger::log('beneficiary.deletion_kept', 'beneficiary', $beneficiary->id, [
                'beneficiary_code' => $beneficiary->beneficiary_code,
                'conflict_uuid' => $conflict->conflict_uuid,
                'requested_by_server_uuid' => $server->server_uuid,
            ], User::query()->find($actorId));

            $this->recordConflictDecision(
                $conflict,
                'keep_record',
                $actorId,
                'The deletion was rejected; the record was kept and restored on the requesting installation.',
                ['requested_by_server_uuid' => $server->server_uuid],
            );
        } else {
            if (! $beneficiary instanceof Beneficiary) {
                throw new InvalidArgumentException('The beneficiary no longer exists on the central server.');
            }

            // The archival tombstone is authoritative and reversible; the
            // RecordsSyncChanges trait emits the delta row for every
            // applicable installation.
            $beneficiary->archive('Deletion approved by central administrator.');

            $receiptResult = $this->result(
                ['operation_uuid' => $conflict->operation_uuid ?? '', 'entity_type' => 'beneficiary', 'entity_uuid' => $conflict->entity_uuid],
                'applied',
                'deletion_approved',
                'The deletion was approved by a central administrator.',
                ['revision' => $beneficiary->revision],
            );

            AuditLogger::log('beneficiary.deletion_approved', 'beneficiary', $beneficiary->id, [
                'beneficiary_code' => $beneficiary->beneficiary_code,
                'conflict_uuid' => $conflict->conflict_uuid,
                'requested_by_server_uuid' => $server->server_uuid,
            ], User::query()->find($actorId));

            $this->recordConflictDecision(
                $conflict,
                'approve_deletion',
                $actorId,
                'The deletion was approved; the central record was archived as a tombstone.',
                ['requested_by_server_uuid' => $server->server_uuid],
            );
        }

        // The requesting installation learns the decision through its request
        // outcomes: mark the held item decided so the outcome endpoint serves
        // it and the offline operation settles (synced on approval, rejected
        // on keep).
        $heldItem = SyncRequestItem::query()
            ->where('operation_uuid', (string) ($conflict->operation_uuid ?? ''))
            ->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)
            ->first();

        if ($heldItem instanceof SyncRequestItem) {
            $heldItem->forceFill([
                'status' => $action === 'keep_record'
                    ? SyncRequestItem::STATUS_REJECTED
                    : SyncRequestItem::STATUS_ACCEPTED,
                'result' => $receiptResult,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_reason' => $receiptResult['message'] ?? null,
            ])->save();

            $this->requests->refresh($heldItem->request, $actorId);
        }

        DB::transaction(function () use ($conflict, $action, $actorId): void {
            $conflict->forceFill([
                'status' => 'resolved',
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution' => ['action' => $action],
            ])->save();

            SyncEvent::create([
                'offline_server_id' => $conflict->offline_server_id,
                'type' => 'conflict.resolved',
                'severity' => 'info',
                'message' => $action === 'keep_record'
                    ? 'Deletion request rejected — record kept.'
                    : 'Deletion request approved — record archived.',
                'context' => [
                    'conflict_uuid' => $conflict->conflict_uuid,
                    'entity_type' => $conflict->entity_type,
                    'entity_uuid' => $conflict->entity_uuid,
                    'action' => $action,
                    'resolved_by' => $actorId,
                ],
            ]);
        });

        // The receipt is the single source of truth for the requesting
        // operation UUID: the offline retry replays this decision.
        $this->recordDecision($server, [
            'operation_uuid' => $conflict->operation_uuid ?? '',
            'entity_type' => 'beneficiary',
            'entity_uuid' => $conflict->entity_uuid,
        ], $receiptResult);

        return [
            'conflict_uuid' => $conflict->conflict_uuid,
            'status' => 'resolved',
            'action' => $action,
        ];
    }

    /**
     * Emit a delta row that recreates/restores the requesting installation's
     * local copy of a record whose deletion was rejected.
     */
    private function emitRestoreChange(OfflineServer $server, Beneficiary $beneficiary): void
    {
        SyncChange::query()->updateOrCreate(
            ['change_uuid' => 'restore-'.($beneficiary->last_operation_uuid ?? $beneficiary->global_uuid)],
            [
                'offline_server_id' => $server->id,
                'entity_type' => 'beneficiary',
                'entity_uuid' => $beneficiary->global_uuid,
                'operation' => 'updated',
                'origin_server_uuid' => $beneficiary->origin_server_uuid,
                'revision' => $beneficiary->revision,
                'payload' => [
                    'changed_fields' => ['deleted'],
                    'data' => $beneficiary->only(array_merge(['beneficiary_code'], self::BENEFICIARY_FIELDS)),
                ],
                'status' => 'pending',
                'applied_at' => now(),
            ],
        );
    }

    /**
     * Hold creations that reference a batch the central server has never
     * seen. All held members of the same batch coalesce into one mapping
     * approval; resolving it applies the held records into the mapped batch
     * through the normal review pipeline.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function holdForBatchMapping(OfflineServer $server, array $operation, string $batchUuid): array
    {
        $existing = SyncConflict::query()
            ->where('entity_type', 'beneficiary_batch')
            ->where('entity_uuid', $batchUuid)
            ->where('operation', 'mapping')
            ->where('status', 'open')
            ->first();

        if ($existing instanceof SyncConflict) {
            return $this->result($operation, 'awaiting_review', 'batch_mapping_pending',
                'The record\'s batch is not known to the central server; held until the batch is mapped.', [
                    'conflict_uuid' => $existing->conflict_uuid,
                ]);
        }

        $conflict = SyncConflict::create([
            'conflict_uuid' => SyncIdentity::newUuid(),
            'offline_server_id' => $server->id,
            'entity_type' => 'beneficiary_batch',
            'entity_uuid' => $batchUuid,
            'operation' => 'mapping',
            'operation_uuid' => $operation['operation_uuid'],
            'origin_server_uuid' => $server->server_uuid,
            'local_payload' => [
                'known_on_central' => false,
            ],
            'remote_payload' => [
                'requested_by_server_uuid' => $server->server_uuid,
                'requested_by_server_name' => $server->name,
                'batch_uuid' => $batchUuid,
                'operation_uuid' => $operation['operation_uuid'],
            ],
            'diff' => ['beneficiary_batch_uuid'],
            'status' => 'open',
        ]);

        SyncEvent::create([
            'offline_server_id' => $server->id,
            'type' => 'operation.batch_mapping_hold',
            'severity' => 'warning',
            'message' => 'Records held: their batch is not known to the central server.',
            'context' => [
                'conflict_uuid' => $conflict->conflict_uuid,
                'batch_uuid' => $batchUuid,
                'operation_uuid' => $operation['operation_uuid'],
            ],
        ]);

        return $this->result($operation, 'awaiting_review', 'batch_mapping_pending',
            'The record\'s batch is not known to the central server; held until the batch is mapped.', [
                'conflict_uuid' => $conflict->conflict_uuid,
            ]);
    }

    /**
     * Decide a held batch mapping.
     *
     * map_batch with new_batch_name: the central batch is created under the
     * offline installation's batch UUID, and every held record is applied
     * into it. map_batch with target_batch_id: the held records are applied
     * into the existing central batch. discard_batch: the held records are
     * rejected with a clear reason and stay on the offline installation.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function resolveMappingConflict(SyncConflict $conflict, string $action, array $fields, ?int $actorId): array
    {
        $server = $conflict->server;

        if (! $server instanceof OfflineServer) {
            throw new InvalidArgumentException('The offline server that submitted these records no longer exists.');
        }

        $batchUuid = (string) $conflict->entity_uuid;

        $heldItems = SyncRequestItem::query()
            ->whereHas('request', fn ($query) => $query->where('offline_server_id', $server->id))
            ->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)
            ->where('payload->beneficiary_batch_uuid', $batchUuid)
            ->orderBy('id')
            ->get();

        if ($action === 'discard_batch') {
            foreach ($heldItems as $item) {
                $this->recordDecision($server, $this->requests->operationFor($item), $this->result(
                    $this->requests->operationFor($item),
                    'rejected',
                    'batch_discarded',
                    'The record\'s batch was discarded during mapping review; the record stays on the offline installation.',
                ));

                $this->requests->applyResult($item, [
                    'operation_uuid' => $item->operation_uuid,
                    'status' => 'rejected',
                    'code' => 'batch_discarded',
                    'message' => 'The record\'s batch was discarded during mapping review.',
                ]);
            }

            AuditLogger::log('beneficiary_batch.mapping_discarded', 'beneficiary_batch', null, [
                'batch_uuid' => $batchUuid,
                'held_records' => $heldItems->count(),
                'requested_by_server_uuid' => $server->server_uuid,
            ], User::query()->find($actorId));

            $this->recordConflictDecision(
                $conflict,
                'discard_batch',
                $actorId,
                'The unknown batch was discarded; '.$heldItems->count().' held record(s) stay on the requesting installation.',
                ['requested_by_server_uuid' => $server->server_uuid, 'held_records' => $heldItems->count()],
            );

            $this->completeResolution($conflict, $action, $actorId);

            return [
                'conflict_uuid' => $conflict->conflict_uuid,
                'status' => 'resolved',
                'action' => $action,
            ];
        }

        if ($action !== 'map_batch') {
            throw new InvalidArgumentException('Unsupported mapping resolution action.');
        }

        $targetBatchId = isset($fields['target_batch_id']) ? (int) $fields['target_batch_id'] : null;
        $newBatchName = trim((string) ($fields['new_batch_name'] ?? ''));
        $targetBatch = null;

        if ($targetBatchId !== null && $targetBatchId > 0) {
            $targetBatch = BeneficiaryBatch::query()->find($targetBatchId);

            if (! $targetBatch instanceof BeneficiaryBatch) {
                throw new InvalidArgumentException('The selected central batch no longer exists.');
            }
        } elseif ($newBatchName !== '') {
            $nameExists = BeneficiaryBatch::withTrashed()
                ->where('origin_server_uuid', $server->server_uuid)
                ->where('name', $newBatchName)
                ->exists();

            if ($nameExists) {
                throw new InvalidArgumentException('This installation already has a batch with that name.');
            }

            // The central batch adopts the offline installation's batch UUID,
            // so the held records — and every future upload referencing that
            // UUID — map here naturally.
            $targetBatch = BeneficiaryBatch::create([
                'global_uuid' => $batchUuid,
                'origin_server_uuid' => $server->server_uuid,
                'name' => $newBatchName,
                'status' => BeneficiaryBatch::STATUS_OPEN,
                'created_by' => $actorId,
            ]);
        } else {
            throw new InvalidArgumentException('Choose an existing batch or enter a new batch name.');
        }

        $applied = 0;

        foreach ($heldItems as $item) {
            $held = $this->requests->operationFor($item);

            // When mapping onto an existing central batch, the held payload's
            // unknown UUID is replaced by the target's.
            $held['payload']['beneficiary_batch_uuid'] = $targetBatch->global_uuid;

            $result = $this->applyApproved($server, $held);

            // Follow the review-pipeline convention: a decided item is
            // ACCEPTED so the outcome endpoint delivers it and the offline
            // operation settles as synced.
            $item->forceFill([
                'status' => SyncRequestItem::STATUS_ACCEPTED,
                'result' => $result,
                'decided_by' => $actorId,
                'decided_at' => now(),
                'decision_reason' => $result['message'] ?? null,
            ])->save();

            $this->requests->refresh($item->request, $actorId);
            $applied++;
        }

        AuditLogger::log('beneficiary_batch.mapping_resolved', 'beneficiary_batch', $targetBatch->id, [
            'batch_uuid' => $batchUuid,
            'mapped_to' => $targetBatch->name,
            'applied_records' => $applied,
            'requested_by_server_uuid' => $server->server_uuid,
        ], User::query()->find($actorId));

        $this->recordConflictDecision(
            $conflict,
            'map_batch',
            $actorId,
            $targetBatch->global_uuid === $batchUuid
                ? 'The batch was created centrally under the installation\'s batch UUID; '.$applied.' held record(s) applied.'
                : 'The batch was mapped onto an existing central batch; '.$applied.' held record(s) applied.',
            ['batch_uuid' => $batchUuid, 'mapped_to' => $targetBatch->name, 'applied_records' => $applied],
        );

        $this->completeResolution($conflict, $action, $actorId);

        return [
            'conflict_uuid' => $conflict->conflict_uuid,
            'status' => 'resolved',
            'action' => $action,
            'applied' => $applied,
        ];
    }

    private function completeResolution(SyncConflict $conflict, string $action, ?int $actorId): void
    {
        DB::transaction(function () use ($conflict, $action, $actorId): void {
            $conflict->forceFill([
                'status' => 'resolved',
                'resolved_by' => $actorId,
                'resolved_at' => now(),
                'resolution' => ['action' => $action],
            ])->save();

            SyncEvent::create([
                'offline_server_id' => $conflict->offline_server_id,
                'type' => 'conflict.resolved',
                'severity' => 'info',
                'message' => 'Batch mapping conflict resolved.',
                'context' => [
                    'conflict_uuid' => $conflict->conflict_uuid,
                    'entity_type' => $conflict->entity_type,
                    'entity_uuid' => $conflict->entity_uuid,
                    'action' => $action,
                    'resolved_by' => $actorId,
                ],
            ]);
        });
    }

    /**
     * Append an immutable entry to a conflict's decision history. Never
     * updated or deleted — later resolutions of the same entity add rows.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordConflictDecision(
        SyncConflict $conflict,
        string $decision,
        ?int $actorId,
        ?string $reason = null,
        ?array $metadata = null,
    ): void {
        SyncConflictDecision::create([
            'conflict_uuid' => $conflict->conflict_uuid,
            'sync_conflict_id' => $conflict->id,
            'entity_type' => $conflict->entity_type,
            'entity_uuid' => $conflict->entity_uuid,
            'operation' => $conflict->operation,
            'decision' => $decision,
            'reason' => $reason,
            'resolved_by' => $actorId,
            'decided_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    private function clearConflictStatus(SyncConflict $conflict): void
    {
        if ($conflict->entity_type === 'beneficiary') {
            Beneficiary::query()
                ->where('global_uuid', $conflict->entity_uuid)
                ->update(['conflict_status' => 'none']);

            return;
        }

        BeneficiaryBatch::withTrashed()
            ->where('global_uuid', $conflict->entity_uuid)
            ->update(['conflict_status' => 'none']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyResolvedConflictPayload(SyncConflict $conflict, array $payload): void
    {
        $server = $conflict->server;

        if ($conflict->entity_type === 'beneficiary') {
            $beneficiary = Beneficiary::query()
                ->where('global_uuid', $conflict->entity_uuid)
                ->lockForUpdate()
                ->firstOrFail();

            $fields = $this->beneficiaryFields($payload);
            unset($fields['beneficiary_code']);

            if (isset($fields['location_code'])
                && ! $this->scopes->allowsLocationCode($server, (string) $fields['location_code'])) {
                throw new InvalidArgumentException('The resolved location is outside this server scope.');
            }

            $fields['revision'] = $beneficiary->revision + 1;
            $fields['conflict_status'] = 'none';
            $fields['last_operation_uuid'] = $conflict->conflict_uuid;

            $beneficiary->fill($fields)->save();

            return;
        }

        $batch = BeneficiaryBatch::withTrashed()
            ->where('global_uuid', $conflict->entity_uuid)
            ->lockForUpdate()
            ->firstOrFail();

        $fields = $this->batchFields($payload);

        if (isset($fields['name'])) {
            $nameExists = BeneficiaryBatch::withTrashed()
                ->where('origin_server_uuid', $batch->origin_server_uuid)
                ->where('name', $fields['name'])
                ->where('id', '!=', $batch->id)
                ->exists();

            if ($nameExists) {
                throw new InvalidArgumentException('The resolved batch name already exists for this origin.');
            }
        }

        $fields['revision'] = $batch->revision + 1;
        $fields['conflict_status'] = 'none';
        $fields['last_operation_uuid'] = $conflict->conflict_uuid;

        $batch->fill($fields)->save();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function processOne(OfflineServer $server, array $operation): array
    {
        $operationUuid = (string) $operation['operation_uuid'];

        $receipt = SyncChange::query()
            ->where('change_uuid', $operationUuid)
            ->first();

        $previousResult = null;

        if ($receipt instanceof SyncChange) {
            if ((int) $receipt->offline_server_id !== $server->id) {
                return $this->result($operation, 'rejected', 'operation_owned_by_another_server', 'This operation UUID belongs to another server.');
            }

            /** @var array<string, mixed>|null $stored */
            $stored = data_get($receipt->payload, 'result');

            if (! is_array($stored)) {
                return $this->result($operation, 'rejected', 'operation_already_processed', 'This operation was already processed.');
            }

            // A retry is honoured only when the previous rejection came from a
            // policy that has since changed. Everything else is replayed from
            // the receipt, keeping retries idempotent.
            if (! $this->isRetryableRejection($stored)) {
                return $stored;
            }

            $previousResult = $stored;
        }

        try {
            $result = DB::transaction(fn (): array => $this->apply($server, $operation));
        } catch (Throwable $exception) {
            report($exception);

            $result = $this->result(
                $operation,
                'rejected',
                'server_error',
                'The operation could not be applied because of a server error.',
            );
        }

        $this->writeReceipt($server, $operation, $result, $previousResult);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isRetryableRejection(array $result): bool
    {
        return ($result['status'] ?? null) === 'rejected'
            && in_array($result['code'] ?? null, self::RETRYABLE_REJECTION_CODES, true);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function apply(OfflineServer $server, array $operation, bool $allowDuplicate = false): array
    {
        $entityType = (string) $operation['entity_type'];
        $entityUuid = (string) $operation['entity_uuid'];
        $action = (string) $operation['operation'];
        $payload = $operation['payload'] ?? [];
        $baseRevision = isset($operation['base_revision']) ? (int) $operation['base_revision'] : null;

        if (! is_array($payload)) {
            return $this->result($operation, 'rejected', 'invalid_payload', 'The operation payload must be an object.');
        }

        return match ($entityType) {
            'beneficiary' => $this->applyBeneficiary($server, $operation, $entityUuid, $action, $payload, $baseRevision, $allowDuplicate),
            'beneficiary_batch' => $this->applyBatch($server, $operation, $entityUuid, $action, $payload, $baseRevision, $allowDuplicate),
            default => $this->result($operation, 'rejected', 'unsupported_entity', 'This entity type cannot be synchronized.'),
        };
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyBeneficiary(
        OfflineServer $server,
        array $operation,
        string $entityUuid,
        string $action,
        array $payload,
        ?int $baseRevision,
        bool $allowDuplicate = false,
    ): array {
        /** @var Beneficiary|null $beneficiary */
        $beneficiary = Beneficiary::query()
            ->where('global_uuid', $entityUuid)
            ->lockForUpdate()
            ->first();

        // A beneficiary "deleted" operation is a destructive, terminal
        // tombstone: the authorized offline operator permanently removed the
        // record. It is applied as a hard delete, never as an archive, and is
        // idempotent (a replayed delete of a missing record is a no-op).
        if ($action === 'deleted') {
            return $this->deleteBeneficiary($server, $operation, $beneficiary);
        }

        if ($action === 'created') {
            if ($beneficiary instanceof Beneficiary) {
                return $this->result($operation, 'duplicate', 'entity_already_exists', 'A beneficiary with this global UUID already exists.', [
                    'revision' => $beneficiary->revision,
                ]);
            }

            $fields = $this->beneficiaryFields($payload);

            if (trim((string) ($fields['first_name'] ?? '')) === '' || trim((string) ($fields['last_name'] ?? '')) === '') {
                return $this->result($operation, 'rejected', 'validation_failed', 'First name and last name are required.');
            }

            $locationCode = trim((string) ($fields['location_code'] ?? ''));

            if ($locationCode === '') {
                return $this->result($operation, 'rejected', 'validation_failed', 'A beneficiary location code is required.');
            }

            // Central-master batch integrity: a record that belongs to a batch
            // the central server has never seen is held until an administrator
            // maps the batch — silently dropping the linkage would create an
            // unbatched record with no operator-visible signal.
            $batchUuid = trim((string) ($payload['beneficiary_batch_uuid'] ?? ''));

            if ($batchUuid !== '' && ! BeneficiaryBatch::withTrashed()->where('global_uuid', $batchUuid)->exists()) {
                return $this->holdForBatchMapping($server, $operation, $batchUuid);
            }

            // The configured scope decides which records the central sends to
            // this server (distribution), not what an authorized server may
            // upload. The location is stored as submitted.

            $duplicates = $this->duplicates->find(
                $fields['beneficiary_code'] ?? null,
                $fields['source_id_number'] ?? null,
                $fields['source_id_no'] ?? null,
                $fields['first_name'] ?? null,
                $fields['middle_name'] ?? null,
                $fields['last_name'] ?? null,
                isset($fields['birthdate']) ? (string) $fields['birthdate'] : null,
            );

            if (! $allowDuplicate && $duplicates !== null && in_array($duplicates['rule'], ['beneficiary_code', 'source_id_number', 'source_id_no'], true)) {
                return $this->duplicateResult($operation, $duplicates);
            }

            $fields['global_uuid'] = $entityUuid;
            $fields['origin_server_uuid'] = $server->server_uuid;
            $fields['revision'] = 1;
            $fields['server_revision'] = 1;
            $fields['sync_status'] = 'synced';
            $fields['last_operation_uuid'] = $operation['operation_uuid'];
            $fields['duplicate_status'] = $duplicates === null ? 'none' : 'candidate';
            $fields['duplicate_rule'] = $duplicates['rule'] ?? null;

            $created = Beneficiary::createWithCode($fields);

            return $this->result($operation, 'applied', null, null, [
                'revision' => $created->revision,
                'duplicate_rule' => $duplicates['rule'] ?? null,
            ]);
        }

        if (! $beneficiary instanceof Beneficiary) {
            return $this->result($operation, 'rejected', 'not_found', 'The beneficiary does not exist on the central server.');
        }

        $isArchived = $beneficiary->archived_at !== null;

        if ($isArchived && $action === 'updated') {
            $remotePayload = array_merge(
                $beneficiary->only(self::BENEFICIARY_FIELDS),
                ['archived_at' => $beneficiary->archived_at, 'archive_reason' => $beneficiary->archive_reason]
            );

            return $this->conflict(
                $server,
                $operation,
                $beneficiary->global_uuid,
                $beneficiary->origin_server_uuid,
                $payload,
                $remotePayload,
                $beneficiary->revision,
                ['archived_at'],
            );
        }

        if ($baseRevision !== null && (int) $beneficiary->revision !== $baseRevision) {
            return $this->conflictOrMerge(
                $server,
                $operation,
                $beneficiary->global_uuid,
                $beneficiary->origin_server_uuid,
                $payload,
                $beneficiary->only(self::BENEFICIARY_FIELDS),
                $beneficiary->revision,
                $beneficiary,
                'beneficiary',
            );
        }

        if ($action === 'archived') {
            $beneficiary->forceFill([
                'archived_at' => $payload['archived_at'] ?? now(),
                'archive_reason' => $payload['archive_reason'] ?? $beneficiary->archive_reason,
                'revision' => $beneficiary->revision + 1,
                'last_operation_uuid' => $operation['operation_uuid'],
            ])->save();

            return $this->result($operation, 'applied', null, null, ['revision' => $beneficiary->revision]);
        }

        if (in_array($action, ['restored', 'unarchived'], true)) {
            $beneficiary->forceFill([
                'archived_at' => null,
                'archive_reason' => null,
                'revision' => $beneficiary->revision + 1,
                'last_operation_uuid' => $operation['operation_uuid'],
            ])->save();

            return $this->result($operation, 'applied', null, null, ['revision' => $beneficiary->revision]);
        }

        if ($action !== 'updated') {
            return $this->result($operation, 'rejected', 'unsupported_operation', 'This beneficiary operation is not supported.');
        }

        $fields = $this->beneficiaryFields($payload);
        unset($fields['beneficiary_code']);

        if (isset($fields['location_code'])) {
            $incomingLocation = trim((string) $fields['location_code']);

            if ($incomingLocation === '') {
                return $this->result($operation, 'rejected', 'validation_failed', 'The beneficiary location code cannot be empty.');
            }

            $fields['location_code'] = $incomingLocation;
        }

        $duplicates = $this->duplicates->find(
            null,
            $fields['source_id_number'] ?? $beneficiary->source_id_number,
            $fields['source_id_no'] ?? $beneficiary->source_id_no,
            $fields['first_name'] ?? $beneficiary->first_name,
            $fields['middle_name'] ?? $beneficiary->middle_name,
            $fields['last_name'] ?? $beneficiary->last_name,
            isset($fields['birthdate']) ? (string) $fields['birthdate'] : $beneficiary->birthdate?->toDateString(),
            $beneficiary->id,
        );

        if (! $allowDuplicate && $duplicates !== null && in_array($duplicates['rule'], ['source_id_number', 'source_id_no'], true)) {
            return $this->duplicateResult($operation, $duplicates);
        }

        $fields['revision'] = $beneficiary->revision + 1;
        $fields['last_operation_uuid'] = $operation['operation_uuid'];

        if ($duplicates !== null && $beneficiary->duplicate_status !== 'confirmed_duplicate') {
            $fields['duplicate_status'] = 'candidate';
            $fields['duplicate_rule'] = $duplicates['rule'];
        } elseif ($duplicates === null && $beneficiary->duplicate_status === 'candidate') {
            $fields['duplicate_status'] = 'none';
            $fields['duplicate_rule'] = null;
        }

        $beneficiary->fill($fields)->save();

        return $this->result($operation, 'applied', null, null, ['revision' => $beneficiary->revision]);
    }

    /**
     * Permanently remove a beneficiary in response to an authorized offline
     * deletion. The audit snapshot is written before the row disappears, and
     * any open review/conflict for the record is closed because the record it
     * referred to no longer exists.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function deleteBeneficiary(OfflineServer $server, array $operation, ?Beneficiary $beneficiary): array
    {
        if (! $beneficiary instanceof Beneficiary) {
            // Already gone: a replayed tombstone is a successful no-op.
            return $this->result($operation, 'applied', null, null, ['revision' => 0]);
        }

        if ($beneficiary->archived_at !== null) {
            // Already tombstoned: the requested end state is achieved, so the
            // deletion settles without a second approval round.
            return $this->result($operation, 'applied', 'already_archived',
                'The record is already archived on the central server.', [
                    'revision' => $beneficiary->revision,
                ]);
        }

        // Central-master safety: an offline deletion of a record the central
        // server knows is never applied silently. It is held as a deletion
        // approval for an administrator (Keep the record / Approve the
        // deletion). The requesting operation stays pending on the offline
        // server until the decision is written onto its receipt.
        if ($server->deletionApprovalRequired()) {
            return $this->holdDeletionForApproval($server, $operation, $beneficiary);
        }

        $payload = $operation['payload'] ?? [];
        $reason = is_array($payload) ? ($payload['delete_reason'] ?? null) : null;

        AuditLogger::log('beneficiary.deleted', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
            'full_name' => $beneficiary->full_name,
            'location_code' => $beneficiary->location_code,
            'beneficiary_batch_uuid' => $beneficiary->beneficiaryBatch?->global_uuid,
            'revision' => $beneficiary->revision,
            'origin_server_uuid' => $beneficiary->origin_server_uuid,
            'requested_by_server_uuid' => $server->server_uuid,
            'operation_uuid' => $operation['operation_uuid'],
            'delete_reason' => $reason,
        ]);

        SyncConflict::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->where('status', 'open')
            ->get()
            ->each(function (SyncConflict $openConflict): void {
                $openConflict->forceFill([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolution' => ['action' => 'record_deleted'],
                ])->save();

                $this->recordConflictDecision(
                    $openConflict,
                    'record_deleted',
                    null,
                    'The record was deleted with the approval gate relaxed on the requesting installation.',
                );
            });

        $revision = $beneficiary->revision;

        $beneficiary->delete();

        return $this->result($operation, 'applied', null, null, ['revision' => $revision]);
    }

    /**
     * Hold an offline deletion for central approval. Repeated submissions of
     * the same deletion resolve to the one open approval — the conflict never
     * disappears because a sync was retried.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function holdDeletionForApproval(OfflineServer $server, array $operation, Beneficiary $beneficiary): array
    {
        $existing = SyncConflict::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->where('operation', 'deleted')
            ->where('status', 'open')
            ->first();

        if ($existing instanceof SyncConflict) {
            return $this->result($operation, 'awaiting_review', 'deletion_pending_approval',
                'The deletion is waiting for central administrator approval.', [
                    'conflict_uuid' => $existing->conflict_uuid,
                    'revision' => $beneficiary->revision,
                ]);
        }

        $payload = $operation['payload'] ?? [];

        $conflict = SyncConflict::create([
            'conflict_uuid' => SyncIdentity::newUuid(),
            'offline_server_id' => $server->id,
            'entity_type' => 'beneficiary',
            'entity_uuid' => $beneficiary->global_uuid,
            'operation' => 'deleted',
            'operation_uuid' => $operation['operation_uuid'],
            'origin_server_uuid' => $beneficiary->origin_server_uuid,
            // "Local" is what central currently holds; "remote" is the
            // deletion the offline server has asked for.
            'local_payload' => array_merge(
                $beneficiary->only(array_merge(['beneficiary_code'], self::BENEFICIARY_FIELDS)),
                ['archived_at' => $beneficiary->archived_at, 'archive_reason' => $beneficiary->archive_reason],
            ),
            'remote_payload' => [
                'requested_by_server_uuid' => $server->server_uuid,
                'requested_by_server_name' => $server->name,
                'delete_reason' => is_array($payload) ? ($payload['delete_reason'] ?? null) : null,
                'operation_uuid' => $operation['operation_uuid'],
            ],
            'diff' => ['deleted'],
            'status' => 'open',
        ]);

        SyncEvent::create([
            'offline_server_id' => $server->id,
            'type' => 'operation.deletion_hold',
            'severity' => 'warning',
            'message' => 'Beneficiary deletion held for central approval.',
            'context' => [
                'conflict_uuid' => $conflict->conflict_uuid,
                'entity_type' => 'beneficiary',
                'entity_uuid' => $beneficiary->global_uuid,
                'beneficiary_code' => $beneficiary->beneficiary_code,
                'operation_uuid' => $operation['operation_uuid'],
            ],
        ]);

        return $this->result($operation, 'awaiting_review', 'deletion_pending_approval',
            'The deletion is waiting for central administrator approval.', [
                'conflict_uuid' => $conflict->conflict_uuid,
                'revision' => $beneficiary->revision,
            ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyBatch(
        OfflineServer $server,
        array $operation,
        string $entityUuid,
        string $action,
        array $payload,
        ?int $baseRevision,
        bool $allowDuplicate = false,
    ): array {
        /** @var BeneficiaryBatch|null $batch */
        $batch = BeneficiaryBatch::withTrashed()
            ->where('global_uuid', $entityUuid)
            ->lockForUpdate()
            ->first();

        if ($action === 'created') {
            if ($batch instanceof BeneficiaryBatch) {
                return $this->result($operation, 'duplicate', 'entity_already_exists', 'A batch with this global UUID already exists.', [
                    'revision' => $batch->revision,
                ]);
            }

            $fields = $this->batchFields($payload);

            if (trim((string) ($fields['name'] ?? '')) === '') {
                return $this->result($operation, 'rejected', 'validation_failed', 'A batch name is required.');
            }

            $nameExists = BeneficiaryBatch::withTrashed()
                ->where('origin_server_uuid', $server->server_uuid)
                ->where('name', $fields['name'])
                ->exists();

            if (! $allowDuplicate && $nameExists) {
                return $this->result($operation, 'duplicate', 'duplicate_batch_name', 'This server already has a batch with that name.');
            }

            $fields['global_uuid'] = $entityUuid;
            $fields['origin_server_uuid'] = $server->server_uuid;
            $fields['revision'] = 1;
            $fields['server_revision'] = 1;
            $fields['sync_status'] = 'synced';
            $fields['last_operation_uuid'] = $operation['operation_uuid'];
            $fields['status'] ??= BeneficiaryBatch::STATUS_OPEN;

            $created = BeneficiaryBatch::create($fields);

            return $this->result($operation, 'applied', null, null, ['revision' => $created->revision]);
        }

        if (! $batch instanceof BeneficiaryBatch) {
            return $this->result($operation, 'rejected', 'not_found', 'The batch does not exist on the central server.');
        }

        $isDeleted = $batch->trashed();

        if ($isDeleted && $action === 'updated') {
            return $this->conflict(
                $server,
                $operation,
                $batch->global_uuid,
                $batch->origin_server_uuid,
                $payload,
                $batch->only(self::BENEFICIARY_BATCH_FIELDS),
                $batch->revision,
                ['deleted_at'],
            );
        }

        if ($baseRevision !== null && (int) $batch->revision !== $baseRevision) {
            return $this->conflictOrMerge(
                $server,
                $operation,
                $batch->global_uuid,
                $batch->origin_server_uuid,
                $payload,
                $batch->only(self::BENEFICIARY_BATCH_FIELDS),
                $batch->revision,
                $batch,
                'beneficiary_batch',
            );
        }

        if ($action === 'deleted') {
            if ($batch->trashed()) {
                return $this->result($operation, 'applied', null, null, ['revision' => $batch->revision]);
            }

            $batch->forceFill([
                'revision' => $batch->revision + 1,
                'last_operation_uuid' => $operation['operation_uuid'],
            ])->save();
            $batch->delete();

            return $this->result($operation, 'applied', null, null, ['revision' => $batch->revision]);
        }

        if ($action === 'restored') {
            if ($batch->trashed()) {
                $batch->restore();
            }

            $batch->forceFill([
                'revision' => $batch->revision + 1,
                'last_operation_uuid' => $operation['operation_uuid'],
            ])->save();

            return $this->result($operation, 'applied', null, null, ['revision' => $batch->revision]);
        }

        if ($action !== 'updated') {
            return $this->result($operation, 'rejected', 'unsupported_operation', 'This batch operation is not supported.');
        }

        $fields = $this->batchFields($payload);

        if (isset($fields['name'])) {
            $nameExists = BeneficiaryBatch::withTrashed()
                ->where('origin_server_uuid', $batch->origin_server_uuid)
                ->where('name', $fields['name'])
                ->where('id', '!=', $batch->id)
                ->exists();

            if (! $allowDuplicate && $nameExists) {
                return $this->result($operation, 'duplicate', 'duplicate_batch_name', 'This origin already has a batch with that name.');
            }
        }

        $fields['revision'] = $batch->revision + 1;
        $fields['last_operation_uuid'] = $operation['operation_uuid'];

        $batch->fill($fields)->save();

        return $this->result($operation, 'applied', null, null, ['revision' => $batch->revision]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function beneficiaryFields(array $payload): array
    {
        $fields = Arr::only($payload, self::BENEFICIARY_FIELDS);

        if (array_key_exists('birthdate', $fields) && $fields['birthdate'] === '') {
            $fields['birthdate'] = null;
        }

        if (isset($payload['beneficiary_batch_uuid'])) {
            $fields['beneficiary_batch_id'] = BeneficiaryBatch::withTrashed()
                ->where('global_uuid', $payload['beneficiary_batch_uuid'])
                ->value('id');
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function batchFields(array $payload): array
    {
        $fields = Arr::only($payload, self::BENEFICIARY_BATCH_FIELDS);

        if (isset($fields['status'])) {
            $fields['status'] = $fields['status'] === BeneficiaryBatch::STATUS_CLOSED
                ? BeneficiaryBatch::STATUS_CLOSED
                : BeneficiaryBatch::STATUS_OPEN;
        }

        return $fields;
    }

    /**
     * Attempt a field-level auto-merge. If the incoming changed_fields do not overlap
     * with any fields in the current remote payload, the non-overlapping fields are
     * applied directly and an 'applied' result is returned. If fields do overlap,
     * a SyncConflict is recorded and a 'conflict' result is returned.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $localPayload
     * @param  array<string, mixed>  $remotePayload
     * @return array<string, mixed>
     */
    private function conflictOrMerge(
        OfflineServer $server,
        array $operation,
        string $entityUuid,
        string $originServerUuid,
        array $localPayload,
        array $remotePayload,
        int $remoteRevision,
        Beneficiary|BeneficiaryBatch $model,
        string $entityType,
    ): array {
        // Which fields did the incoming offline operation actually change?
        $changedFields = is_array($operation['changed_fields'] ?? null)
            ? $operation['changed_fields']
            : array_keys($localPayload);

        // A field truly conflicts only when ALL of the following are true:
        // 1. The offline operation explicitly changed it (it's in $changedFields).
        // 2. The central record has a non-null, non-empty value for that field.
        // 3. That central value differs from what the local operation proposes.
        //
        // Rationale: if the central value is null/empty, the central server never
        // explicitly set that field. The local op is safe to write it without conflict.
        $overlapping = [];

        foreach ($changedFields as $field) {
            if (! array_key_exists($field, $remotePayload)) {
                continue; // Remote doesn't have this field — no conflict.
            }

            $localValue = $localPayload[$field] ?? null;
            $remoteValue = $remotePayload[$field] ?? null;

            // Central never set this field — safe to merge.
            if ($remoteValue === null || $remoteValue === '') {
                continue;
            }

            // Both sides have a value and they differ → genuine conflict.
            if ((string) $localValue !== (string) $remoteValue) {
                $overlapping[] = $field;
            }
        }

        $overlapping = array_values(array_unique($overlapping));

        // No field overlap → safe to auto-merge.
        if ($overlapping === [] && $changedFields !== []) {
            return $this->autoMerge($operation, $localPayload, $model, $remoteRevision, $entityType);
        }

        // Fields overlap or we cannot determine changed fields → create a conflict.
        return $this->conflict(
            $server,
            $operation,
            $entityUuid,
            $originServerUuid,
            $localPayload,
            $remotePayload,
            $remoteRevision,
            $overlapping,
        );
    }

    /**
     * Apply only the non-overlapping changed fields from the local payload.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $localPayload
     * @return array<string, mixed>
     */
    private function autoMerge(
        array $operation,
        array $localPayload,
        Beneficiary|BeneficiaryBatch $model,
        int $remoteRevision,
        string $entityType,
    ): array {
        if ($entityType === 'beneficiary') {
            /** @var Beneficiary $model */
            $fields = $this->beneficiaryFields($localPayload);
            unset($fields['beneficiary_code']);
            $fields['revision'] = $remoteRevision + 1;
            $fields['last_operation_uuid'] = $operation['operation_uuid'];
            $model->fill($fields)->save();
        } else {
            /** @var BeneficiaryBatch $model */
            $fields = $this->batchFields($localPayload);
            $fields['revision'] = $remoteRevision + 1;
            $fields['last_operation_uuid'] = $operation['operation_uuid'];
            $model->fill($fields)->save();
        }

        SyncEvent::create([
            'offline_server_id' => null,
            'type' => 'operation.auto_merged',
            'severity' => 'info',
            'message' => 'Non-overlapping fields auto-merged without conflict.',
            'context' => [
                'entity_type' => $entityType,
                'entity_uuid' => $operation['entity_uuid'],
                'operation_uuid' => $operation['operation_uuid'],
                'merged_fields' => array_keys($fields),
            ],
        ]);

        return $this->result($operation, 'applied', null, null, ['revision' => $model->revision]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $localPayload
     * @param  array<string, mixed>  $remotePayload
     * @param  array<int, string>  $overlappingFields  Fields that conflict between local and remote.
     * @return array<string, mixed>
     */
    private function conflict(
        OfflineServer $server,
        array $operation,
        string $entityUuid,
        string $originServerUuid,
        array $localPayload,
        array $remotePayload,
        int $remoteRevision,
        array $overlappingFields = [],
    ): array {
        $conflict = SyncConflict::create([
            'conflict_uuid' => SyncIdentity::newUuid(),
            'offline_server_id' => $server->id,
            'entity_type' => $operation['entity_type'],
            'entity_uuid' => $entityUuid,
            'operation' => $operation['operation'],
            'operation_uuid' => $operation['operation_uuid'],
            'origin_server_uuid' => $originServerUuid,
            'local_payload' => $localPayload,
            'remote_payload' => $remotePayload,
            'diff' => $overlappingFields !== []
                ? $overlappingFields
                : array_values(array_unique(array_merge(array_keys($localPayload), array_keys($remotePayload)))),
            'status' => 'open',
        ]);

        if ($operation['entity_type'] === 'beneficiary') {
            Beneficiary::query()->where('global_uuid', $entityUuid)
                ->update(['conflict_status' => 'open']);
        } else {
            BeneficiaryBatch::withTrashed()->where('global_uuid', $entityUuid)
                ->update(['conflict_status' => 'open']);
        }

        SyncEvent::create([
            'offline_server_id' => $server->id,
            'type' => 'operation.conflict',
            'severity' => 'warning',
            'message' => 'Sync conflict created — overlapping field edits require manual resolution.',
            'context' => [
                'conflict_uuid' => $conflict->conflict_uuid,
                'entity_type' => $operation['entity_type'],
                'entity_uuid' => $entityUuid,
                'operation_uuid' => $operation['operation_uuid'],
                'overlapping_fields' => $overlappingFields,
            ],
        ]);

        return $this->result($operation, 'conflict', 'revision_conflict', 'The central record has a newer revision.', [
            'conflict_uuid' => $conflict->conflict_uuid,
            'revision' => $remoteRevision,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array{rule: string, matches: Collection<int, Beneficiary>}  $duplicates
     * @return array<string, mixed>
     */
    private function duplicateResult(array $operation, array $duplicates): array
    {
        return $this->result($operation, 'duplicate', 'duplicate_beneficiary', 'A matching beneficiary already exists.', [
            'duplicate_rule' => $duplicates['rule'],
            'matches' => $duplicates['matches']->pluck('beneficiary_code')->values()->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function result(
        array $operation,
        string $status,
        ?string $code = null,
        ?string $message = null,
        array $extra = [],
    ): array {
        return array_merge([
            'operation_uuid' => $operation['operation_uuid'],
            'entity_type' => $operation['entity_type'],
            'entity_uuid' => $operation['entity_uuid'],
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $previousResult
     */
    private function writeReceipt(OfflineServer $server, array $operation, array $result, ?array $previousResult = null): void
    {
        try {
            $receipt = SyncChange::query()
                ->where('change_uuid', $operation['operation_uuid'])
                ->first();

            $metadata = ['direction' => 'upload'];

            if ($previousResult !== null) {
                // Keep every earlier outcome for auditability; the receipt
                // itself always reflects the latest attempt.
                $metadata['previous_results'] = array_merge(
                    (array) data_get($receipt?->metadata, 'previous_results', []),
                    [[
                        'result' => $previousResult,
                        'recorded_at' => now()->toIso8601String(),
                    ]],
                );
            }

            $attributes = [
                'offline_server_id' => $server->id,
                'entity_type' => $operation['entity_type'],
                'entity_uuid' => $operation['entity_uuid'],
                'operation' => 'receipt',
                'origin_server_uuid' => $server->server_uuid,
                'revision' => (int) ($result['revision'] ?? $operation['base_revision'] ?? 0),
                'payload' => ['result' => $result],
                'metadata' => $metadata,
                'status' => $result['status'],
                'applied_at' => now(),
            ];

            if ($receipt instanceof SyncChange) {
                $receipt->forceFill($attributes)->save();

                return;
            }

            SyncChange::create(array_merge($attributes, [
                'change_uuid' => $operation['operation_uuid'],
            ]));
        } catch (Throwable) {
            // A concurrent retry may have written the same idempotency receipt.
        }
    }
}
