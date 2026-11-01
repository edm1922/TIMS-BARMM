<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateCheckRequest;
use App\Http\Requests\StoreBeneficiaryRequest;
use App\Http\Requests\UpdateBeneficiaryRequest;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServerEncoder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DuplicateDetector;
use App\Services\Sync\SyncDeletionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

class BeneficiaryController extends Controller
{
    public function __construct(
        private readonly DuplicateDetector $duplicates,
        private readonly SyncDeletionGuard $syncDeletionGuard,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $batches = BeneficiaryBatch::query()
            ->withMemberCount()
            ->orderBy('name')
            ->get();

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'age_status' => $request->query('age_status', ''),
            'age_range' => $request->query('age_range', ''),
            'registered_year' => $request->query('registered_year', ''),
            'sex' => $request->query('sex', ''),
            'civil_status' => $request->query('civil_status', ''),
            'duplicate_status' => $request->query('duplicate_status', ''),
            'status' => $request->query('status', 'active'),
            'sort' => in_array($request->query('sort', 'registered'), ['registered', 'newest', 'name', 'name_desc'], true)
                ? (string) $request->query('sort', 'registered')
                : 'registered',
            'batch' => $this->resolveBatchFilter($request->query('batch', ''), $batches),
        ];

        $query = Beneficiary::query()->with('beneficiaryBatch');

        if ($filters['q'] !== '') {
            $query->search($filters['q']);
        }

        $cutoff = now()->subYears(18)->toDateString();

        match ($filters['age_status']) {
            'underage' => $query->whereNotNull('birthdate')->whereDate('birthdate', '>', $cutoff),
            'adult' => $query->whereNotNull('birthdate')->whereDate('birthdate', '<=', $cutoff),
            'unknown' => $query->whereNull('birthdate'),
            default => null,
        };

        // Age-range groups used by the dashboard demographics cards. The same
        // birthdate cutoffs as the dashboard, evaluated against today.
        $ageRange = $filters['age_range'];
        if (in_array($ageRange, ['below18', '18-25', '26-31', '32-40', '41-50', '51-60', '61plus', 'unclassified'], true)) {
            if ($ageRange === 'unclassified') {
                $query->where(function ($q): void {
                    $q->whereNull('birthdate')->orWhereDate('birthdate', '>', now()->toDateString());
                });
            } else {
                $bounds = [
                    'below18' => [null, 18],
                    '18-25' => [18, 26],
                    '26-31' => [26, 32],
                    '32-40' => [32, 41],
                    '41-50' => [41, 51],
                    '51-60' => [51, 61],
                    '61plus' => [61, 150],
                ][$ageRange];

                $query->whereNotNull('birthdate')
                    ->whereDate('birthdate', '<=', now()->toDateString());

                if ($bounds[0] !== null) {
                    $query->whereDate('birthdate', '<=', now()->subYears($bounds[0])->toDateString());
                }

                $query->whereDate('birthdate', '>', now()->subYears($bounds[1])->toDateString());
            }
        }

        if (in_array($filters['sex'], ['MALE', 'M', 'FEMALE', 'F'], true)) {
            $query->whereIn('sex', $filters['sex'] === 'MALE' || $filters['sex'] === 'M' ? ['MALE', 'M'] : ['FEMALE', 'F']);
        }

        // Year the beneficiary record was registered in TIMS: the profiling
        // date set by the importer when present, otherwise the record
        // creation timestamp — the same rule as the dashboard trend chart.
        // SUBSTR keeps the query portable across MySQL/MariaDB and SQLite.
        if ($filters['registered_year'] === 'unclassified') {
            $query->whereNull('profiled_on')->whereNull('created_at');
        } elseif (preg_match('/^\d{4}$/', (string) $filters['registered_year']) === 1) {
            $query->whereRaw('SUBSTR(COALESCE(profiled_on, created_at), 1, 4) = ?', [(string) $filters['registered_year']]);
        }

