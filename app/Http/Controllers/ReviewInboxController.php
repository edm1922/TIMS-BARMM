<?php

namespace App\Http\Controllers;

use App\Services\Sync\ReviewQueueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The single review queue (Option A). It aggregates every pending decision
 * into one list; the ReviewQueueService owns the domain vocabulary and the
 * decision page.
 */
class ReviewInboxController extends Controller
{
    public function index(Request $request, ReviewQueueService $queue): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'kind' => (string) $request->query('kind', ''),
        ];

        $entries = $queue->pending();
        $counts = $queue->counts($entries);

        $filtered = $queue->filter($entries, $filters);
        $paginator = $this->paginate($filtered, $filters, $request->query('page'));

        return view('review-inbox.index', [
            'entries' => $paginator,
            'counts' => $counts,
            'filters' => $filters,
            'allClear' => $counts['total'] === 0,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array{q: string, kind: string}  $filters
     */
    private function paginate(Collection $entries, array $filters, mixed $page): LengthAwarePaginator
    {
        $perPage = 25;
        $currentPage = max(1, (int) $page);

        return new Paginator(
            $entries->forPage($currentPage, $perPage)->values(),
            $entries->count(),
            $perPage,
            $currentPage,
            [
                'path' => route('review-inbox.index'),
                'query' => array_filter($filters, fn (string $value): bool => $value !== ''),
            ],
        );
    }
}
