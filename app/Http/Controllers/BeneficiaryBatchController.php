<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBeneficiaryBatchRequest;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\OfflineServerEncoder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BeneficiaryIdGenerator;
use App\Services\Sync\SyncDeletionGuard;
use App\Support\SyncIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Operational release/payout batches. Groups beneficiaries for a round of
 * release, distribution, or verification work. A beneficiary belongs to at
 * most one batch; registering a beneficiary inside a batch files the new
 * record into it.
 */
class BeneficiaryBatchController extends Controller
{
    /** Upper bound on beneficiaries affected in one request. */
    private const MAX_ASSIGN = 1000;

    /** Maximum number of beneficiary ids recorded in an audit entry. */
    private const AUDIT_ID_LIMIT = 25;

    public function __construct(private readonly SyncDeletionGuard $syncDeletionGuard) {}

    public function index(): View
    {
        Gate::authorize('viewAny', BeneficiaryBatch::class);

        $batches = BeneficiaryBatch::query()
            ->withMemberCount()
            ->with(['creator', 'manager.server', 'managerUser'])
            ->orderByDesc('id')
            ->paginate(25);

        return view('batches.index', [
            'batches' => $batches,
            'managers' => $this->managers(),
            'counts' => [
                'total' => BeneficiaryBatch::query()->count(),
                'open' => BeneficiaryBatch::query()->open()->count(),
                'closed' => BeneficiaryBatch::query()->where('status', 'closed')->count(),
                'members' => Beneficiary::query()->active()->whereNotNull('beneficiary_batch_id')->count(),
            ],
        ]);
    }

