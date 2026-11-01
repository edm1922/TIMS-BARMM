<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncRequest;
use App\Models\SyncRequestDecision;
use App\Models\SyncRequestItem;
use App\Services\DuplicateDetector;
use App\Services\Sync\SyncRequestReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Central server review workspace for sync requests submitted by offline
 * servers. A request is the reviewable unit; each submitted record inside it
 * is compared with the central data and accepted or rejected individually.
 */
class SyncRequestController extends Controller
{
    /**
     * Rejection codes that describe the submitted data rather than a decision.
     * They are surfaced as an actionable explanation to the reviewer.
     *
     * @var array<int, string>
     */
    private const VALIDATION_CODES = [
        'validation_failed',
        'invalid_payload',
        'not_found',
        'unsupported_operation',
        'unsupported_entity',
    ];

    /**
     * @var array<string, string>
     */
    private const FAILURE_LABELS = [
        'validation_failed' => 'Required data is missing or invalid for this record.',
        'invalid_payload' => 'The submitted payload could not be read.',
        'not_found' => 'The record does not exist on the central server, so it cannot be updated.',
        'unsupported_operation' => 'This operation is not supported for the record type.',
        'unsupported_entity' => 'This record type cannot be synchronized.',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', 'pending'),
            'server' => (string) $request->query('server', ''),
        ];

        $query = SyncRequest::query()
            ->with('server.scopes', 'submitter', 'reviewer')
            ->latest('id');

