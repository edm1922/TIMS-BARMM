<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\SyncConflict;
use App\Models\SyncOperation;

/**
 * Shared rules for permanently deleting a beneficiary. Used by the single
 * beneficiary deletion and by the batch deletion, which removes every
 * beneficiary filed in the batch.
 */
class SyncDeletionGuard
{
    /**
     * A beneficiary with an open sync review, or an operation that is being
     * uploaded or is already in conflict, must not be deleted: the deletion
     * would race work that is already in flight. Pending and failed uploads
     * are safe to supersede, so they do not block.
     */
    public function blocksDeletion(Beneficiary $beneficiary): bool
    {
        $openConflict = SyncConflict::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->where('status', 'open')
            ->exists();

        if ($openConflict) {
            return true;
        }

        return SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->whereIn('status', [
                SyncOperation::STATUS_SYNCING,
                SyncOperation::STATUS_CONFLICT,
            ])
            ->where('operation', '!=', 'deleted')
            ->exists();
    }

    /**
     * Pending uploads for a record are superseded by the deletion rather than
     * silently discarded: they stay on record with a reason, and are no longer
     * attempted.
     */
    public function supersedePendingOperations(Beneficiary $beneficiary, string $reason): void
    {
        SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('entity_uuid', $beneficiary->global_uuid)
            ->whereIn('status', [SyncOperation::STATUS_PENDING, SyncOperation::STATUS_FAILED])
            ->where('operation', '!=', 'deleted')
            ->update([
                'status' => SyncOperation::STATUS_SUPERSEDED,
                'last_error' => $reason,
            ]);
    }
}
