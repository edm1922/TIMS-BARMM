<?php

namespace App\Http\Controllers;

use App\Services\Sync\ReviewQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The single decision page for a review item. Every type of pending decision
 * (deletion approval, batch mapping, field conflict, duplicate, invalid record)
 * is rendered and decided here, so the reviewer never has to learn two
 * different workspaces.
 */
class ReviewItemController extends Controller
{
    public function show(string $reviewItem, ReviewQueueService $queue): View|RedirectResponse
    {
        try {
            $entry = $queue->find($reviewItem);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('review-inbox.index')
                ->with('error', $exception->getMessage());
        }

        return view('review-inbox.show', [
            'entry' => $entry,
            'context' => $queue->context($entry),
        ]);
    }

    public function decide(Request $request, string $reviewItem, ReviewQueueService $queue): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
            'target_batch_id' => ['nullable', 'integer'],
            'new_batch_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $entry = $queue->find($reviewItem);

            $result = $queue->decide($entry, [
                'action' => $validated['action'] ?? '',
                'reason' => $validated['reason'] ?? null,
                'fields' => $validated['fields'] ?? [],
                'target_batch_id' => $validated['target_batch_id'] ?? null,
                'new_batch_name' => $validated['new_batch_name'] ?? null,
            ], $request->user()?->id);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('review-inbox.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('review-inbox.index')
            ->with('success', $result['message']);
    }
}
