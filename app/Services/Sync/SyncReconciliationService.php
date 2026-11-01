<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServer;
use App\Models\SyncChange;
use App\Models\SyncCheckpoint;
use App\Models\SyncConflict;
use App\Models\SyncRequestItem;

/**
 * Read-only comparison between the central server's scoped state and an
 * offline installation's last reported batch roster.
 *
 * The report never reasons from totals alone: each row is keyed by the
 * batch's stable global UUID, and the offline's acknowledged download
 * checkpoint plus its upload receipts explain *why* the two sides differ.
 * Delivery itself remains the job of the existing snapshot/delta protocol —
 * this service only makes the differences visible and actionable.
 */
class SyncReconciliationService
{
    /**
     * Verdicts surfaced per compared batch.
     */
    public const IN_SYNC = 'in_sync';

    public const MISSING_ON_OFFLINE = 'missing_on_offline';

    public const MISSING_ON_CENTRAL = 'missing_on_central';

    public const DIVERGENT = 'divergent';

    public const UNKNOWN_TO_CENTRAL = 'unknown_to_central';

    /**
     * @return array<string, mixed>
     */
    public function forServer(OfflineServer $server): array
    {
        $roster = $this->rosterOf($server);

        if ($roster === null) {
            // No roster has been reported yet. Queue and workflow facts are
            // still available; the batch comparison is simply absent.
            return [
                'roster_available' => false,
                'captured_at' => null,
                'batches' => [],
                'totals' => $this->totals([], [], null),
                'delivery_backlog' => $this->deliveryBacklog($server),
                'held_for_review' => $this->heldForReview($server),
                'open_conflicts' => $this->openConflicts($server),
            ];
        }

        $centralCounts = $this->centralBatchCounts($server);

        $rows = [];
        $compared = [];

        // Server-side roster rows: matched against central by batch UUID.
        foreach ($roster as $entry) {
            $uuid = $entry['uuid'] ?? null;
            $offlineMembers = (int) ($entry['members'] ?? 0);

            if ($uuid === null) {
                // The unassigned bucket: central mirrors it as active records
                // in scope with no batch assignment.
                $centralMembers = $centralCounts['unassigned'] ?? 0;

                $rows[] = $this->row(
                    uuid: null,
                    name: $entry['name'] ?? 'Not in any batch',
                    deletedOffline: (bool) ($entry['deleted'] ?? false),
                    centralMembers: (int) $centralMembers,
                    offlineMembers: $offlineMembers,
                );

                continue;
            }

            $central = $centralCounts[$uuid] ?? null;
            $compared[] = $uuid;

            $rows[] = $this->row(
                uuid: $uuid,
                name: $entry['name'] ?? '(unnamed batch)',
                deletedOffline: (bool) ($entry['deleted'] ?? false),
                centralMembers: $central,
                offlineMembers: $offlineMembers,
            );
        }

        // Central-side batches in the server's scope that the roster did not
        // mention: the installation has not reported them (not enrolled when
        // they were created, or never received a snapshot containing them).
        foreach ($centralCounts as $uuid => $count) {
            if ($uuid === 'unassigned' || in_array($uuid, $compared, true)) {
                continue;
            }

            if ((int) $count === 0) {
                continue; // empty central batch — nothing to reconcile yet
            }

            $rows[] = $this->row(
                uuid: (string) $uuid,
                name: $this->centralBatchName($uuid),
                deletedOffline: false,
                centralMembers: (int) $count,
                offlineMembers: null,
            );
        }

        usort($rows, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'roster_available' => true,
            'captured_at' => data_get($server->metadata, 'last_roster.captured_at'),
            'batches' => $rows,
            'totals' => $this->totals($rows, $centralCounts, $roster),
            'delivery_backlog' => $this->deliveryBacklog($server),
            'held_for_review' => $this->heldForReview($server),
            'open_conflicts' => $this->openConflicts($server),
        ];
    }

    private function heldForReview(OfflineServer $server): int
    {
        return SyncRequestItem::query()
            ->whereHas('request', fn ($query) => $query->where('offline_server_id', $server->id))
            ->awaitingReview()
            ->count();
    }

    private function openConflicts(OfflineServer $server): int
    {
        return SyncConflict::query()
            ->where('offline_server_id', $server->id)
            ->where('status', 'open')
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function rosterOf(OfflineServer $server): ?array
    {
        $roster = data_get($server->metadata, 'last_roster.batches');

        return is_array($roster) ? $roster : null;
    }

    /**
     * Central's active, in-scope beneficiary counts, keyed by batch UUID
     * ('unassigned' for records with no batch). One grouped query through the
     * server's scope, so the page stays at a fixed query count.
     *
     * @return array<string, int>
     */
    private function centralBatchCounts(OfflineServer $server): array
    {
        $scoped = app(ScopeService::class)
            ->applyToBeneficiaryQuery(Beneficiary::query(), $server)
            ->whereNull('archived_at');

        $rows = (clone $scoped)
            ->join('beneficiary_batches', 'beneficiary_batches.id', '=', 'beneficiaries.beneficiary_batch_id')
            ->whereNull('beneficiary_batches.deleted_at')
            ->groupBy('beneficiary_batches.global_uuid', 'beneficiary_batches.name')
            ->selectRaw('beneficiary_batches.global_uuid AS uuid, beneficiary_batches.name AS name, COUNT(*) AS members')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->uuid] = (int) $row->members;
        }

