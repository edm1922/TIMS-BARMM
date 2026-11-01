<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncConflict;
use App\Models\SyncEvent;
use App\Models\SyncOperation;
use App\Services\DuplicateDetector;
use App\Support\SyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

final class LocalChangeApplier
{
    private const PENDING_STATUSES = [
        SyncOperation::STATUS_PENDING,
        SyncOperation::STATUS_SYNCING,
        SyncOperation::STATUS_FAILED,
        SyncOperation::STATUS_CONFLICT,
    ];

    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly IncomingBeneficiaryResolver $incoming,
    ) {}

    /**
     * @param  array<string, mixed>  $change
     * @return 'applied'|'conflict'|'skipped'
     */
    public function apply(array $change): string
    {
        $entityUuid = (string) ($change['entity_uuid'] ?? '');
        $entityType = (string) ($change['entity_type'] ?? '');
        $operation = (string) ($change['operation'] ?? 'updated');
        $changedFields = (array) ($change['payload']['changed_fields'] ?? []);
        $isManagerAssignment = $entityType === 'beneficiary_batch'
            && in_array('manager_uuid', $changedFields, true);

        if (! $isManagerAssignment
            && ($change['origin_server_uuid'] ?? null) === SyncIdentity::originUuid()) {
            return 'skipped';
        }

        if ($entityUuid === '' || ! in_array($entityType, ['beneficiary', 'beneficiary_batch'], true)) {
            return 'skipped';
        }

        $pending = SyncOperation::query()
            ->where('entity_type', $entityType)
            ->where('entity_uuid', $entityUuid)
            ->whereIn('status', self::PENDING_STATUSES)
            ->get();

        if ($pending->isNotEmpty()) {
            $this->markConflict($change, $pending);

            return 'conflict';
        }

        if ($operation === 'removed_from_scope') {
            return $this->removeFromScope($entityType, $entityUuid);
        }

        $data = $change['payload']['data'] ?? null;

        if (! is_array($data)) {
            return 'skipped';
        }

        $result = 'applied';

        SyncOperationRecorder::withoutRecording(function () use ($entityType, $entityUuid, $change, $data, &$result): void {
            match ($entityType) {
                'beneficiary' => $result = $this->applyBeneficiary($entityUuid, $change, $data),
                'beneficiary_batch' => $result = $this->applyBatch($entityUuid, $change, $data),
            };
        });

        return $result;
    }

    /**
     * Handle a record being removed from this server's scope.
     * Archive the record locally so it no longer appears in active lists
     * but is preserved for audit purposes.
     *
     * @return 'applied'
     */
    private function removeFromScope(string $entityType, string $entityUuid): string
    {
        if ($entityType === 'beneficiary') {
            $model = Beneficiary::query()->where('global_uuid', $entityUuid)->first();

            if ($model !== null && $model->archived_at === null) {
                $model->forceFill([
                    'archived_at' => now(),
                    'archive_reason' => 'Removed from server scope due to location change or scope update.',
                    'sync_status' => 'synced',
                    'conflict_status' => 'none',
                ])->save();
            }
        } elseif ($entityType === 'beneficiary_batch') {
            $model = BeneficiaryBatch::withTrashed()->where('global_uuid', $entityUuid)->first();

            if ($model !== null && ! $model->trashed()) {
                $model->forceFill([
                    'revision' => $model->revision + 1,
                    'sync_status' => 'synced',
                    'conflict_status' => 'none',
                ])->save();
                $model->delete();
            }
        }

        SyncEvent::create([
            'type' => 'scope_removed',
            'severity' => 'info',
            'message' => 'Record removed from local scope.',
            'context' => [
                'entity_type' => $entityType,
                'entity_uuid' => $entityUuid,
            ],
        ]);

        return 'applied';
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $data
     * @return 'applied'|'conflict'
     */
    private function applyBeneficiary(string $entityUuid, array $change, array $data): string
    {
        $operation = (string) ($change['operation'] ?? 'updated');
        $revision = max(1, (int) ($change['revision'] ?? 1));
        $batchUuid = $data['beneficiary_batch_uuid'] ?? null;
        unset($data['beneficiary_batch_uuid']);

        if (is_string($batchUuid) && $batchUuid !== '') {
            $data['beneficiary_batch_id'] = BeneficiaryBatch::withTrashed()
                ->where('global_uuid', $batchUuid)
                ->value('id');
        }

        if ($operation === 'deleted') {
            // The central record was permanently removed, so the local copy is
            // removed too. Idempotent: a missing row is already in the target
            // state. No operation is queued (this whole apply is user-suppressed).
            Beneficiary::query()
                ->where('global_uuid', $entityUuid)
                ->get()
                ->each(fn (Beneficiary $beneficiary) => $beneficiary->delete());

            return 'applied';
        }

        if (in_array($operation, ['archived'], true)) {
            $data['archived_at'] = $data['archived_at'] ?? now();
        } elseif (in_array($operation, ['unarchived', 'restored'], true)) {
            $data['archived_at'] = null;
            $data['archive_reason'] = null;
        }

        // The record may replace a record that the central server held before,
        // and a code issued by another installation may still be held here. The
        // incoming record is stored with the identity it has on the central
        // server.
        $resolved = $this->incoming->resolve([
            'global_uuid' => $entityUuid,
            'origin_server_uuid' => (string) ($change['origin_server_uuid'] ?? SyncIdentity::centralUuid()),
            'beneficiary_code' => $data['beneficiary_code'] ?? null,
            'source_id_number' => $data['source_id_number'] ?? null,
            'beneficiary_batch_id' => $data['beneficiary_batch_id'] ?? null,
        ]);

        if (array_key_exists('beneficiary_code', $data)) {
            $data['beneficiary_code'] = $resolved['beneficiary_code'];
        }

        // For 'created' operations from central, check for local duplicates
        // to avoid creating duplicate records with different global_uuids
        if ($operation === 'created') {
            $localMatch = $this->findLocalDuplicate($data);

            if ($localMatch !== null) {
                // Found a local match - create a conflict instead of duplicating
                $this->createDuplicateConflict($change, $localMatch, $data, $revision);

                return 'conflict';
            }
        }

        $beneficiary = Beneficiary::query()->where('global_uuid', $entityUuid)->first()
            ?? new Beneficiary(['global_uuid' => $entityUuid]);

        $beneficiary->fill($data);

        $this->setSyncMetadata($beneficiary, $change, $revision);
        $beneficiary->save();

        return 'applied';
    }

    /**
     * Check for an existing local beneficiary that matches the incoming data.
     * Uses the same rules as the central DuplicateDetector.
     *
     * @param  array<string, mixed>  $data
     */
    private function findLocalDuplicate(array $data): ?Beneficiary
    {
        $beneficiaryCode = $data['beneficiary_code'] ?? null;
        $sourceIdNumber = $data['source_id_number'] ?? null;
        $sourceIdNo = $data['source_id_no'] ?? null;
        $firstName = $data['first_name'] ?? null;
        $middleName = $data['middle_name'] ?? null;
        $lastName = $data['last_name'] ?? null;
        $birthdate = $data['birthdate'] ?? null;

        // Use the same duplicate detection logic as the central server
        $duplicates = $this->duplicates->find(
            $beneficiaryCode,
            $sourceIdNumber,
            $sourceIdNo,
            $firstName,
            $middleName,
            $lastName,
            $birthdate ? (string) $birthdate : null,
        );

        \Log::debug('findLocalDuplicate', [
            'input' => $data,
            'duplicates_found' => $duplicates !== null,
            'rule' => $duplicates['rule'] ?? null,
            'match_count' => ($duplicates['matches'] ?? collect())->count(),
        ]);

        if ($duplicates !== null && $duplicates['matches']->isNotEmpty()) {
            return $duplicates['matches']->first();
        }

        return null;
    }

    /**
     * Create a conflict when a central 'created' operation matches a local record.
     *
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $data
     */
    private function createDuplicateConflict(array $change, Beneficiary $localMatch, array $data, int $revision): void
    {
        $conflict = SyncConflict::create([
            'conflict_uuid' => SyncIdentity::newUuid(),
            'offline_server_id' => null, // No specific offline server - this is a local duplicate
            'entity_type' => 'beneficiary',
            'entity_uuid' => $localMatch->global_uuid,
            'origin_server_uuid' => $change['origin_server_uuid'] ?? null,
            'local_payload' => $data,
            'remote_payload' => $localMatch->only([
                'beneficiary_code',
                'source_id_number',
                'source_id_no',
                'first_name',
                'middle_name',
                'last_name',
                'birthdate',
            ]),
            'diff' => ['duplicate'],
            'status' => 'open',
        ]);

        $localMatch->forceFill([
            'conflict_status' => 'open',
        ])->save();

        SyncEvent::create([
            'type' => 'operation.conflict',
            'severity' => 'warning',
            'message' => 'Central create operation matches existing local beneficiary.',
            'context' => [
                'conflict_uuid' => $conflict->conflict_uuid,
                'entity_type' => 'beneficiary',
                'entity_uuid' => $localMatch->global_uuid,
                'change_uuid' => $change['change_uuid'] ?? null,
                'duplicate_rule' => 'local_duplicate',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array<string, mixed>  $data
     * @return 'applied'
     */
    private function applyBatch(string $entityUuid, array $change, array $data): string
    {
        $operation = (string) ($change['operation'] ?? 'updated');
        $revision = max(1, (int) ($change['revision'] ?? 1));

        $batch = BeneficiaryBatch::withTrashed()->where('global_uuid', $entityUuid)->first()
            ?? new BeneficiaryBatch(['global_uuid' => $entityUuid]);

        $batch->fill($data);
        $this->setSyncMetadata($batch, $change, $revision);

        if ($operation === 'deleted') {
            $saved = $this->saveBatch($batch, $data, $change);

            if ($saved !== 'applied') {
                return $saved;
            }

            if (! $batch->trashed()) {
                $batch->delete();
            }

            return 'applied';
        }

        if (in_array($operation, ['restored'], true) && $batch->trashed()) {
            $batch->restore();
        }

        return $this->saveBatch($batch, $data, $change);
    }

    /**
     * Save a synced batch without letting a name-origin collision abort the
     * whole delta. A local batch with the same name is a conflict for an
     * operator to resolve, not a reason to lose later changes in the page.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $change
     * @return 'applied'|'conflict'
     */
    private function saveBatch(BeneficiaryBatch $batch, array $data, array $change): string
    {
        try {
            $batch->save();

            return 'applied';
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            SyncEvent::create([
                'type' => 'conflict',
                'severity' => 'warning',
                'message' => 'A batch with the same name already exists on this installation. Rename the local batch, then run Sync now again.',
                'context' => [
                    'entity_type' => 'beneficiary_batch',
                    'entity_uuid' => $batch->global_uuid,
                    'name' => $data['name'] ?? null,
                    'operation' => $change['operation'] ?? null,
                    'change_uuid' => $change['change_uuid'] ?? null,
                ],
            ]);

            return 'conflict';
        }
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function setSyncMetadata(Model $model, array $change, int $revision): void
    {
        $model->forceFill([
            'origin_server_uuid' => $change['origin_server_uuid'] ?? $model->getAttribute('origin_server_uuid'),
            'revision' => $revision,
            'server_revision' => $revision,
            'sync_status' => 'synced',
            'conflict_status' => 'none',
            'last_operation_uuid' => $change['change_uuid'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  Collection<int, SyncOperation>  $pending
     */
    private function markConflict(array $change, $pending): void
    {
        $entityType = (string) $change['entity_type'];
        $entityUuid = (string) $change['entity_uuid'];

        $model = $entityType === 'beneficiary'
            ? Beneficiary::query()->where('global_uuid', $entityUuid)->first()
            : BeneficiaryBatch::withTrashed()->where('global_uuid', $entityUuid)->first();

        if ($model !== null) {
            $model->forceFill([
                'sync_status' => 'conflict',
                'conflict_status' => 'open',
            ])->save();
        }

        foreach ($pending as $operation) {
            $operation->forceFill([
                'status' => SyncOperation::STATUS_CONFLICT,
                'last_error' => 'A central change overlaps local unsynced edits.',
            ])->save();
        }

        SyncEvent::create([
            'type' => 'conflict',
            'severity' => 'warning',
            'message' => 'A central change overlaps local unsynced edits.',
            'context' => [
                'entity_type' => $entityType,
                'entity_uuid' => $entityUuid,
                'change_uuid' => $change['change_uuid'] ?? null,
                'operation' => $change['operation'] ?? null,
                'changed_fields' => $change['payload']['changed_fields'] ?? [],
                'local_operation_uuids' => $pending->pluck('operation_uuid')->all(),
            ],
        ]);
    }
}
