<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\SyncConflict;
use App\Services\Sync\SyncOperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class SyncConflictController extends Controller
{
    /**
     * Unified conflict workspace. Both former tabs ("Sync Conflicts" on the
     * central server and "Local Conflicts" on an offline installation) read
     * the same sync_conflicts table; `source` distinguishes where the conflict
     * was detected.
     */
    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'entity_type' => (string) $request->query('entity_type', ''),
            'source' => (string) $request->query('source', 'all'),
        ];

        $query = SyncConflict::query()
            ->with('server', 'resolver')
            ->latest('id');

        if (in_array($filters['status'], ['open', 'resolved'], true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['entity_type'] !== '') {
            $query->where('entity_type', $filters['entity_type']);
        }

        if ($filters['source'] === 'server') {
            $query->whereNotNull('offline_server_id');
        } elseif ($filters['source'] === 'local') {
            $query->whereNull('offline_server_id');
        }

        $conflicts = $query->paginate(20)->withQueryString();

        $counts = [
            'open' => SyncConflict::where('status', 'open')->count(),
            'resolved' => SyncConflict::where('status', 'resolved')->count(),
            'server' => SyncConflict::whereNotNull('offline_server_id')->count(),
            'local' => SyncConflict::whereNull('offline_server_id')->count(),
        ];

        return view('sync-conflicts.index', [
            'conflicts' => $conflicts,
            'filters' => $filters,
            'counts' => $counts,
            'recordLinks' => $this->recordLinks($conflicts->getCollection()),
        ]);
    }

    /**
     * Show the side-by-side diff and resolution form for a single conflict.
     */
    public function show(SyncConflict $syncConflict): View
    {
        $syncConflict->load('server', 'resolver');

        $decisionHistory = \App\Models\SyncConflictDecision::query()
            ->where('conflict_uuid', $syncConflict->conflict_uuid)
            ->with('resolver')
            ->latest('id')
            ->get();

        $heldRecords = 0;
        $rosterName = null;

        if ($syncConflict->operation === 'mapping') {
            // How many records are waiting behind this mapping decision, and
            // what the installation's roster calls this batch.
            $heldRecords = \App\Models\SyncRequestItem::query()
                ->whereHas('request', fn ($query) => $query->where('offline_server_id', $syncConflict->offline_server_id))
                ->where('status', \App\Models\SyncRequestItem::STATUS_AWAITING_REVIEW)
                ->where('payload->beneficiary_batch_uuid', $syncConflict->entity_uuid)
                ->count();

            $roster = data_get($syncConflict->server?->metadata, 'last_roster.batches', []);

            foreach ((array) $roster as $entry) {
                if (($entry['uuid'] ?? null) === $syncConflict->entity_uuid) {
                    $rosterName = $entry['name'] ?? null;

                    break;
                }
            }
        }

        return view('sync-conflicts.show', [
            'conflict' => $syncConflict,
            'recordLink' => $this->recordLinks(collect([$syncConflict]))[$this->recordKey($syncConflict)] ?? null,
            'heldRecords' => $heldRecords,
            'rosterName' => $rosterName,
            'decisionHistory' => $decisionHistory,
        ]);
    }

    /**
     * Process the resolution and redirect back with a result message.
     */
    public function resolve(Request $request, SyncConflict $syncConflict, SyncOperationService $operations): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['keep_central', 'accept_local', 'merge', 'keep_record', 'approve_deletion', 'map_batch', 'discard_batch'])],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ]);

        try {
            $operations->resolveConflict(
                $syncConflict,
                $validated['action'],
                $validated['fields'] ?? [],
                $request->user()?->id,
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['resolution' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sync-conflicts.index', ['source' => $syncConflict->offline_server_id ? 'server' : 'local'])
            ->with('success', 'Conflict resolved successfully.');
    }

    /**
     * Resolve the affected beneficiary/batch so the workspace can deep-link
     * back to the record.
     *
     * @param  Collection<int, SyncConflict>  $conflicts
     * @return array<string, array{label: string, url: string, exists: bool}>
     */
    private function recordLinks(Collection $conflicts): array
    {
        $beneficiaryUuids = $conflicts
            ->where('entity_type', 'beneficiary')
            ->pluck('entity_uuid')
            ->unique()
            ->values();

        $batchUuids = $conflicts
            ->where('entity_type', 'beneficiary_batch')
            ->pluck('entity_uuid')
            ->unique()
            ->values();

        $links = [];

        if ($beneficiaryUuids->isNotEmpty()) {
            Beneficiary::query()
                ->whereIn('global_uuid', $beneficiaryUuids)
                ->get(['id', 'global_uuid', 'beneficiary_code', 'first_name', 'last_name'])
                ->each(function (Beneficiary $beneficiary) use (&$links): void {
                    $links['beneficiary|'.$beneficiary->global_uuid] = [
                        'label' => $beneficiary->beneficiary_code ?: $beneficiary->full_name,
                        'url' => route('beneficiaries.show', $beneficiary),
                        'exists' => true,
                    ];
                });
        }

        if ($batchUuids->isNotEmpty()) {
            BeneficiaryBatch::withTrashed()
                ->whereIn('global_uuid', $batchUuids)
                ->get(['id', 'global_uuid', 'name'])
                ->each(function (BeneficiaryBatch $batch) use (&$links): void {
                    $links['beneficiary_batch|'.$batch->global_uuid] = [
                        'label' => $batch->name,
                        'url' => route('beneficiary-batches.show', $batch),
                        'exists' => true,
                    ];
                });
        }

        return $links;
    }

    private function recordKey(SyncConflict $conflict): string
    {
        return $conflict->entity_type.'|'.$conflict->entity_uuid;
    }
}
