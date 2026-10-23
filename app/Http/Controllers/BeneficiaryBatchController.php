<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBeneficiaryBatchRequest;
use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function index(): View
    {
        Gate::authorize('viewAny', BeneficiaryBatch::class);

        $batches = BeneficiaryBatch::query()
            ->withMemberCount()
            ->with('creator')
            ->orderByDesc('id')
            ->paginate(25);

        return view('batches.index', [
            'batches' => $batches,
        ]);
    }

    /**
     * The batch management view: members, and every batch-specific action.
     */
    public function show(BeneficiaryBatch $beneficiaryBatch): View
    {
        Gate::authorize('view', $beneficiaryBatch);

        $beneficiaryBatch->load('creator');

        $members = $beneficiaryBatch->beneficiaries()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25);

        return view('batches.show', [
            'batch' => $beneficiaryBatch,
            'members' => $members,
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
            $existing = BeneficiaryBatch::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // createOrFirst() swallows the unique-index violation raised by a
            // concurrent double submission and returns the winning row.
            return BeneficiaryBatch::query()->createOrFirst(
                ['name' => $name],
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
     * Release beneficiaries from the batch. The beneficiary records are kept —
     * only the batch membership is cleared.
     */
    public function removeMembers(Request $request, BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('assign', BeneficiaryBatch::class);

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
            $removed = Beneficiary::query()
                ->whereIn('id', $ids)
                ->where('beneficiary_batch_id', $beneficiaryBatch->id)
                ->update(['beneficiary_batch_id' => null]);
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

    public function update(Request $request, BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('update', $beneficiaryBatch);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('beneficiary_batches', 'name')->ignore($beneficiaryBatch->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:open,closed'],
        ]);

        $beneficiaryBatch->update([
            'name' => trim($data['name']),
            'description' => ($description = trim((string) ($data['description'] ?? ''))) !== '' ? $description : null,
            'status' => $data['status'],
        ]);

        AuditLogger::log('beneficiary_batch.updated', 'beneficiary_batch', $beneficiaryBatch->id, [
            'name' => $beneficiaryBatch->name,
            'status' => $beneficiaryBatch->status,
        ]);

        return redirect()
            ->route('beneficiary-batches.show', $beneficiaryBatch)
            ->with('success', 'Batch "'.$beneficiaryBatch->name.'" updated.');
    }

    public function destroy(BeneficiaryBatch $beneficiaryBatch): RedirectResponse
    {
        Gate::authorize('delete', $beneficiaryBatch);

        $members = $beneficiaryBatch->beneficiaries()->count();

        if ($members > 0) {
            return redirect()
                ->route('beneficiary-batches.show', $beneficiaryBatch)
                ->with(
                    'error',
                    'Batch "'.$beneficiaryBatch->name.'" still has '.Str::plural('beneficiary', $members).'. Reassign or remove them before deleting it.',
                );
        }

        $name = $beneficiaryBatch->name;
        $id = $beneficiaryBatch->id;

        $beneficiaryBatch->delete();

        AuditLogger::log('beneficiary_batch.deleted', 'beneficiary_batch', $id, [
            'name' => $name,
        ]);

        return redirect()
            ->route('beneficiary-batches.index')
            ->with('success', 'Batch "'.$name.'" deleted.');
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
