<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Finds and clears "ghost" beneficiaries: active records still filed in a
 * release batch that has been deleted locally. Those records no longer exist in
 * that form on the central server, so sync cannot remove them — a snapshot only
 * creates/updates records, it never deletes. They inflate the offline active
 * total and make the dashboard disagree with central until cleaned.
 *
 * Cleaning only archives the records (never hard-deletes them) and suppresses
 * the local sync recorder, so the cleanup cannot upload phantom updates to the
 * central server.
 */
class GhostBatchCleaner
{
    /**
     * Active members grouped by deleted batch.
     *
     * @return array{groups: list<array<string, mixed>>, total: int}
     */
    public function preview(): array
    {
        $groups = [];
        $total = 0;

        $batches = BeneficiaryBatch::onlyTrashed()
            ->withCount(['beneficiaries as active_members' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get();

        foreach ($batches as $batch) {
            $active = (int) $batch->active_members;

            if ($active === 0) {
                continue;
            }

            $total += $active;

            $groups[] = [
                'id' => $batch->id,
                'uuid' => $batch->global_uuid,
                'name' => $batch->name,
                'deleted_at' => $batch->deleted_at?->toIso8601String(),
                'active_members' => $active,
                'sample' => Beneficiary::query()
                    ->where('beneficiary_batch_id', $batch->id)
                    ->active()
                    ->orderBy('id')
                    ->limit(5)
                    ->get(['id', 'beneficiary_code', 'first_name', 'last_name'])
                    ->all(),
            ];
        }

        return ['groups' => $groups, 'total' => $total];
    }

    /**
     * Archive every ghost record and record what was cleaned.
     *
     * @return array{archived: int, groups: list<array<string, mixed>>, total_before: int}
     */
    public function clean(): array
    {
        $preview = $this->preview();
        $archived = 0;
        $groups = [];

        DB::transaction(function () use ($preview, &$archived, &$groups): void {
            foreach ($preview['groups'] as $group) {
                $batchArchived = 0;

                SyncOperationRecorder::withoutRecording(function () use ($group, &$batchArchived): void {
                    Beneficiary::query()
                        ->where('beneficiary_batch_id', $group['id'])
                        ->active()
                        ->orderBy('id')
                        ->chunkById(200, function ($members) use (&$batchArchived): void {
                            foreach ($members as $member) {
                                $member->forceFill([
                                    'archived_at' => now(),
                                    'archive_reason' => 'Ghost cleanup: the release batch this record was filed in was deleted.',
                                    'sync_status' => 'synced',
                                ])->save();

                                $batchArchived++;
                            }
                        });
                });

                if ($batchArchived === 0) {
                    continue;
                }

                $archived += $batchArchived;
                $groups[] = [
                    'uuid' => $group['uuid'],
                    'name' => $group['name'],
                    'archived' => $batchArchived,
                ];

                SyncEvent::create([
                    'type' => 'ghost_batch_cleaned',
                    'severity' => 'info',
                    'message' => 'Active beneficiaries filed in a deleted batch were archived.',
                    'context' => [
                        'batch_uuid' => $group['uuid'],
                        'batch_name' => $group['name'],
                        'archived' => $batchArchived,
                    ],
                ]);
            }
        });

        AuditLogger::log('sync.ghost_batches_cleaned', 'system', null, [
            'archived' => $archived,
            'groups' => $groups,
        ]);

        return [
            'archived' => $archived,
            'groups' => $groups,
            'total_before' => $preview['total'],
        ];
    }
}
