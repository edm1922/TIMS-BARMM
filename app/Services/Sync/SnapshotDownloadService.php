<?php

namespace App\Services\Sync;

use App\Exceptions\Sync\SnapshotExpiredException;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\Location;
use App\Models\SyncSetting;
use App\Models\Training;
use App\Support\SyncIdentity;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SnapshotDownloadService
{
    public function __construct(
        private readonly SyncClient $client,
        private readonly IncomingBeneficiaryResolver $incoming,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function download(): array
    {
        if (! $this->client->isEnrolled()) {
            throw new RuntimeException('This server is not enrolled with a central server yet.');
        }

        $progress = $this->progress();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->run($progress ?? $this->createSnapshot());
            } catch (SnapshotExpiredException) {
                $this->clearProgress();
                $progress = null;
            }
        }

        throw new RuntimeException('The central snapshot expired before it could be downloaded. Please try again.');
    }

    /**
     * @return array<string, mixed>
     */
    private function createSnapshot(): array
    {
        $manifestResponse = $this->client->snapshot();

        if (! $manifestResponse->successful()) {
            throw new RuntimeException((string) $manifestResponse->json('error.message', 'The central server could not create a snapshot.'));
        }

        $snapshotUuid = (string) $manifestResponse->json('data.snapshot_uuid');

        if ($snapshotUuid === '') {
            throw new RuntimeException('The central server returned an invalid snapshot.');
        }

        $expected = $manifestResponse->json('data.manifest.beneficiaries');

        $progress = [
            'snapshot_uuid' => $snapshotUuid,
            'revision_to' => (int) $manifestResponse->json('data.revision_to', 0),
            'expected_beneficiaries' => is_numeric($expected) ? (int) $expected : null,
            'cursor' => null,
            'beneficiaries' => 0,
            'locations' => 0,
            'trainings' => 0,
            'pages' => 0,
            'started_at' => now()->toIso8601String(),
        ];

        $this->saveProgress($progress);

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, int|string|null>
     */
    private function run(array $progress): array
    {
        $snapshotUuid = (string) $progress['snapshot_uuid'];
        $referenceResponse = $this->client->snapshotReference($snapshotUuid);

        $this->ensureUsable($referenceResponse, 'The snapshot reference data could not be downloaded.');

        $reference = $this->importReference((array) $referenceResponse->json('data', []));
        $progress['locations'] = $reference['locations'];
        $progress['trainings'] = $reference['trainings'];

        $cursor = $progress['cursor'] !== null ? (int) $progress['cursor'] : null;

        while (true) {
            $pageResponse = $this->client->snapshotBeneficiaries($snapshotUuid, $cursor);

            $this->ensureUsable($pageResponse, 'The snapshot beneficiaries could not be downloaded.');

            $rows = (array) $pageResponse->json('data', []);
            $progress['beneficiaries'] = (int) $progress['beneficiaries'] + $this->importBeneficiaries($rows);
            $progress['pages'] = (int) $progress['pages'] + 1;

            $hasMore = (bool) $pageResponse->json('meta.has_more', false);
            $nextCursor = $pageResponse->json('meta.next_cursor');
            $cursor = is_numeric($nextCursor) ? (int) $nextCursor : null;
            $progress['cursor'] = $cursor;

            if ($hasMore && $cursor === null) {
                throw new RuntimeException('The central server returned an incomplete snapshot page.');
            }

            $this->saveProgress($progress);

            if (! $hasMore) {
                break;
            }
        }

        $expected = $progress['expected_beneficiaries'];
        $beneficiaries = (int) $progress['beneficiaries'];

        // If the manifest reports 0 beneficiaries but we have a scope configured,
        // this likely indicates a scope configuration issue rather than an empty dataset.
        if ($expected !== null && (int) $expected === 0 && $this->hasScopeConfigured()) {
            $this->clearProgress();

            throw new RuntimeException('Snapshot contains 0 beneficiaries for your scope. Please verify the scope configuration on the central server matches this installation\'s assigned scope.');
        }

        if ($expected !== null && (int) $expected !== $beneficiaries) {
            $this->clearProgress();

            throw new RuntimeException(sprintf(
                'Snapshot verification failed: expected %s beneficiaries but imported %s.',
                number_format((int) $expected),
                number_format($beneficiaries),
            ));
        }

        $revisionTo = (int) $progress['revision_to'];
        $locations = (int) $progress['locations'];
        $trainings = (int) $progress['trainings'];
        $pages = (int) $progress['pages'];

        DB::transaction(function () use ($snapshotUuid, $revisionTo, $beneficiaries, $locations, $trainings, $pages): void {
            SyncSetting::set('last_snapshot_uuid', $snapshotUuid);
            SyncSetting::set('last_snapshot_at', now()->toIso8601String());
            SyncSetting::set('last_snapshot_revision', (string) $revisionTo);
            SyncSetting::set('last_download_revision', (string) $revisionTo);
            SyncSetting::set('reference_version', (string) $revisionTo);
            SyncSetting::set('last_snapshot_beneficiaries', (string) $beneficiaries);
            SyncSetting::set('last_snapshot_locations', (string) $locations);
            SyncSetting::set('last_snapshot_trainings', (string) $trainings);
            SyncSetting::set('last_snapshot_pages', (string) $pages);
        });

        $this->clearProgress();

        return [
            'snapshot_uuid' => $snapshotUuid,
            'revision_to' => $revisionTo,
            'beneficiaries' => $beneficiaries,
            'locations' => $locations,
            'trainings' => $trainings,
            'pages' => $pages,
        ];
    }

    private function ensureUsable(Response $response, string $message): void
    {
        if ($response->status() === 410) {
            throw new SnapshotExpiredException('The central snapshot has expired.');
        }

        if (! $response->successful()) {
            throw new RuntimeException((string) $response->json('error.message', $message));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function progress(): ?array
    {
        $raw = SyncSetting::get('snapshot_in_progress');

        if ($raw === null || $raw === '') {
            return null;
        }

        $progress = json_decode($raw, true);

        if (! is_array($progress) || empty($progress['snapshot_uuid'])) {
            $this->clearProgress();

            return null;
        }

        $startedAt = isset($progress['started_at']) ? Carbon::parse($progress['started_at']) : null;

        if ($startedAt !== null && $startedAt->lt(now()->subHours(2))) {
            $this->clearProgress();

            return null;
        }

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function saveProgress(array $progress): void
    {
        SyncSetting::set('snapshot_in_progress', json_encode($progress, JSON_THROW_ON_ERROR));
    }

    private function clearProgress(): void
    {
        SyncSetting::forget('snapshot_in_progress');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{locations: int, trainings: int}
     */
    private function importReference(array $data): array
    {
        $locations = (array) ($data['locations'] ?? []);
        $batches = (array) ($data['batches'] ?? []);
        $trainings = (array) ($data['trainings'] ?? []);

        return DB::transaction(function () use ($locations, $batches, $trainings): array {
            return SyncOperationRecorder::withoutRecording(function () use ($locations, $batches, $trainings): array {
                foreach ($locations as $location) {
                    if (! is_array($location) || empty($location['psgc_code'])) {
                        continue;
                    }

                    Location::query()->updateOrCreate(
                        ['psgc_code' => (string) $location['psgc_code']],
                        [
                            'barangay' => $location['barangay'] ?? null,
                            'city_municipality' => $location['city_municipality'] ?? null,
                            'province' => $location['province'] ?? null,
                            'full_address' => $location['full_address'] ?? null,
                            'is_city' => (bool) ($location['is_city'] ?? false),
                        ],
                    );
                }

                foreach ($batches as $batch) {
                    if (! is_array($batch) || empty($batch['global_uuid'])) {
                        continue;
                    }

                    $deletedAt = $batch['deleted_at'] ?? null;

                    BeneficiaryBatch::withTrashed()->updateOrCreate(
                        ['global_uuid' => (string) $batch['global_uuid']],
                        [
                            'origin_server_uuid' => $batch['origin_server_uuid'] ?? SyncIdentity::centralUuid(),
                            'name' => $batch['name'] ?? null,
                            'description' => $batch['description'] ?? null,
                            'status' => $batch['status'] ?? BeneficiaryBatch::STATUS_OPEN,
                            'manager_uuid' => $batch['manager_uuid'] ?? null,
                            'revision' => (int) ($batch['revision'] ?? 1),
                            'server_revision' => (int) ($batch['revision'] ?? 1),
                            'sync_status' => 'synced',
                            'conflict_status' => 'none',
                            'deleted_at' => $deletedAt ? Carbon::parse($deletedAt) : null,
                        ],
                    );
                }

                foreach ($trainings as $training) {
                    if (! is_array($training) || empty($training['category']) || empty($training['name'])) {
                        continue;
                    }

                    Training::query()->updateOrCreate(
                        ['category' => (string) $training['category'], 'name' => (string) $training['name']],
                        [],
                    );
                }

                return [
                    'locations' => count($locations),
                    'trainings' => count($trainings),
                ];
            });
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function importBeneficiaries(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($rows): int {
            return SyncOperationRecorder::withoutRecording(function () use ($rows): int {
                $batchUuids = array_values(array_filter(array_map(
                    fn (array $row): ?string => $row['beneficiary_batch_uuid'] ?? null,
                    $rows,
                )));

                $batchIds = BeneficiaryBatch::withTrashed()
                    ->whereIn('global_uuid', $batchUuids)
                    ->pluck('id', 'global_uuid');

                $count = 0;

                foreach ($rows as $row) {
                    if (! is_array($row) || empty($row['global_uuid'])) {
                        continue;
                    }

                    $fields = Arr::only($row, SyncPayload::BENEFICIARY_FIELDS);
                    $fields['global_uuid'] = (string) $row['global_uuid'];
                    $fields['origin_server_uuid'] = (string) ($row['origin_server_uuid'] ?? SyncIdentity::centralUuid());
                    $fields['revision'] = (int) ($row['revision'] ?? 1);
                    $fields['server_revision'] = (int) ($row['revision'] ?? 1);
                    $fields['sync_status'] = 'synced';
                    $fields['conflict_status'] = 'none';
                    $fields['duplicate_status'] = $row['duplicate_status'] ?? 'none';
                    $fields['duplicate_rule'] = $row['duplicate_rule'] ?? null;
                    $fields['duplicate_note'] = $row['duplicate_note'] ?? null;

                    $batchUuid = $row['beneficiary_batch_uuid'] ?? null;
                    $fields['beneficiary_batch_id'] = $batchUuid !== null
                        ? $batchIds->get((string) $batchUuid)
                        : null;

                    // The record may replace a record that the central server
                    // held before, and a code issued by another installation may
                    // still be held here. The incoming record is stored with the
                    // identity it has on the central server.
                    $fields = $this->incoming->resolve($fields);

                    Beneficiary::query()->updateOrCreate(
                        ['global_uuid' => $fields['global_uuid']],
                        $fields,
                    );

                    $count++;
                }

                return $count;
            });
        });
    }

    private function hasScopeConfigured(): bool
    {
        return SyncSetting::get('scope_code') !== null && SyncSetting::get('scope_code') !== '';
    }
}
