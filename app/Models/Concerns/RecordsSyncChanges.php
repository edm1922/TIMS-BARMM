<?php

namespace App\Models\Concerns;

use App\Models\SyncChange;
use App\Services\Sync\SyncOperationRecorder;
use App\Services\Sync\SyncPayload;
use App\Support\SyncIdentity;
use Illuminate\Database\Eloquent\Model;

trait RecordsSyncChanges
{
    private const SYNC_IGNORED_FIELDS = [
        'created_at',
        'updated_at',
        'revision',
        'server_revision',
        'sync_status',
        'conflict_status',
        'last_operation_uuid',
    ];

    protected static function bootRecordsSyncChanges(): void
    {
        static::created(function (Model $model): void {
            $model->recordSyncChange('created', array_keys($model->getAttributes()));
        });

        static::updating(function (Model $model): void {
            $dirty = array_diff_key($model->getDirty(), array_flip(self::SYNC_IGNORED_FIELDS));

            if ($dirty === [] || array_key_exists('revision', $model->getDirty())) {
                return;
            }

            $model->setAttribute('revision', max(1, (int) $model->getAttribute('revision')) + 1);
        });

        static::updated(function (Model $model): void {
            $changes = array_diff_key(
                $model->getChanges(),
                array_flip(self::SYNC_IGNORED_FIELDS),
            );

            if ($changes === []) {
                return;
            }

            $model->recordSyncChange('updated', array_keys($changes));

            // Check if location_code changed for beneficiaries - handle scope transitions
            if ($model->getTable() === 'beneficiaries' && array_key_exists('location_code', $changes)) {
                $oldCode = $model->getOriginal('location_code');
                $newCode = $model->getAttribute('location_code');

                if ($oldCode !== $newCode) {
                    $model->handleLocationCodeScopeChange($oldCode, $newCode);
                }
            }
        });

        static::deleted(function (Model $model): void {
            $forceDeleting = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();

            $model->recordSyncChange($forceDeleting ? 'force_deleted' : 'deleted', [
                $forceDeleting ? 'force_deleted_at' : 'deleted_at' => now()->toIso8601String(),
            ]);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model): void {
                $model->recordSyncChange('restored', ['deleted_at' => null]);
            });
        }
    }

    public function recordSyncChange(string $operation, array $changedFields = []): ?SyncChange
    {
        $entityUuid = $this->getAttribute('global_uuid');

        if (! is_string($entityUuid) || trim($entityUuid) === '' || SyncOperationRecorder::isSuppressed()) {
            return null;
        }

        $change = SyncChange::create([
            'entity_type' => $this->syncEntityType(),
            'entity_uuid' => $entityUuid,
            'operation' => $operation,
            'origin_server_uuid' => $this->getAttribute('origin_server_uuid') ?: SyncIdentity::originUuid(),
            'local_id' => $this->getKey(),
            'revision' => (int) ($this->getAttribute('revision') ?? 1),
            'payload' => [
                'changed_fields' => array_values($changedFields),
                'data' => SyncPayload::for($this),
            ],
            'applied_at' => now(),
        ]);

        SyncOperationRecorder::record($this, $operation, array_values($changedFields));

        return $change;
    }

    public function handleLocationCodeScopeChange(?string $oldCode, string $newCode): void
    {
        if (! class_exists(\App\Models\OfflineServer::class)) {
            return;
        }

        // Get all enrolled servers
        $servers = \App\Models\OfflineServer::query()
            ->where('status', 'active')
            ->with('scopes')
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $scopeService = app(\App\Services\Sync\ScopeService::class);

        foreach ($servers as $server) {
            $wasInScope = $oldCode !== null && $scopeService->allowsLocationCode($server, $oldCode);
            $isInScope = $scopeService->allowsLocationCode($server, $newCode);

            if ($wasInScope && ! $isInScope) {
                // Record moved OUT of this server's scope
                // Create a targeted sync_change for this server
                \App\Models\SyncChange::create([
                    'entity_type' => 'beneficiary',
                    'entity_uuid' => $this->getAttribute('global_uuid'),
                    'operation' => 'removed_from_scope',
                    'origin_server_uuid' => $this->getAttribute('origin_server_uuid') ?: \App\Support\SyncIdentity::originUuid(),
                    'offline_server_id' => $server->id,
                    'local_id' => $this->getKey(),
                    'revision' => (int) ($this->getAttribute('revision') ?? 1),
                    'payload' => [
                        'changed_fields' => ['location_code'],
                        'data' => \App\Services\Sync\SyncPayload::for($this),
                        'old_location_code' => $oldCode,
                        'new_location_code' => $newCode,
                    ],
                    'applied_at' => now(),
                ]);
            }
        }
    }

    protected function syncEntityType(): string
    {
        return match ($this->getTable()) {
            'beneficiaries' => 'beneficiary',
            'beneficiary_batches' => 'beneficiary_batch',
            default => $this->getTable(),
        };
    }
}