    /**
     * The batch management view: members, and every batch-specific action.
     */
    public function show(BeneficiaryBatch $beneficiaryBatch): View
    {
        Gate::authorize('view', $beneficiaryBatch);

        $beneficiaryBatch->load(['creator', 'manager.server', 'managerUser']);

        // Registration order matches the order the ID series is applied in.
        $members = $beneficiaryBatch->beneficiaries()
            ->orderBy('id')
            ->paginate(25);

        // Candidates for "Assign existing beneficiaries": active records that
        // are not currently filed in any batch (the reassignment path after a
        // removal). Capped so a very large unassigned pool cannot bloat the
        // page; the picker filter narrows the rendered list client side.
        $assignable = Beneficiary::query()
            ->active()
            ->unassigned()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'beneficiary_code', 'first_name', 'middle_name', 'last_name', 'barangay', 'city_municipality']);

        return view('batches.show', [
            'batch' => $beneficiaryBatch,
            'members' => $members,
            'managers' => $this->managers(),
            'assignable' => $assignable,
        ]);
    }

    /**
     * Create a batch. The name is the identity of a batch, so a repeated
     * submission (double click, back button, retry) opens the batch that
     * already exists instead of creating a duplicate.
     */
    public function store(StoreBeneficiaryBatchRequest $request): RedirectResponse
    {
        Gate::authorize('create', BeneficiaryBatch::class);

        $name = trim((string) $request->input('name'));

        $batch = DB::transaction(function () use ($request, $name): BeneficiaryBatch {
            $origin = SyncIdentity::originUuid();

            $existing = BeneficiaryBatch::query()
                ->withTrashed()
                ->where('origin_server_uuid', $origin)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                return $existing;
            }

            // createOrFirst() swallows the unique-index violation raised by a
            // concurrent double submission and returns the winning row.
            return BeneficiaryBatch::query()->createOrFirst(
                ['origin_server_uuid' => $origin, 'name' => $name],
                $request->batchPayload(),
            );
        });

        if (! $batch->wasRecentlyCreated) {
            return redirect()
                ->route('beneficiary-batches.show', $batch)
                ->with('success', 'Batch "'.$batch->name.'" already exists — opening it instead.');
        }

        AuditLogger::log('beneficiary_batch.created', 'beneficiary_batch', $batch->id, [
            'name' => $batch->name,
        ]);

        return redirect()
            ->route('beneficiary-batches.show', $batch)
            ->with('success', 'Batch "'.$batch->name.'" created.');
    }

    /**
     * Re-issue the IDs of the batch's active members from a starting code, in
     * registration order. Codes already used by other beneficiaries are skipped
     * so the unique constraint can never be violated. The batch's stored series
     * is advanced to the next free code.
     */
    private function applyCodeSeries(BeneficiaryBatch $beneficiaryBatch, string $startingCode): int
    {
        $generator = app(BeneficiaryIdGenerator::class);

        $members = Beneficiary::query()
            ->where('beneficiary_batch_id', $beneficiaryBatch->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();

        if ($members->isEmpty()) {
            return 0;
        }

        $otherCodes = Beneficiary::query()
            ->whereNotIn('id', $members->pluck('id'))
            ->pluck('beneficiary_code')
            ->flip();

        // Park members on unique temporary codes first so a straight swap
        // (A→B, B→A) cannot collide with the unique index mid-way.
        foreach ($members as $member) {
            $member->forceFill([
                'beneficiary_code' => 'TMP-'.$member->id.'-'.substr(md5(uniqid((string) $member->id, true)), 0, 6),
            ])->save();
        }

        $candidate = $startingCode;
        $used = [];

        foreach ($members as $member) {
            while ($otherCodes->has($candidate) || isset($used[$candidate])) {
                $candidate = $generator->incrementSeries($candidate);
            }

            $member->forceFill(['beneficiary_code' => $candidate])->save();
            $used[$candidate] = true;
            $candidate = $generator->incrementSeries($candidate);
        }

        $beneficiaryBatch->forceFill(['beneficiary_code_series' => $candidate])->save();

        return $members->count();
    }

    /**
     * Release beneficiaries from the batch. The beneficiary records are kept —
     * only the batch membership is cleared.
     */
    public function removeMembers(Request $request, BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('update', $beneficiaryBatch);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSIGN],
            'ids.*' => ['integer', 'exists:beneficiaries,id'],
        ]);

        $ids = $this->normalizeIds($data['ids']);

        if ($ids === []) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Select at least one beneficiary to remove from the batch.');
        }

        $removed = 0;

        DB::transaction(function () use ($ids, $beneficiaryBatch, &$removed): void {
            // Save each member individually so sync observers record a queued
            // operation and revision for every affected beneficiary.
            $members = Beneficiary::query()
                ->whereIn('id', $ids)
                ->where('beneficiary_batch_id', $beneficiaryBatch->id)
                ->get();

            foreach ($members as $member) {
                $member->forceFill(['beneficiary_batch_id' => null])->save();
                $removed++;
            }
        });

        AuditLogger::log('beneficiary_batch.unassigned', 'beneficiary_batch', $beneficiaryBatch->id, [
            'batch_name' => $beneficiaryBatch->name,
            'count' => $removed,
            'beneficiary_ids' => Beneficiary::query()
                ->whereIn('id', $ids)
                ->whereNull('beneficiary_batch_id')
                ->orderBy('id')
                ->limit(self::AUDIT_ID_LIMIT)
                ->pluck('id')
                ->all(),
        ]);

        return redirect()
            ->route('beneficiary-batches.show', $beneficiaryBatch)
            ->with('success', Str::plural('beneficiary', $removed).' removed from batch "'.$beneficiaryBatch->name.'".');
    }

    /**
     * File existing beneficiaries into this batch. Removal only clears the
     * membership, so this is the reassignment path: a beneficiary taken out of
     * one batch is filed into another from the gaining batch's page. Only
     * currently unassigned beneficiaries can be assigned here — a member of a
     * different batch must be removed there first, so one manager cannot
     * silently take people out of another manager's batch. Each membership is
     * saved individually so sync observers record a queued operation and
     * revision for every affected beneficiary.
     */
    public function addMembers(Request $request, BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('update', $beneficiaryBatch);

        if (! $beneficiaryBatch->isOpen()) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Batch "'.$beneficiaryBatch->name.'" is closed — reopen it before assigning beneficiaries.');
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSIGN],
            'ids.*' => ['integer', 'exists:beneficiaries,id'],
        ]);

        $ids = $this->normalizeIds($data['ids']);

        if ($ids === []) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Select at least one beneficiary to assign.');
        }

        $remaining = $beneficiaryBatch->remainingCapacity();

        if ($remaining === 0) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with('error', 'Batch "'.$beneficiaryBatch->name.'" is full (limit '
                    .number_format($beneficiaryBatch->capacityLimit()).'). Raise the limit in Settings to assign more beneficiaries.');
        }

        $assigned = 0;
        $skipped = 0;
        $overLimit = 0;

        DB::transaction(function () use ($ids, $beneficiaryBatch, $remaining, &$assigned, &$skipped, &$overLimit): void {
            $candidates = Beneficiary::query()
                ->whereIn('id', $ids)
                ->active()
                ->get();

            foreach ($candidates as $candidate) {
                if ($candidate->beneficiary_batch_id === $beneficiaryBatch->id) {
                    continue; // already a member — nothing to change
                }

                if ($candidate->beneficiary_batch_id !== null) {
                    $skipped++; // still filed in another batch

                    continue;
                }

                if ($remaining !== null && $assigned >= $remaining) {
                    $overLimit++;

                    continue;
                }

                $candidate->forceFill(['beneficiary_batch_id' => $beneficiaryBatch->id])->save();
                $assigned++;
            }
        });

        AuditLogger::log('beneficiary_batch.assigned', 'beneficiary_batch', $beneficiaryBatch->id, [
            'batch_name' => $beneficiaryBatch->name,
            'count' => $assigned,
            'beneficiary_ids' => Beneficiary::query()
                ->whereIn('id', $ids)
                ->where('beneficiary_batch_id', $beneficiaryBatch->id)
                ->orderBy('id')
                ->limit(self::AUDIT_ID_LIMIT)
                ->pluck('id')
                ->all(),
        ]);

        $message = Str::plural('beneficiary', $assigned).' assigned to batch "'.$beneficiaryBatch->name.'".';

        if ($skipped > 0) {
            $message .= ' Skipped '.$skipped.' — still assigned to another batch; remove them there first.';
        }

        if ($overLimit > 0) {
            $message .= ' '.$overLimit.' not assigned — the batch limit of '
                .number_format((int) $beneficiaryBatch->capacityLimit()).' reached.';
        }

        return redirect()
            ->route('beneficiary-batches.show', $beneficiaryBatch)
            ->with('success', $message);
    }

    public function update(Request $request, BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('update', $beneficiaryBatch);

        $isAdmin = $request->user()?->isAdmin() === true;

        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('beneficiary_batches', 'name')
                    ->where(fn ($query) => $query->where('origin_server_uuid', $beneficiaryBatch->origin_server_uuid))
                    ->ignore($beneficiaryBatch->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:open,closed'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'profiled_on' => ['nullable', 'date'],
            'beneficiary_code_series' => ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== null && trim((string) $value) !== ''
                    && ! BeneficiaryIdGenerator::isValidSeries((string) $value)) {
                    $fail('The beneficiary ID series must end with a number so it can increment automatically.');
                }
            }],
        ];

        if ($isAdmin) {
            $rules['manager_id'] = [
                'nullable',
                'integer',
                Rule::exists('offline_server_encoders', 'id')->where(
                    fn ($query) => $query->where('role', User::ROLE_MANAGER)->where('is_active', true)
                ),
            ];
        }

        $data = $request->validate($rules);

        // A limit can be raised or cleared, but never set below the number of
        // beneficiaries already in the batch.
        $capacity = array_key_exists('capacity', $data) && $data['capacity'] !== null && $data['capacity'] !== ''
            ? (int) $data['capacity']
            : null;

        if ($capacity !== null && $capacity < $beneficiaryBatch->memberCount()) {
            return back()
                ->withInput()
                ->withErrors(['capacity' => 'The limit cannot be lower than the current number of members ('
                    .number_format($beneficiaryBatch->memberCount()).').']);
        }

        $attributes = [
            'name' => trim($data['name']),
            'description' => ($description = trim((string) ($data['description'] ?? ''))) !== '' ? $description : null,
            'status' => $data['status'],
        ];

        if (array_key_exists('capacity', $data)) {
            $attributes['capacity'] = $capacity;
        }

        $profileDateChanged = false;
        $newProfileDate = $beneficiaryBatch->profiled_on?->toDateString();

        if (array_key_exists('profiled_on', $data)) {
            $profileDate = trim((string) ($data['profiled_on'] ?? ''));
            $newProfileDate = $profileDate !== '' ? $profileDate : null;
            $attributes['profiled_on'] = $newProfileDate;
            $profileDateChanged = $newProfileDate !== null;
        }

        $seriesChanged = false;
        $newSeries = $beneficiaryBatch->beneficiary_code_series;

        if (array_key_exists('beneficiary_code_series', $data)) {
            $series = trim((string) ($data['beneficiary_code_series'] ?? ''));
            $newSeries = $series !== '' ? $series : null;
            $attributes['beneficiary_code_series'] = $newSeries;
            $seriesChanged = $newSeries !== null && $newSeries !== $beneficiaryBatch->beneficiary_code_series;
        }

        if ($isAdmin) {
            $managerId = $data['manager_id'] ?? null;

            $attributes['manager_id'] = $managerId;
            $attributes['manager_uuid'] = $managerId !== null
                ? OfflineServerEncoder::query()->whereKey($managerId)->value('encoder_uuid')
                : null;
        }

        $renumbered = 0;

        $reprofiled = 0;

        DB::transaction(function () use ($beneficiaryBatch, $attributes, $seriesChanged, $newSeries, $profileDateChanged, $newProfileDate, &$renumbered, &$reprofiled): void {
            $beneficiaryBatch->update($attributes);

            // Setting a new series also re-issues the member IDs: the first
            // member in registration order gets the entered code, then the
            // series increments through the rest of the list.
            if ($seriesChanged && $newSeries !== null) {
                $renumbered = $this->applyCodeSeries($beneficiaryBatch, $newSeries);
            }

            // The batch registration date is applied to every beneficiary in it.
            if ($profileDateChanged && $newProfileDate !== null) {
                $reprofiled = Beneficiary::query()
                    ->where('beneficiary_batch_id', $beneficiaryBatch->id)
                    ->update(['profiled_on' => $newProfileDate]);
            }
        });

        AuditLogger::log('beneficiary_batch.updated', 'beneficiary_batch', $beneficiaryBatch->id, [
            'name' => $beneficiaryBatch->name,
            'status' => $beneficiaryBatch->status,
            'renumbered' => $renumbered,
            'profiled_on' => $beneficiaryBatch->profiled_on?->toDateString(),
            'reprofiled' => $reprofiled,
        ]);

        $message = 'Batch "'.$beneficiaryBatch->name.'" updated.';

        if ($renumbered > 0) {
            $message .= ' '.number_format($renumbered).' beneficiary '.($renumbered === 1 ? 'ID was' : 'IDs were')
                .' renumbered to the new series.';
        }

        if ($reprofiled > 0) {
            $message .= ' '.number_format($reprofiled).' beneficiary '.($reprofiled === 1 ? 'date was' : 'dates were')
                .' updated to '.$newProfileDate.'.';
        }

        return redirect()
            ->route('beneficiary-batches.show', $beneficiaryBatch)
            ->with('success', $message);
    }

    /**
     * Delete a batch together with every beneficiary filed in it. The batch row
     * is soft deleted, but the beneficiaries are removed permanently: they
     * belong to the batch and must not be left behind as unassigned records.
     * The operator confirms by typing the batch name.
     */
    public function destroy(Request $request, BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('delete', $beneficiaryBatch);

        $validated = $request->validate([
            // The UI confirms through a modal; older clients may still send a
            // typed name, which is validated only when present.
            'confirm_name' => ['nullable', 'string', 'max:100'],
            'delete_reason' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['confirm_name']) && $validated['confirm_name'] !== ''
            && ! hash_equals((string) $beneficiaryBatch->name, trim($validated['confirm_name']))) {
            return back()->withErrors([
                'confirm_name' => 'The batch name you typed does not match. Deletion cancelled.',
            ]);
        }

        $members = $beneficiaryBatch->beneficiaries()->get();

        $blocked = $members->first(
            fn (Beneficiary $member): bool => $this->syncDeletionGuard->blocksDeletion($member)
        );

        if ($blocked !== null) {
            return back()->withErrors([
                'delete' => 'Beneficiary '.$blocked->beneficiary_code.' has unsynced changes or an open sync review. Reconcile them before deleting the batch.',
            ]);
        }

        $name = $beneficiaryBatch->name;
        $id = $beneficiaryBatch->id;
        $reason = $validated['delete_reason'] ?? null;
        $count = $members->count();

        DB::transaction(function () use ($members, $beneficiaryBatch, $name, $reason): void {
            foreach ($members as $member) {
                $this->syncDeletionGuard->supersedePendingOperations(
                    $member,
                    'Superseded by a deletion of batch "'.$name.'".',
                );

                // The audit snapshot is written before the row is removed,
                // because a hard delete leaves nothing to inspect afterwards.
                AuditLogger::log('beneficiary.deleted', 'beneficiary', $member->id, [
                    'beneficiary_code' => $member->beneficiary_code,
                    'full_name' => $member->full_name,
                    'birthdate' => $member->birthdate?->toDateString(),
                    'location_code' => $member->location_code,
                    'beneficiary_batch_uuid' => $beneficiaryBatch->global_uuid,
                    'revision' => $member->revision,
                    'origin_server_uuid' => $member->origin_server_uuid,
                    'delete_reason' => $reason,
                    'deleted_with_batch' => true,
                    'batch_name' => $name,
                ]);

                $member->delete();
            }

            $beneficiaryBatch->delete();
        });

        AuditLogger::log('beneficiary_batch.deleted', 'beneficiary_batch', $id, [
            'name' => $name,
            'deleted_beneficiary_count' => $count,
            'beneficiary_ids' => $members->take(self::AUDIT_ID_LIMIT)->pluck('id')->all(),
            'delete_reason' => $reason,
        ]);

        return redirect()
            ->route('beneficiary-batches.index')
            ->with('success', $count > 0
                ? 'Batch "'.$name.'" deleted with '.Str::plural('beneficiary', $count).' permanently removed.'
                : 'Batch "'.$name.'" deleted.');
    }

    /**
     * Active managers reported by offline installations who can be assigned
     * to a batch. Managers are created on the offline installation, not here.
     *
     * @return Collection<int, OfflineServerEncoder>
     */
    private function managers()
    {
        return OfflineServerEncoder::query()
            ->with('server')
            ->where('role', User::ROLE_MANAGER)
            ->where('is_active', true)
            ->whereHas('server', fn ($query) => $query->active())
            ->orderBy('username')
            ->get();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => $id > 0,
        )));
    }
}
