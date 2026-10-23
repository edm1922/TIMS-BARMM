<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateCheckRequest;
use App\Http\Requests\StoreBeneficiaryRequest;
use App\Http\Requests\UpdateBeneficiaryRequest;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Services\AuditLogger;
use App\Services\DuplicateDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BeneficiaryController extends Controller
{
    public function __construct(private readonly DuplicateDetector $duplicates) {}

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
            'sex' => $request->query('sex', ''),
            'civil_status' => $request->query('civil_status', ''),
            'duplicate_status' => $request->query('duplicate_status', ''),
            'status' => $request->query('status', 'active'),
            'batch' => $this->resolveBatchFilter($request->query('batch', ''), $batches),
        ];

        $query = Beneficiary::query()->with('beneficiaryBatch')->latest('id');

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

        if (in_array($filters['sex'], ['MALE', 'FEMALE'], true)) {
            $query->where('sex', $filters['sex']);
        }

        if ($filters['civil_status'] !== '') {
            $query->where('civil_status', $filters['civil_status']);
        }

        if (in_array($filters['duplicate_status'], ['none', 'candidate', 'confirmed_duplicate', 'cleared'], true)) {
            $query->where('duplicate_status', $filters['duplicate_status']);
        }

        if ($filters['status'] === 'archived') {
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

        $beneficiaries = $query->paginate(25)->withQueryString();

        $civilStatuses = Beneficiary::query()
            ->select('civil_status')
            ->distinct()
            ->whereNotNull('civil_status')
            ->orderBy('civil_status')
            ->pluck('civil_status');

        return view('beneficiaries.index', compact('beneficiaries', 'filters', 'civilStatuses', 'batches'));
    }

    /**
     * Whitelist the requested batch filter: '', 'unassigned', or the id of a
     * batch that actually exists. Anything else is ignored.
     *
     * @param  \Illuminate\Support\Collection<int, BeneficiaryBatch>  $batches
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

        if (! $beneficiaryBatch->isOpen()) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Batch "'.$beneficiaryBatch->name.'" is closed — reopen it before adding beneficiaries.');
        }

        $payload = $request->beneficiaryPayload();
        $payload['beneficiary_batch_id'] = $beneficiaryBatch->id;

        $beneficiary = Beneficiary::createWithCode($payload);

        $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);

        $flags = [];

        if ($candidates !== null) {
            $beneficiary->forceFill(['duplicate_status' => 'candidate'])->save();
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
            ->with('duplicate_flags', $flags);
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

        $beneficiary->update($payload);

        $candidates = $this->duplicates->findFor($beneficiary, $beneficiary->id);

        if ($candidates !== null && $beneficiary->duplicate_status !== 'confirmed_duplicate') {
            $beneficiary->forceFill(['duplicate_status' => 'candidate'])->save();
        } elseif ($candidates === null && $beneficiary->duplicate_status === 'candidate') {
            $beneficiary->forceFill(['duplicate_status' => 'none'])->save();
        }

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

        $beneficiary->archive($request->input('archive_reason'));

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

        $beneficiary->unarchive();

        AuditLogger::log('beneficiary.unarchived', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
        ]);

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Beneficiary restored.');
    }

    public function resolveDuplicate(Request $request, Beneficiary $beneficiary): RedirectResponse
    {
        Gate::authorize('resolveDuplicate', $beneficiary);

        $request->validate([
            'decision' => ['required', 'in:cleared,confirmed_duplicate'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $beneficiary->forceFill([
            'duplicate_status' => $request->input('decision'),
            'duplicate_note' => $request->input('note'),
        ])->save();

        AuditLogger::log('beneficiary.duplicate_resolved', 'beneficiary', $beneficiary->id, [
            'beneficiary_code' => $beneficiary->beneficiary_code,
            'decision' => $request->input('decision'),
        ]);

        return redirect()
            ->route('beneficiaries.show', $beneficiary)
            ->with('success', 'Duplicate decision recorded.');
    }
}