        if ($filters['civil_status'] !== '') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $filters['civil_status']))));

            if ($statuses !== []) {
                $query->whereIn('civil_status', $statuses);
            }
        }

        $missingFieldsOnly = function ($missing): void {
            $missing->where(function ($inner): void {
                $inner->whereNotNull('missing_fields')
                    ->orWhere(fn ($gov) => $gov->whereNull('source_id_no')->orWhere('source_id_no', ''))
                    ->orWhereNull('birthdate')
                    ->orWhereNull('sex')
                    ->orWhereNull('civil_status');
            });
        };

        if ($filters['duplicate_status'] === 'missing') {
            // Kept for old links; the list UI exposes this via Records.
            $query->where($missingFieldsOnly);
        } elseif (in_array($filters['duplicate_status'], ['none', 'candidate', 'confirmed_duplicate', 'cleared'], true)) {
            $query->where('duplicate_status', $filters['duplicate_status']);
        }

        if ($filters['status'] === 'missing') {
            $query->active();
            $query->where($missingFieldsOnly);
        } elseif ($filters['status'] === 'archived') {
            $query->archived();
        } elseif ($filters['status'] === 'all') {
            // no filter
        } else {
            $query->active();
        }

        if ($filters['batch'] === 'unassigned') {
            $query->unassigned();
        } elseif (is_numeric($filters['batch'])) {
            $query->where('beneficiary_batch_id', (int) $filters['batch']);
        }

        // Registration order is the default: the sequence in which records
        // actually entered the register (their id), not alphabetical order.
        match ($filters['sort']) {
            'newest' => $query->orderByDesc('id'),
            'name' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('middle_name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('last_name')->orderByDesc('first_name')->orderByDesc('middle_name')->orderByDesc('id'),
            default => $query->orderBy('id'),
        };

        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $beneficiaries = $query->paginate($perPage)->withQueryString();

        $missingFieldsOnly = function ($missing): void {
            $missing->where(function ($inner): void {
                $inner->whereNotNull('missing_fields')
                    ->orWhere(fn ($gov) => $gov->whereNull('source_id_no')->orWhere('source_id_no', ''))
                    ->orWhereNull('birthdate')
                    ->orWhereNull('sex')
                    ->orWhereNull('civil_status');
            });
        };

        $counts = [
            'records' => Beneficiary::query()->active()->count(),
            'unassigned' => Beneficiary::query()->active()->unassigned()->count(),
            'missing' => Beneficiary::query()->active()->where($missingFieldsOnly)->count(),
            'batches' => $batches->count(),
        ];

        $civilStatuses = Beneficiary::query()
            ->select('civil_status')
            ->distinct()
            ->whereNotNull('civil_status')
            ->orderBy('civil_status')
            ->pluck('civil_status');

        return view('beneficiaries.index', compact('beneficiaries', 'filters', 'civilStatuses', 'batches', 'counts', 'perPage'))
            ->with('managers', OfflineServerEncoder::query()
                ->with('server')
                ->where('role', User::ROLE_MANAGER)
                ->where('is_active', true)
                ->whereHas('server', fn ($query) => $query->active())
                ->orderBy('username')
                ->get());
    }

    /**
     * Whitelist the requested batch filter: '', 'unassigned', or the id of a
     * batch that actually exists. Anything else is ignored.
     *
     * @param  Collection<int, BeneficiaryBatch>  $batches
     */
    private function resolveBatchFilter(mixed $requested, $batches): string
    {
        $requested = is_string($requested) ? trim($requested) : '';

        if ($requested === 'unassigned') {
            return 'unassigned';
        }

        if ($requested !== '' && ctype_digit($requested)) {
            $id = (int) $requested;

            if ($batches->contains('id', $id)) {
                return (string) $id;
            }
        }

        return '';
    }

    /**
     * Manual registration happens inside a release batch: the batch is the
     * container a beneficiary is registered into, so the operator first creates
     * the batch and then adds the beneficiary to it.
     */
    public function createInBatch(BeneficiaryBatch $beneficiaryBatch): View|RedirectResponse
    {
        Gate::authorize('create', Beneficiary::class);
        Gate::authorize('addBeneficiary', $beneficiaryBatch);

        if (! $beneficiaryBatch->isOpen()) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Batch "'.$beneficiaryBatch->name.'" is closed — reopen it before adding beneficiaries.');
        }

        return view('batches.beneficiaries.create', [
            'batch' => $beneficiaryBatch,
        ]);
    }

    /**
     * Store a beneficiary registered inside a release batch. The batch is taken
     * from the route, never from the request body, so a record cannot be filed
     * into a batch the operator did not open.
     */
    public function storeInBatch(BeneficiaryBatch $beneficiaryBatch, StoreBeneficiaryRequest $request): RedirectResponse
    {
        Gate::authorize('create', Beneficiary::class);
        Gate::authorize('addBeneficiary', $beneficiaryBatch);

        if (! $beneficiaryBatch->isOpen()) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Batch "'.$beneficiaryBatch->name.'" is closed — reopen it before adding beneficiaries.');
        }

        if (! $beneficiaryBatch->hasRoomFor(1)) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Batch "'.$beneficiaryBatch->name.'" is full (limit '
                    .number_format((int) $beneficiaryBatch->capacityLimit()).'). Raise the limit in Settings before adding more beneficiaries.');
        }

        $payload = $request->beneficiaryPayload();
        $payload['beneficiary_batch_id'] = $beneficiaryBatch->id;

        try {
            [$beneficiary, $candidates] = DB::transaction(function () use ($payload): array {
                $beneficiary = Beneficiary::createWithCode($payload);

                $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);

                if ($candidates !== null) {
                    $beneficiary->forceFill([
                        'duplicate_status' => 'candidate',
                        'duplicate_rule' => $candidates['rule'],
                    ])->save();
                }

                return [$beneficiary, $candidates];
            });
        } catch (RuntimeException $exception) {
            // The database constraint on (source_id_no, origin_server_uuid)
            // refused the insert: this government ID is already registered on
            // this server. The pre-registration modal only compares names, so
            // the operator can legitimately reach save — answer with a
            // validation error instead of a server error, and never create a
            // second record for the same person.
            return back()
                ->withInput()
                ->withErrors(['source_id_no' => $exception->getMessage()]);
        }

        $flags = [];

        if ($candidates !== null) {
            $flags = [
                'rule' => $candidates['rule'],
                'codes' => $candidates['matches']->pluck('beneficiary_code')->all(),
            ];
        }

        AuditLogger::log('beneficiary.created', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
            'age_status' => $beneficiary->ageStatus(),
            'duplicate_rule' => $flags['rule'] ?? null,
            'batch_name' => $beneficiaryBatch->name,
        ]);

        AuditLogger::log('beneficiary_batch.assigned', 'beneficiary_batch', $beneficiaryBatch->id, [
            'batch_name' => $beneficiaryBatch->name,
            'count' => 1,
            'beneficiary_ids' => [$beneficiary->id],
        ]);

        return redirect()
            ->route('beneficiary-batches.show', $beneficiaryBatch)
            ->with('success', 'Beneficiary created with ID '.$beneficiary->beneficiary_code.' in batch "'.$beneficiaryBatch->name.'".')
            ->with('duplicate_flags', $flags)
            ->with('created_beneficiary', [
                'name' => $beneficiary->full_name,
                'code' => $beneficiary->beneficiary_code,
                'batch' => $beneficiaryBatch->name,
                'url' => route('beneficiaries.show', $beneficiary),
                'add_url' => route('beneficiary-batches.beneficiaries.create', $beneficiaryBatch),
                'duplicate' => $candidates !== null,
                'duplicate_rule' => $candidates !== null
                    ? DuplicateDetector::ruleLabel($candidates['rule'])
                    : null,
            ]);
    }

    /**
     * Pre-registration duplicate check used by the modal. Read-only: returns
     * candidate records so the operator can review before saving. Never merges.
     */
    public function duplicateCheck(DuplicateCheckRequest $request): JsonResponse
    {
        Gate::authorize('create', Beneficiary::class);

        $payload = $request->validated();

        $candidates = $this->duplicates->findForRegistration(
            $payload['first_name'] ?? null,
            $payload['middle_name'] ?? null,
            $payload['last_name'] ?? null,
            isset($payload['birthdate']) ? (string) $payload['birthdate'] : null,
        );

        $matches = $candidates === null ? [] : $candidates['matches']->map(fn (Beneficiary $match) => [
            'beneficiary_code' => $match->beneficiary_code,
            'full_name' => $match->full_name,
            'birthdate' => $match->birthdate?->format('Y-m-d'),
            'address' => $match->display_address,
            'url' => route('beneficiaries.show', $match),
        ])->values()->all();

        return response()->json([
            'duplicate' => $candidates !== null,
            'rule' => $candidates['rule'] ?? null,
            'rule_label' => $candidates === null ? null : DuplicateDetector::ruleLabel($candidates['rule']),
            'matches' => $matches,
        ]);
    }

    public function show(Beneficiary $beneficiary): View
    {
        Gate::authorize('view', $beneficiary);

        $beneficiary->load('beneficiaryBatch');

        $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);

        return view('beneficiaries.show', compact('beneficiary', 'candidates'));
    }

    public function edit(Beneficiary $beneficiary): View
    {
        Gate::authorize('update', $beneficiary);

        $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);

        return view('beneficiaries.edit', compact('beneficiary', 'candidates'));
    }

    public function update(UpdateBeneficiaryRequest $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('update', $beneficiary);

        $payload = $request->beneficiaryPayload();

        $before = $beneficiary->only(array_keys($payload));

        $candidates = DB::transaction(function () use ($beneficiary, $payload): ?array {
            $beneficiary->update($payload);

            $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);

            if ($candidates !== null && $beneficiary->duplicate_status !== 'confirmed_duplicate') {
                $beneficiary->forceFill([
                    'duplicate_status' => 'candidate',
                    'duplicate_rule' => $candidates['rule'],
                ])->save();
            } elseif ($candidates === null && $beneficiary->duplicate_status === 'candidate') {
                $beneficiary->forceFill([
                    'duplicate_status' => 'none',
                    'duplicate_rule' => null,
                ])->save();
            }

            return $candidates;
        });

        AuditLogger::log('beneficiary.updated', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
            'changed_fields' => array_keys(array_diff_assoc($beneficiary->only(array_keys($before)), $before)),
            'duplicate_rule' => $candidates['rule'] ?? null,
        ]);

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Beneficiary updated.');
    }

    public function archive(Request $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('archive', $beneficiary);

        $request->validate(['archive_reason' => ['nullable', 'string', 'max:255']]);

        DB::transaction(fn () => $beneficiary->archive($request->input('archive_reason')));

        AuditLogger::log('beneficiary.archived', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
        ]);

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Beneficiary archived.');
    }

    public function unarchive(Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('unarchive', $beneficiary);

        DB::transaction(fn () => $beneficiary->unarchive());

        AuditLogger::log('beneficiary.unarchived', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
        ]);

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Beneficiary restored.');
    }

    /**
     * Permanently delete a beneficiary. This is destructive and irreversible,
     * so it is limited to administrators and managers, requires the operator
     * to retype the beneficiary ID as confirmation, and writes an immutable
     * audit snapshot before the row is removed. Records with unsynced changes
     * or an open sync review must be reconciled first.
     */
    public function destroy(Request $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('delete', $beneficiary);

        $validated = $request->validate([
            // The UI confirms through a modal; older clients may still send a
            // typed beneficiary ID, which is validated only when present.
            'confirm_code' => ['nullable', 'string', 'max:100'],
            'delete_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['confirm_code']) && $validated['confirm_code'] !== ''
            && ! hash_equals((string) $beneficiary->beneficiary_code, trim($validated['confirm_code']))) {
            return back()->withErrors([
                'confirm_code' => 'The beneficiary ID you typed does not match. Deletion cancelled.',
            ]);
        }

        if ($this->syncDeletionGuard->blocksDeletion($beneficiary)) {
            return back()->withErrors([
                'delete' => 'This beneficiary has unsynced changes or an open sync review. Reconcile them before deleting.',
            ]);
        }

        DB::transaction(function () use ($beneficiary, $validated): void {
            // Pending uploads for this record are superseded by the deletion
            // rather than silently discarded: they stay on record with a
            // reason, and are no longer attempted.
            $this->syncDeletionGuard->supersedePendingOperations(
                $beneficiary,
                'Superseded by a deletion of this record.',
            );

            // The audit snapshot is written before the row is removed, because
            // a hard delete leaves nothing to inspect afterwards.
            AuditLogger::log('beneficiary.deleted', 'beneficiary', $beneficiary->id, [
                'beneficiary_code' => $beneficiary->beneficiary_code,
                'full_name' => $beneficiary->full_name,
                'birthdate' => $beneficiary->birthdate?->toDateString(),
                'location_code' => $beneficiary->location_code,
                'beneficiary_batch_uuid' => $beneficiary->beneficiaryBatch?->global_uuid,
                'revision' => $beneficiary->revision,
                'origin_server_uuid' => $beneficiary->origin_server_uuid,
                'delete_reason' => $validated['delete_reason'] ?? null,
            ]);

            $beneficiary->delete();
        });

        return redirect()
            ->route('beneficiaries.index')
            ->with('success', 'Beneficiary deleted. The deletion is recorded in the audit trail.');
    }

    public function resolveDuplicate(Request $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('resolveDuplicate', $beneficiary);

        $request->validate([
            'decision' => ['required', 'in:cleared,confirmed_duplicate'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(fn () => $beneficiary->forceFill([
            'duplicate_status' => $request->input('decision'),
            'duplicate_note' => $request->input('note'),
        ])->save());

        AuditLogger::log('beneficiary.duplicate_resolved', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
            'decision' => $request->input('decision'),
        ]);

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Duplicate decision recorded.');
    }
}
