<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Models\OfflineServer;
use App\Models\SyncConflict;
use App\Services\Sync\SyncOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ConflictController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $data['status'] ?? 'open';
        $limit = (int) ($data['limit'] ?? 50);

        $query = SyncConflict::query()
            ->where('offline_server_id', $this->server($request)->id)
            ->latest('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $conflicts = $query->limit($limit)->get()->map(fn (SyncConflict $conflict) => [
            'conflict_uuid' => $conflict->conflict_uuid,
            'entity_type' => $conflict->entity_type,
            'entity_uuid' => $conflict->entity_uuid,
            'origin_server_uuid' => $conflict->origin_server_uuid,
            'status' => $conflict->status,
            'local_payload' => $conflict->local_payload,
            'remote_payload' => $conflict->remote_payload,
            'diff' => $conflict->diff,
            'created_at' => $conflict->created_at?->toIso8601String(),
            'resolved_at' => $conflict->resolved_at?->toIso8601String(),
            'resolution' => $conflict->resolution,
        ])->all();

        return response()->json([
            'data' => $conflicts,
            'meta' => [
                'count' => count($conflicts),
                'status' => $status,
            ],
        ]);
    }

    public function resolve(Request $request, string $conflictUuid, SyncOperationService $operations): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['keep_central', 'accept_local', 'merge'])],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ]);

        $conflict = SyncConflict::query()
            ->where('conflict_uuid', $conflictUuid)
            ->where('offline_server_id', $this->server($request)->id)
            ->firstOrFail();

        try {
            $result = $operations->resolveConflict(
                $conflict,
                $data['action'],
                $data['fields'] ?? [],
                $request->user()?->getAuthIdentifier(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'conflict_resolution_failed',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    private function server(Request $request): OfflineServer
    {
        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        return $server;
    }
}
