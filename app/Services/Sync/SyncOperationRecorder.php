<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncOperation;
use App\Support\SyncIdentity;
use Illuminate\Database\Eloquent\Model;

final class SyncOperationRecorder
{
    private static int $suppressed = 0;

    /**
     * Run a callback without recording local sync operations. Used while
     * importing a central snapshot or applying remote changes.
     */
    public static function withoutRecording(callable $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed--;
        }
    }

    public static function isRecording(): bool
    {
        return ! self::isSuppressed() && SyncIdentity::isOffline();
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed > 0;
    }

    public static function record(Model $model, string $operation, array $changedFields = []): ?SyncOperation
    {
        if (! self::isRecording()) {
            return null;
        }

        $entityUuid = $model->getAttribute('global_uuid');

        if (! is_string($entityUuid) || trim($entityUuid) === '') {
            return null;
        }

        $operation = self::normalizeOperation($model, $operation, $changedFields);
        $baseRevision = self::baseRevision($model, $operation);

        return SyncOperation::create([
            'entity_type' => self::entityType($model),
            'entity_uuid' => $entityUuid,
            'operation' => $operation,
            'origin_server_uuid' => (string) ($model->getAttribute('origin_server_uuid') ?: SyncIdentity::originUuid()),
            'local_id' => $model->getKey(),
            'base_revision' => $baseRevision,
            'payload' => SyncPayload::for($model),
            'changed_fields' => array_values($changedFields),
            'status' => SyncOperation::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }

    private static function normalizeOperation(Model $model, string $operation, array $changedFields): string
    {
        if ($operation !== 'updated' || ! in_array('archived_at', $changedFields, true)) {
            return $operation;
        }

        if ($model instanceof Beneficiary) {
            return $model->archived_at === null ? 'unarchived' : 'archived';
        }

        return $operation;
    }

    private static function baseRevision(Model $model, string $operation): int
    {
        $revision = (int) ($model->getAttribute('revision') ?? 1);
        $original = (int) ($model->getOriginal('revision') ?? $revision);

        return match ($operation) {
            'created' => 0,
            'updated', 'archived', 'unarchived' => max(0, $original),
            default => $revision,
        };
    }

    private static function entityType(Model $model): string
    {
        return match (true) {
            $model instanceof Beneficiary => 'beneficiary',
            $model instanceof BeneficiaryBatch => 'beneficiary_batch',
            default => $model->getTable(),
        };
    }
}
