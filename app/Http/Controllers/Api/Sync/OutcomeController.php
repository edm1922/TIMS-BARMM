<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Models\SyncRequest;
use App\Models\SyncRequestItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-record review outcomes for the calling offline server.
 *
 * A record the central server held for review is only accepted or rejected
 * once an administrator decides. The offline server polls this endpoint with
 * the last item id it consumed, so retries are idempotent and the local queue
 * converges on the central decision.
 */
class OutcomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        $data = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $since = (int) ($data['since'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);

        $items = SyncRequestItem::query()
            ->with('request')
            ->whereIn('sync_request_id', SyncRequest::query()
                ->select('id')
                ->where('offline_server_id', $server->id))
            ->whereIn('status', [SyncRequestItem::STATUS_ACCEPTED, SyncRequestItem::STATUS_REJECTED])
            ->where('id', '>', $since)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $items->count() > $limit;
        $page = $items->take($limit);

        return response()->json([
            'data' => $page->map(function (SyncRequestItem $item): array {
                $result = $item->result ?? [];

                return [
                    'item_id' => $item->id,
                    'request_uuid' => $item->request?->request_uuid,
                    'operation_uuid' => $item->operation_uuid,
                    'entity_type' => $item->entity_type,
                    'entity_uuid' => $item->entity_uuid,
                    'status' => $item->status,
                    'code' => $result['code'] ?? null,
                    'message' => $item->decision_reason ?? ($result['message'] ?? null),
                    'revision' => $result['revision'] ?? null,
                    'decided_at' => $item->decided_at?->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => [
                'count' => $page->count(),
                'next_cursor' => $page->last()?->id,
                'has_more' => $hasMore,
            ],
        ]);
    }
}