        $unassigned = (clone $scoped)
            ->whereNull('beneficiary_batch_id')
            ->count();

        $counts['unassigned'] = (int) $unassigned;

        // Members pointing at soft-deleted batches: counted so the ghost
        // records are visible instead of silently vanishing from the totals.
        $ghosted = (clone $scoped)
            ->join('beneficiary_batches', 'beneficiary_batches.id', '=', 'beneficiaries.beneficiary_batch_id')
            ->whereNotNull('beneficiary_batches.deleted_at')
            ->count();

        $counts['ghosted'] = (int) $ghosted;

        return $counts;
    }

    private function centralBatchName(string $uuid): string
    {
        return (string) (BeneficiaryBatch::query()
            ->where('global_uuid', $uuid)
            ->value('name') ?? '(batch)');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        ?string $uuid,
        string $name,
        bool $deletedOffline,
        ?int $centralMembers,
        ?int $offlineMembers,
    ): array {
        // The unassigned bucket is compared one-sided: it is not a batch, so
        // "equal" is the normal state and only the offline's surplus matters.
        $verdict = $uuid === null
            ? (($offlineMembers - ($centralMembers ?? 0)) > 0 ? self::MISSING_ON_CENTRAL : self::IN_SYNC)
            : $this->verdict($centralMembers, $offlineMembers);

        return [
            'uuid' => $uuid,
            'name' => $name,
            'deleted_offline' => $deletedOffline,
            'central_members' => $centralMembers,
            'offline_members' => $offlineMembers,
            'delta' => ($centralMembers ?? 0) - ($offlineMembers ?? 0),
            'verdict' => $verdict,
        ];
    }

    private function verdict(?int $central, ?int $offline): string
    {
        if ($central === null) {
            // A batch the central server has never seen: the offline created
            // it outside the sync baseline (the classic backfill gap).
            return $offline > 0 ? self::UNKNOWN_TO_CENTRAL : self::IN_SYNC;
        }

        $delta = $central - ($offline ?? 0);

        if ($delta === 0) {
            return self::IN_SYNC;
        }

        if ($offline !== null && $offline > $central) {
            return self::MISSING_ON_CENTRAL;
        }

        return $delta > 0 ? self::MISSING_ON_OFFLINE : self::DIVERGENT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $centralCounts
     * @param  array<int, array<string, mixed>>|null  $roster
     * @return array<string, int>
     */
    private function totals(array $rows, array $centralCounts, ?array $roster): array
    {
        $totals = [
            'central_in_scope' => ($centralCounts['unassigned'] ?? 0) + array_sum(array_diff_key(
                $centralCounts,
                ['unassigned' => 0, 'ghosted' => 0],
            )),
            'unassigned_central' => $centralCounts['unassigned'] ?? 0,
            'ghosted_central' => $centralCounts['ghosted'] ?? 0,
            'in_sync' => 0,
            'missing_on_offline' => 0,
            'missing_on_central' => 0,
            'divergent' => 0,
            'unknown_to_central' => 0,
        ];

        foreach ($rows as $row) {
            if ($row['uuid'] === null) {
                continue; // the unassigned bucket is reported, not batched
            }

            $totals[$row['verdict']] = ($totals[$row['verdict']] ?? 0) + 1;
        }

        return $totals;
    }

    /**
     * In-scope change-log entries the server has not yet acknowledged: the
     * concrete work its next delta download will pull (Scenario A evidence).
     */
    private function deliveryBacklog(OfflineServer $server): int
    {
        $cursor = (int) (SyncCheckpoint::query()
            ->where('offline_server_id', $server->id)
            ->where('direction', 'download')
            ->value('cursor') ?? 0);

        $scopedUuids = app(ScopeService::class)
            ->applyToBeneficiaryQuery(Beneficiary::query(), $server)
            ->select('global_uuid');

        return (int) SyncChange::query()
            ->where('operation', '!=', 'receipt')
            ->where('id', '>', $cursor)
            ->where(function ($query) use ($scopedUuids, $server): void {
                $query
                    ->where('entity_type', 'beneficiary_batch')
                    ->orWhere(function ($beneficiaryQuery) use ($scopedUuids): void {
                        $beneficiaryQuery
                            ->where('entity_type', 'beneficiary')
                            ->whereIn('entity_uuid', $scopedUuids);
                    })
                    ->orWhere(function ($targetedQuery) use ($server): void {
                        $targetedQuery
                            ->where('offline_server_id', $server->id)
                            ->where('operation', 'removed_from_scope');
                    });
            })
            ->count();
    }
}
