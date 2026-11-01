<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Models\OfflineServer;
use App\Models\SyncChange;
use App\Services\Sync\ScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangeController extends Controller
{
    public function index(Request $request, ScopeService $scopes): JsonResponse
    {
        $server = $this->server($request);

        $data = $request->validate([
            'since' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $since = (int) ($data['since'] ?? 0);
        $limit = (int) ($data['limit'] ?? config('sync.delta_page_size', 200));

        $scopedBeneficiaryUuids = $scopes
            ->applyToBeneficiaryQuery(Beneficiary::query(), $server)
            ->select('global_uuid');

        $query = SyncChange::query()
            ->where('operation', '!=', 'receipt')
            ->where('id', '>', $since)
            ->where(function ($changeQuery) use ($scopedBeneficiaryUuids, $server): void {
                $changeQuery
                    ->where('entity_type', 'beneficiary_batch')
                    ->orWhere(function ($beneficiaryQuery) use ($scopedBeneficiaryUuids): void {
                        $beneficiaryQuery
                            ->where('entity_type', 'beneficiary')
                            ->whereIn('entity_uuid', $scopedBeneficiaryUuids);
                    })
                    // Always include changes explicitly targeted to this server (e.g., removed_from_scope)
                    ->orWhere(function ($targetedQuery) use ($server): void {
                        $targetedQuery
                            ->where('offline_server_id', $server->id)
                            ->where('operation', 'removed_from_scope');
                    });
            })
            ->orderBy('id');

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;

        $items = $page->map(fn (SyncChange $change) => [
            'id' => $change->id,
            'change_uuid' => $change->change_uuid,
            'entity_type' => $change->entity_type,
            'entity_uuid' => $change->entity_uuid,
            'operation' => $change->operation,
            'origin_server_uuid' => $change->origin_server_uuid,
            'revision' => $change->revision,
            'payload' => $change->payload,
            'applied_at' => $change->applied_at?->toIso8601String(),
            'created_at' => $change->created_at?->toIso8601String(),
        ])->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'server_uuid' => $server->server_uuid,
                'since' => $since,
                'next_cursor' => $page->last()?->id,
                'has_more' => $hasMore,
                'count' => count($items),
            ],
        ]);
    }

    private function server(Request $request): OfflineServer
    {
        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        return $server;
    }
}
