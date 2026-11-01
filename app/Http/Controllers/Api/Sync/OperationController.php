<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Models\OfflineServer;
use App\Models\SyncCheckpoint;
use App\Services\Sync\SyncOperationService;
use App\Services\Sync\SyncRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationController extends Controller
{
    public function store(Request $request, SyncOperationService $operations, SyncRequestService $requests): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:100'],
            'operations.*.operation_uuid' => ['required', 'uuid'],
            'operations.*.entity_type' => ['required', 'in:beneficiary,beneficiary_batch'],
            'operations.*.entity_uuid' => ['required', 'uuid'],
            'operations.*.operation' => ['required', 'in:created,updated,deleted,archived,unarchived,restored'],
            'operations.*.base_revision' => ['nullable', 'integer', 'min:0'],
            'operations.*.payload' => ['present', 'array'],
        ]);

        $server = $this->server($request);

        // Every upload becomes a traceable review request. Records the server
        // can apply safely are applied on receipt; duplicates, conflicts, and
        // invalid records are held as awaiting_review for an administrator.
        $syncRequest = $requests->create($server, $data['operations'], $request->user()?->id);

        $results = $operations->process($server, $data['operations']);

        if ($syncRequest !== null) {
            $requests->recordResults($syncRequest, $data['operations'], $results);
            $syncRequest->refresh();
        }

        $counts = array_count_values(array_column($results, 'status'));

        return response()->json([
            'data' => $results,
            'meta' => [
                'count' => count($results),
                'applied' => $counts['applied'] ?? 0,
                'duplicate' => $counts['duplicate'] ?? 0,
                'conflict' => $counts['conflict'] ?? 0,
                'rejected' => $counts['rejected'] ?? 0,
                'request_uuid' => $syncRequest?->request_uuid,
                'awaiting_review' => $syncRequest?->pending_count ?? 0,
                'request_status' => $syncRequest?->status,
            ],
        ]);
    }

    public function ack(Request $request): JsonResponse
    {
        $server = $this->server($request);

        $data = $request->validate([
            'download_revision' => ['nullable', 'integer', 'min:0'],
            'upload_revision' => ['nullable', 'integer', 'min:0'],
            'cursor' => ['nullable', 'string', 'max:100'],
        ]);

        foreach (['download' => $data['download_revision'] ?? null, 'upload' => $data['upload_revision'] ?? null] as $direction => $revision) {
            if ($revision === null) {
                continue;
            }

            SyncCheckpoint::query()->updateOrCreate(
                [
                    'offline_server_id' => $server->id,
                    'direction' => $direction,
                ],
                [
                    'last_revision' => (int) $revision,
                    'cursor' => $direction === 'download' ? ($data['cursor'] ?? null) : null,
                    'synced_at' => now(),
                ],
            );
        }

        $server->forceFill(['last_sync_at' => now()])->save();

        return response()->json([
            'data' => [
                'server_uuid' => $server->server_uuid,
                'acknowledged_at' => now()->toIso8601String(),
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
