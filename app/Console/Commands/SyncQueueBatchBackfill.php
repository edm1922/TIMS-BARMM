<?php

namespace App\Console\Commands;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncOperation;
use App\Services\Sync\SyncPayload;
use App\Support\SyncIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Queue durable "created" operations for batch members that predate the
 * installation's sync baseline, so the next Sync Now uploads them through the
 * normal protocol.
 *
 * This is the operator-run remedy for the backfill gap: records that arrived
 * before enrollment (or via a snapshot) never entered the upload queue and are
 * invisible to the central server. The command is idempotent — members that
 * already have a creation operation are never queued twice — and it queues the
 * batch itself first, so the members are uploaded into a batch the central
 * server knows.
 */
class SyncQueueBatchBackfill extends Command
{
    protected $signature = 'sync:queue-batch-backfill
        {batch? : Batch id or exact name}
        {--all : Queue every non-deleted batch}
        {--dry-run : Preview the counts without writing anything}
        {--include-synced : Also re-queue members whose creation was already marked synced (re-uploads people central may already hold — they will be held for review)}';

    protected $description = 'Queue local batch members for upload when they predate the sync baseline (no creation operation exists)';

    public function handle(): int
    {
        if (! SyncIdentity::isOffline()) {
            $this->error('This command runs on an OFFLINE installation only — it queues local operations for upload.');

            return 1;
        }

        $batches = $this->resolveBatches();

        if ($batches->isEmpty()) {
            $this->error('No matching batch found. Pass a batch id or exact name, or use --all.');

            return 1;
        }

        $totals = ['batches' => 0, 'batch_ops' => 0, 'queued' => 0, 'already_queued' => 0, 'already_synced' => 0, 'archived' => 0];

        foreach ($batches as $batch) {
            $this->line("Batch #{$batch->id} \"{$batch->name}\"".($batch->trashed() ? ' (DELETED)' : ''));

            $result = $this->backfillBatch($batch, $dryRun = (bool) $this->option('dry-run'));

            foreach ($result as $key => $value) {
                $this->line(sprintf('  %-16s %s', $key.':', number_format($value)));
            }

            $totals['batches']++;
            foreach ($result as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d batch(es), %d batch operation(s), %d member operation(s) queued, %d already queued, %d already synced, %d archived skipped.',
            $dryRun ? 'DRY RUN' : 'Done',
            $totals['batches'],
            $totals['batch_ops'],
            $totals['queued'],
            $totals['already_queued'],
            $totals['already_synced'],
            $totals['archived'],
        ));

        if (! $dryRun && $totals['queued'] > 0) {
            $this->line('Run Sync Now (repeatedly, 50 operations per run) to upload the queued operations.');
        }

        return 0;
    }

    /**
     * @return Collection<int, BeneficiaryBatch>
     */
    private function resolveBatches(): Collection
    {
        if ($this->option('all')) {
            return BeneficiaryBatch::query()->orderBy('id')->get();
        }

        $term = trim((string) $this->argument('batch'));

        if ($term === '') {
            return collect();
        }

        $query = BeneficiaryBatch::withTrashed();

        $batch = ctype_digit($term)
            ? $query->find((int) $term)
            : $query->where('name', $term)->first();

        return collect($batch !== null ? [$batch] : []);
    }

    /**
     * @return array<string, int>
     */
    private function backfillBatch(BeneficiaryBatch $batch, bool $dryRun): array
    {
        $result = ['batch_ops' => 0, 'queued' => 0, 'already_queued' => 0, 'already_synced' => 0, 'archived' => 0];

        // The batch itself is queued first so the members are uploaded into a
        // batch the central server knows (batch-first ordering).
        $batchQueued = SyncOperation::query()
            ->where('entity_type', 'beneficiary_batch')
            ->where('entity_uuid', $batch->global_uuid)
            ->where('operation', 'created')
            ->exists();

        if (! $batchQueued && ! $batch->trashed()) {
            if (! $dryRun) {
                $this->queueCreation($batch);
            }

            $result['batch_ops'] = 1;
        }

        $members = Beneficiary::query()
            ->where('beneficiary_batch_id', $batch->id)
            ->orderBy('id')
            ->get();

        $creationOps = SyncOperation::query()
            ->where('entity_type', 'beneficiary')
            ->where('operation', 'created')
            ->whereIn('entity_uuid', $members->pluck('global_uuid'))
            ->orderBy('id')
            ->get()
            ->groupBy('entity_uuid');

        foreach ($members as $member) {
            if ($member->archived_at !== null) {
                $result['archived']++;

                continue;
            }

            $creation = $creationOps->get($member->global_uuid)?->last();

            $needsQueue = $creation === null
                || ($creation->status === SyncOperation::STATUS_SYNCED && $this->option('include-synced'));

            if ($needsQueue) {
                if (! $dryRun) {
                    $this->queueCreation($member);
                }

                $result['queued']++;

                continue;
            }

            if ($creation->status === SyncOperation::STATUS_SYNCED) {
                $result['already_synced']++;

                continue;
            }

            // Pending/failed/awaiting_review creations are already in flight.
            $result['already_queued']++;
        }

        return $result;
    }

    private function queueCreation(Beneficiary|BeneficiaryBatch $model): void
    {
        SyncOperation::create([
            'operation_uuid' => (string) Str::uuid(),
            'entity_type' => $model instanceof Beneficiary ? 'beneficiary' : 'beneficiary_batch',
            'entity_uuid' => (string) $model->global_uuid,
            'operation' => 'created',
            'origin_server_uuid' => (string) ($model->origin_server_uuid ?: SyncIdentity::originUuid()),
            'local_id' => $model->getKey(),
            'base_revision' => 0,
            'payload' => SyncPayload::for($model),
            'changed_fields' => [],
            'status' => SyncOperation::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }
}
