<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * ID card preview and print (docs/id-card-layout.md).
 * Front page 1, back page 2 per card; cards print at 99.4 × 130.2 mm
 * (confirmed 50% of the FRONT/BACK.pdf frames).
 */
class IdCardController extends Controller
{
    private const MAX_BATCH = 500;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $status = (string) $request->query('status', 'active');
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
        ];

        $query = Beneficiary::query()->orderBy('last_name')->orderBy('first_name');

        match ($filters['status']) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        if ($filters['q'] !== '') {
            $query->search($filters['q']);
        }

        $beneficiaries = $query->paginate(20)->withQueryString();

        return view('id-cards.index', [
            'beneficiaries' => $beneficiaries,
            'filters' => $filters,
        ]);
    }

    /**
     * Batch print: consecutive (front, back) page pairs for the selected ids.
     */
    public function print(Request $request): View|RedirectResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->query('ids', [])),
            fn (int $id) => $id > 0,
        )));

        if ($ids === []) {
            return redirect()
                ->route('id-cards.index')
                ->with('error', 'Select at least one beneficiary to print ID cards.');
        }

        if (count($ids) > self::MAX_BATCH) {
            return redirect()
                ->route('id-cards.index')
                ->with('error', 'Batch printing is limited to '.self::MAX_BATCH.' cards at a time.');
        }

        $beneficiaries = Beneficiary::query()->whereIn('id', $ids)->orderBy('id')->get();

        if ($beneficiaries->isEmpty()) {
            return redirect()
                ->route('id-cards.index')
                ->with('error', 'None of the selected beneficiaries exist.');
        }

        foreach ($beneficiaries as $beneficiary) {
            Gate::authorize('view', $beneficiary);
        }

        AuditLogger::log('id_card.printed', 'beneficiary', null, [
            'mode' => 'batch',
            'count' => $beneficiaries->count(),
            'beneficiary_ids' => $beneficiaries->take(25)->pluck('id')->all(),
        ]);

        return view('id-cards.print', [
            'beneficiaries' => $beneficiaries,
            'mode' => 'batch',
        ]);
    }

    /**
     * Single card: front on page 1, back on page 2.
     */
    public function single(Beneficiary $beneficiary): View
    {
        Gate::authorize('view', $beneficiary);

        AuditLogger::log('id_card.printed', 'beneficiary', $beneficiary->id, [
            'mode' => 'single',
        ]);

        return view('id-cards.print', [
            'beneficiaries' => collect([$beneficiary]),
            'mode' => 'single',
        ]);
    }
}