        if ($filters['status'] === 'pending') {
            $query->awaitingReview();
        } elseif (in_array($filters['status'], [
            SyncRequest::STATUS_ACCEPTED,
            SyncRequest::STATUS_PARTIALLY_ACCEPTED,
            SyncRequest::STATUS_REJECTED,
            SyncRequest::STATUS_FAILED,
        ], true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['server'] !== '') {
            $query->whereHas('server', fn ($inner) => $inner->where('server_uuid', $filters['server']));
        }

        $syncRequests = $query->paginate(20)->withQueryString();

        $counts = [
            'pending' => SyncRequest::query()->awaitingReview()->count(),
            'accepted' => SyncRequest::query()->where('status', SyncRequest::STATUS_ACCEPTED)->count(),
            'partially_accepted' => SyncRequest::query()->where('status', SyncRequest::STATUS_PARTIALLY_ACCEPTED)->count(),
            'rejected' => SyncRequest::query()->where('status', SyncRequest::STATUS_REJECTED)->count(),
            'pending_records' => SyncRequestItem::query()->awaitingReview()->count(),
        ];

        return view('sync-requests.index', [
            'syncRequests' => $syncRequests,
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    public function show(SyncRequest $syncRequest): View
    {
        $syncRequest->load(['server.scopes', 'submitter', 'reviewer', 'items' => fn ($query) => $query->orderBy('id')]);

        $duplicates = app(DuplicateDetector::class);

        $review = $syncRequest->items->map(function (SyncRequestItem $item) use ($duplicates): array {
            $central = $this->centralRecord($item);
            $advisory = $this->weakMatchAdvisory($item, $duplicates);

            return [
                'item' => $item,
                'central' => $central,
                'matches' => $this->duplicateMatches($item),
                'conflict' => $item->conflict,
                'incoming' => $this->incomingValues($item),
                'advisory' => $advisory,
                'guidance' => $this->guidance($item, $central, $advisory),
                'failure_label' => $this->failureLabel($item),
            ];
        });

        $entityUuids = $syncRequest->items->pluck('entity_uuid')->unique()->values();

        return view('sync-requests.show', [
            'syncRequest' => $syncRequest,
            'review' => $review,
            'summary' => $this->summarize($review),
            'decisions' => $syncRequest->decisions()->with('decider')->latest('id')->get(),
            'priorDecisions' => SyncRequestDecision::query()
                ->whereIn('entity_uuid', $entityUuids)
                ->where('sync_request_id', '!=', $syncRequest->id)
                ->with('decider', 'request')
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function approve(
        Request $request,
        SyncRequest $syncRequest,
        SyncRequestItem $item,
        SyncRequestReviewService $review,
    ): RedirectResponse {
        $this->ensureBelongs($syncRequest, $item);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'action' => ['nullable', Rule::in(['accept_local', 'merge', 'keep_central', 'link', 'update'])],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ]);

        try {
            $review->approve($item, $request->user()?->id, [
                'reason' => $validated['reason'] ?? null,
                'action' => $validated['action'] ?? '',
                'fields' => $validated['fields'] ?? [],
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync-requests.show', $syncRequest)
            ->with('success', 'The submitted record was accepted.');
    }

    public function reject(
        Request $request,
        SyncRequest $syncRequest,
        SyncRequestItem $item,
        SyncRequestReviewService $review,
    ): RedirectResponse {
        $this->ensureBelongs($syncRequest, $item);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'A rejection reason is required so the offline server knows why.',
        ]);

        try {
            $review->reject($item, (string) $validated['reason'], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync-requests.show', $syncRequest)
            ->with('success', 'The submitted record was rejected.');
    }

    private function ensureBelongs(SyncRequest $syncRequest, SyncRequestItem $item): void
    {
        abort_unless((int) $item->sync_request_id === (int) $syncRequest->id, 404);
    }

    /**
     * The review summary a reviewer reads before deciding: what is new, what
     * collides with an existing record, what is merely similar, what is
     * invalid, and what conflicts. It makes the workload legible and reduces
     * accidental duplicate creation.
     *
     * @param  Collection<int, array<string, mixed>>  $review
     * @return array<string, int>
     */
    private function summarize(Collection $review): array
    {
        $items = $review->pluck('item');

        $summary = [
            'total' => $items->count(),
            'awaiting' => $items->where('status', SyncRequestItem::STATUS_AWAITING_REVIEW)->count(),
            'accepted' => $items->whereIn('status', [SyncRequestItem::STATUS_APPLIED, SyncRequestItem::STATUS_ACCEPTED])->count(),
            'rejected' => $items->where('status', SyncRequestItem::STATUS_REJECTED)->count(),
            'new_records' => 0,
            'updates' => 0,
            'exact_matches' => 0,
            'possible_duplicates' => 0,
            'invalid' => 0,
            'conflicts' => 0,
        ];

        foreach ($review as $entry) {
            /** @var SyncRequestItem $item */
            $item = $entry['item'];

            if ($item->conflict_uuid !== null) {
                $summary['conflicts']++;
            }

            if ($item->duplicate_rule !== null) {
                $summary['exact_matches']++;
            }

            if ($entry['advisory'] !== null) {
                $summary['possible_duplicates']++;
            }

            if ($entry['failure_label'] !== null) {
                $summary['invalid']++;
            }

            if ($item->operation === 'created' && $entry['central'] === null && $item->duplicate_rule === null) {
                $summary['new_records']++;
            }

            if ($item->operation !== 'created' && $entry['central'] !== null) {
                $summary['updates']++;
            }
        }

        return $summary;
    }

    /**
     * Weak (name-based) matches are advisory: they are shown to the reviewer
     * and never used to merge or to block a record.
     *
     * @return array{rule: string, label: string, matches: Collection<int, Beneficiary>}|null
     */
    private function weakMatchAdvisory(SyncRequestItem $item, DuplicateDetector $duplicates): ?array
    {
        if ($item->entity_type !== 'beneficiary'
            || $item->operation !== 'created'
            || $item->duplicate_rule !== null
            || $item->conflict_uuid !== null) {
            return null;
        }

        $payload = $item->payload ?? [];

        $match = $duplicates->findForRegistration(
            $payload['first_name'] ?? null,
            $payload['middle_name'] ?? null,
            $payload['last_name'] ?? null,
            isset($payload['birthdate']) ? (string) $payload['birthdate'] : null,
        );

        if ($match === null) {
            return null;
        }

        return [
            'rule' => $match['rule'],
            'label' => DuplicateDetector::ruleLabel($match['rule']),
            'matches' => $match['matches'],
        ];
    }

    /**
     * One sentence explaining what acceptance actually does to this record.
     *
     * @param  array{label: string, url: string|null, deleted: bool, values: array<string, mixed>}|null  $central
     * @param  array{rule: string, label: string, matches: Collection<int, Beneficiary>}|null  $advisory
     */
    private function guidance(SyncRequestItem $item, ?array $central, ?array $advisory): ?string
    {
        if (! $item->isAwaitingReview()) {
            return null;
        }

        if ($item->conflict_uuid !== null) {
            return 'Accepting writes the submitted values over the central record; merging applies only the fields you select; keeping central rejects the submission.';
        }

        if ($item->duplicate_rule !== null) {
            return 'Held because '.DuplicateDetector::ruleLabel($item->duplicate_rule).' matches an existing record. Accepting either confirms the same person (nothing is inserted) or applies the submitted values onto that record.';
        }

        if ($advisory !== null) {
            return 'No unique identifier matches, but the '.strtolower($advisory['label']).' points at an existing record. Accepting creates a separate beneficiary — review the match first.';
        }

        return $central === null
            ? 'Accepting creates a new central beneficiary record.'
            : 'Accepting applies the submitted update to the central record.';
    }

    /**
     * Human-readable reason for a validation failure or server-side failure,
     * so the reviewer sees what is wrong instead of an error code.
     */
    private function failureLabel(SyncRequestItem $item): ?string
    {
        $code = $item->failure_code ?? ($item->result['code'] ?? null);

        if ($item->status === SyncRequestItem::STATUS_FAILED) {
            return self::FAILURE_LABELS[$code ?? ''] ?? 'The record could not be processed because of a server error.';
        }

        if (! in_array($code, self::VALIDATION_CODES, true)) {
            return null;
        }

        return self::FAILURE_LABELS[$code] ?? (string) ($item->result['message'] ?? 'The submitted record is not valid.');
    }

    /**
     * The central record a reviewer compares against: the beneficiary or batch
     * already stored under this global UUID, if it exists.
     *
     * @return array{label: string, url: string|null, values: array<string, mixed>}|null
     */
    private function centralRecord(SyncRequestItem $item): ?array
    {
        if ($item->entity_type === 'beneficiary_batch') {
            $batch = BeneficiaryBatch::withTrashed()->where('global_uuid', $item->entity_uuid)->first();

            if (! $batch instanceof BeneficiaryBatch) {
                return null;
            }

            return [
                'label' => $batch->name,
                'url' => $batch->trashed() ? null : route('beneficiary-batches.show', $batch),
                'deleted' => $batch->trashed(),
                'values' => $batch->only(['name', 'description', 'status', 'revision', 'deleted_at']),
            ];
        }

        $beneficiary = Beneficiary::query()->where('global_uuid', $item->entity_uuid)->first();

        if (! $beneficiary instanceof Beneficiary) {
            return null;
        }

        return [
            'label' => $beneficiary->beneficiary_code ?: $beneficiary->full_name,
            'url' => route('beneficiaries.show', $beneficiary),
            'deleted' => false,
            'values' => $beneficiary->only([
                'first_name', 'middle_name', 'last_name', 'birthdate', 'sex', 'civil_status',
                'location_code', 'source_id_no', 'contact_no', 'revision', 'duplicate_status', 'archived_at',
            ]),
        ];
    }

    /**
     * Potential duplicate matches recorded when the record was held. Both the
     * matched identifiers and the reason are shown; nothing is merged here.
     *
     * @return Collection<int, Beneficiary>
     */
    private function duplicateMatches(SyncRequestItem $item): Collection
    {
        $codes = array_values(array_filter((array) $item->duplicate_matches));

        if ($codes === []) {
            return collect();
        }

        return Beneficiary::query()
            ->where(function ($query) use ($codes): void {
                $query->whereIn('beneficiary_code', $codes)
                    ->orWhereIn('source_id_no', $codes);
            })
            ->limit(10)
            ->get();
    }

    /**
     * The submitted values, limited to the fields a reviewer actually needs to
     * compare. No tokens or credentials are ever part of an operation payload.
     *
     * @return array<string, mixed>
     */
    private function incomingValues(SyncRequestItem $item): array
    {
        $payload = $item->payload ?? [];

        if ($item->entity_type === 'beneficiary_batch') {
            return array_intersect_key($payload, array_flip(['name', 'description', 'status']));
        }

        return array_intersect_key($payload, array_flip([
            'first_name', 'middle_name', 'last_name', 'extension_name', 'birthdate', 'sex',
            'civil_status', 'location_code', 'barangay', 'city_municipality', 'province',
            'source_id_no', 'contact_no', 'beneficiary_type', 'notes',
        ]));
    }
}
