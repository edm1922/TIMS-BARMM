<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncConflict;
use App\Models\SyncRequestItem;
use App\Services\DuplicateDetector;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Option A: one review domain for every decision an administrator has to make.
 *
 * The queue is an aggregate of the two storage models that exist today:
 * - open sync conflicts (field conflicts, deletion approvals, batch mappings)
 * - held upload items (duplicate candidates, invalid records)
 *
 * The rest of the application talks to this service for queueing and deciding,
 * so the reviewer only ever sees one vocabulary and one decision page. The
 * request/conflict models remain the transport and audit storage.
 */
class ReviewQueueService
{
    public const TYPE_DELETION = 'deletion_approval';

    public const TYPE_MAPPING = 'batch_mapping';

    public const TYPE_CONFLICT = 'field_conflict';

    public const TYPE_DUPLICATE = 'duplicate_candidate';

    public const TYPE_INVALID = 'invalid_record';

    public const LABELS = [
        self::TYPE_DELETION => 'Deletion approval',
        self::TYPE_MAPPING => 'Batch mapping',
        self::TYPE_CONFLICT => 'Field conflict',
        self::TYPE_DUPLICATE => 'Duplicate candidate',
        self::TYPE_INVALID => 'Invalid record',
    ];

    public function __construct(
        private readonly SyncOperationService $operations,
        private readonly SyncRequestReviewService $reviews,
    ) {}

    /**
     * Every pending decision, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(): Collection
    {
        $conflicts = SyncConflict::query()
            ->where('status', 'open')
            ->with('server')
            ->orderBy('id')
            ->get()
            ->map(fn (SyncConflict $conflict): array => $this->entryFromConflict($conflict));

        $items = SyncRequestItem::query()
            ->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)
            ->whereNull('conflict_uuid')
            ->with('request.server')
            ->orderBy('id')
            ->get()
            ->map(fn (SyncRequestItem $item): array => $this->entryFromItem($item));

        return $conflicts
            ->toBase()
            ->merge($items->toBase())
            ->sortByDesc('age_key')
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array<string, int>
     */
    public function counts(Collection $entries): array
    {
        return [
            'total' => $entries->count(),
            'deletions' => $entries->where('type', self::TYPE_DELETION)->count(),
            'mappings' => $entries->where('type', self::TYPE_MAPPING)->count(),
            'duplicates' => $entries->where('type', self::TYPE_DUPLICATE)->count(),
            'conflicts' => $entries->where('type', self::TYPE_CONFLICT)->count(),
            'other' => $entries->where('type', self::TYPE_INVALID)->count(),
        ];
    }

    /**
     * Server-side queue filtering (kind uses the review labels, 'other' covers
     * invalid records).
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array{q: string, kind: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function filter(Collection $entries, array $filters): Collection
    {
        $filtered = $entries;

        if ($filters['kind'] === 'other') {
            $filtered = $filtered->where('type', self::TYPE_INVALID)->values();
        } elseif ($filters['kind'] !== '') {
            $type = array_search($filters['kind'], self::LABELS, true);

            if ($type !== false) {
                $filtered = $filtered->where('type', $type)->values();
            }
        }

        if ($filters['q'] !== '') {
            $needle = mb_strtolower($filters['q']);

            $filtered = $filtered
                ->filter(function (array $entry) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', [
                        $entry['label'],
                        $entry['summary'],
                        $entry['detail'],
                        $entry['server'],
                        $entry['entity'],
                    ]));

                    return str_contains($haystack, $needle);
                })
                ->values();
        }

        return $filtered;
    }

    /**
     * Load one pending decision by its queue key (conflict-{uuid} or item-{id}).
     *
     * @return array<string, mixed>
     */
    public function find(string $key): array
    {
        [$prefix, $identifier] = array_pad(explode('-', $key, 2), 2, '');

        if ($identifier === '') {
            throw new InvalidArgumentException('Unknown review decision.');
        }

        if ($prefix === 'conflict') {
            $conflict = SyncConflict::query()
                ->where('conflict_uuid', $identifier)
                ->where('status', 'open')
                ->with('server')
                ->first();

            if (! $conflict instanceof SyncConflict) {
                throw new InvalidArgumentException('This decision has already been completed or no longer exists.');
            }

            return $this->entryFromConflict($conflict);
        }

        if ($prefix === 'item') {
            $item = SyncRequestItem::query()
                ->whereKey((int) $identifier)
                ->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)
                ->with('request.server')
                ->first();

            if (! $item instanceof SyncRequestItem) {
                throw new InvalidArgumentException('This decision has already been completed or no longer exists.');
            }

            return $this->entryFromItem($item);
        }

        throw new InvalidArgumentException('Unknown review decision.');
    }

    /**
     * Apply the administrator's decision for one review item.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $input
     * @return array{message: string, type: string, key: string}
     */
    public function decide(array $entry, array $input, ?int $actorId): array
    {
        $action = (string) ($input['action'] ?? '');
        $reason = isset($input['reason']) && trim((string) $input['reason']) !== ''
            ? trim((string) $input['reason'])
            : null;
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];

        $message = match ($entry['type']) {
            self::TYPE_DELETION => $this->decideDeletion($entry, $action, $actorId),
            self::TYPE_MAPPING => $this->decideMapping($entry, $action, $fields, $actorId),
            self::TYPE_CONFLICT => $this->decideConflict($entry, $action, $fields, $actorId),
            self::TYPE_DUPLICATE => $this->decideDuplicate($entry, $action, $reason, $actorId),
            self::TYPE_INVALID => $this->decideInvalid($entry, $action, $reason, $actorId),
            default => throw new InvalidArgumentException('Unknown review decision type.'),
        };

        return ['message' => $message, 'type' => $entry['type'], 'key' => $entry['key']];
    }

    private function decideDeletion(array $entry, string $action, ?int $actorId): string
    {
        if (! in_array($action, ['keep_record', 'approve_deletion'], true)) {
            throw new InvalidArgumentException('Choose whether to keep the beneficiary or approve the deletion.');
        }

        $this->operations->resolveConflict($entry['conflict'], $action, [], $actorId);

        return $action === 'keep_record'
            ? 'The deletion was rejected; the beneficiary was kept and restored on the requesting installation.'
            : 'The deletion was approved; the central record was archived.';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function decideMapping(array $entry, string $action, array $fields, ?int $actorId): string
    {
        if ($action === 'discard_batch') {
            $this->operations->resolveConflict($entry['conflict'], 'discard_batch', [], $actorId);

            return 'The unknown batch was discarded; its held records stay on the requesting installation.';
        }

        if ($action !== 'map_batch') {
            throw new InvalidArgumentException('Choose how to map the batch, or discard it.');
        }

        $targetBatchId = isset($fields['target_batch_id']) && $fields['target_batch_id'] !== ''
            ? (int) $fields['target_batch_id']
            : null;
        $newBatchName = trim((string) ($fields['new_batch_name'] ?? ''));

        if ($targetBatchId === null && $newBatchName === '') {
            throw new InvalidArgumentException('Choose an existing central batch or enter a new batch name.');
        }

        $this->operations->resolveConflict($entry['conflict'], 'map_batch', [
            'target_batch_id' => $targetBatchId,
            'new_batch_name' => $newBatchName,
        ], $actorId);

        return $targetBatchId !== null
            ? 'The batch was mapped onto an existing central batch; its held records were applied.'
            : 'The batch was created centrally under the installation\'s batch identity; its held records were applied.';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function decideConflict(array $entry, string $action, array $fields, ?int $actorId): string
    {
        if (! in_array($action, ['accept_local', 'merge', 'keep_central'], true)) {
            throw new InvalidArgumentException('Choose how to resolve the conflicting fields.');
        }

        if ($action === 'merge' && $fields === []) {
            throw new InvalidArgumentException('Select at least one field to merge, or choose another resolution.');
        }

        $this->operations->resolveConflict(
            $entry['conflict'],
            $action,
            $action === 'merge' ? $fields : [],
            $actorId,
        );

        return match ($action) {
            'accept_local' => 'The submitted values were accepted over the central record.',
            'merge' => 'The selected fields were merged into the central record.',
            default => 'The central record was kept; the submission was rejected and the offline server notified.',
        };
    }

    private function decideDuplicate(array $entry, string $action, ?string $reason, ?int $actorId): string
    {
        if ($action === 'reject') {
            $this->reviews->reject($entry['item'], $reason ?? 'Rejected during duplicate review.', $actorId);

            return 'The duplicate submission was rejected; the offline installation keeps its local record.';
        }

        if (! in_array($action, ['link', 'update'], true)) {
            throw new InvalidArgumentException('Choose whether the submission is the same person or should update the existing record.');
        }

        $this->reviews->approve($entry['item'], $actorId, ['action' => $action, 'reason' => $reason]);

        return $action === 'link'
            ? 'Confirmed as the same person; the existing central record was kept as is.'
            : 'The submitted values were applied onto the existing central record.';
    }

    private function decideInvalid(array $entry, string $action, ?string $reason, ?int $actorId): string
    {
        if ($action === 'reject') {
            if ($reason === null) {
                throw new InvalidArgumentException('A rejection reason is required so the offline server knows why.');
            }

            $this->reviews->reject($entry['item'], $reason, $actorId);

            return 'The record was rejected; the offline installation keeps its local copy.';
        }

        if ($action !== 'accept') {
            throw new InvalidArgumentException('Choose whether to accept or reject the held record.');
        }

        $this->reviews->approve($entry['item'], $actorId, ['action' => '', 'reason' => $reason]);

        return 'The held record was accepted and applied on the central server.';
    }

    /**
     * @return array<string, mixed>
     */
    private function entryFromConflict(SyncConflict $conflict): array
    {
        $type = match ($conflict->operation) {
            'deleted' => self::TYPE_DELETION,
            'mapping' => self::TYPE_MAPPING,
            default => self::TYPE_CONFLICT,
        };

        $summary = match ($type) {
            self::TYPE_DELETION => 'Requested removal of this beneficiary.',
            self::TYPE_MAPPING => 'Records belong to a batch the central server has never seen.',
            default => 'Overlapping edits between the central record and the submitted values.',
        };

        return [
            'key' => 'conflict-'.$conflict->conflict_uuid,
            'type' => $type,
            'label' => self::LABELS[$type],
            'summary' => $summary,
            'detail' => $this->conflictDetail($conflict),
            'server' => $conflict->server?->name ?? 'Unknown server',
            'entity' => $conflict->entity_type === 'beneficiary' ? 'Beneficiary' : 'Batch',
            'created' => $conflict->created_at,
            'age_key' => $conflict->created_at?->getTimestamp() ?? 0,
            'severity' => $type === self::TYPE_DELETION ? 'danger' : 'warning',
            'conflict' => $conflict,
            'item' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryFromItem(SyncRequestItem $item): array
    {
        $type = $item->duplicate_rule !== null ? self::TYPE_DUPLICATE : self::TYPE_INVALID;
        $payload = $item->payload ?? [];
        $code = $payload['beneficiary_code'] ?? null;
        $name = trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? ''));

        return [
            'key' => 'item-'.$item->id,
            'type' => $type,
            'label' => self::LABELS[$type],
            'summary' => $type === self::TYPE_DUPLICATE
                ? 'Held because '.DuplicateDetector::ruleLabel($item->duplicate_rule).' matches an existing record.'
                : 'Held: the submitted record failed validation or was flagged for review.',
            'detail' => $code !== null && $code !== ''
                ? 'Beneficiary '.$code.($name !== '' ? ' — '.$name : '')
                : ($name !== '' ? $name : substr($item->entity_uuid, 0, 8)),
            'server' => $item->request?->server?->name ?? 'Unknown server',
            'entity' => $item->entity_type === 'beneficiary' ? 'Beneficiary' : 'Batch',
            'created' => $item->created_at,
            'age_key' => $item->created_at?->getTimestamp() ?? 0,
            'severity' => $type === self::TYPE_DUPLICATE ? 'warning' : 'danger',
            'conflict' => null,
            'item' => $item,
        ];
    }

    /**
     * Extra context required by the decision page (never used by the queue).
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function context(array $entry): array
    {
        if ($entry['conflict'] instanceof SyncConflict) {
            $conflict = $entry['conflict'];

            $heldRecords = 0;
            $rosterName = null;

            if ($conflict->operation === 'mapping') {
                $heldRecords = SyncRequestItem::query()
                    ->whereHas('request', fn ($query) => $query->where('offline_server_id', $conflict->offline_server_id))
                    ->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)
                    ->where('payload->beneficiary_batch_uuid', $conflict->entity_uuid)
                    ->count();

                foreach ((array) data_get($conflict->server?->metadata, 'last_roster.batches', []) as $roster) {
                    if (($roster['uuid'] ?? null) === $conflict->entity_uuid) {
                        $rosterName = $roster['name'] ?? null;

                        break;
                    }
                }
            }

            return [
                'conflict' => $conflict,
                'local' => (array) $conflict->local_payload,
                'remote' => (array) $conflict->remote_payload,
                'diff' => (array) $conflict->diff,
                'held_records' => $heldRecords,
                'roster_name' => $rosterName,
                'batch_options' => BeneficiaryBatch::query()->orderBy('name')->get(['id', 'name']),
            ];
        }

        $item = $entry['item'];

        $matched = null;

        if ($item instanceof SyncRequestItem) {
            $codes = $item->duplicate_matches ?? [];

            if (is_array($codes) && $codes !== []) {
                $matched = Beneficiary::query()->whereIn('beneficiary_code', $codes)->first();
            }
        }

        return [
            'item' => $item,
            'payload' => (array) ($item?->payload ?? []),
            'matched' => $matched,
            'duplicate_matches' => (array) ($item?->duplicate_matches ?? []),
        ];
    }

    private function conflictDetail(SyncConflict $conflict): string
    {
        if ($conflict->operation === 'deleted') {
            $code = data_get($conflict->local_payload, 'beneficiary_code');

            return $code !== null && $code !== '' ? 'Beneficiary '.$code : substr($conflict->entity_uuid, 0, 8);
        }

        if ($conflict->operation === 'mapping') {
            $uuid = $conflict->entity_uuid;

            foreach ((array) data_get($conflict->server?->metadata, 'last_roster.batches', []) as $entry) {
                if (($entry['uuid'] ?? null) === $uuid && ($entry['name'] ?? '') !== '') {
                    return 'Batch "'.$entry['name'].'"';
                }
            }

            return 'Batch '.substr($uuid, 0, 8);
        }

        $code = data_get($conflict->local_payload, 'beneficiary_code');

        return $code !== null && $code !== '' ? 'Beneficiary '.$code : substr($conflict->entity_uuid, 0, 8);
    }
}
