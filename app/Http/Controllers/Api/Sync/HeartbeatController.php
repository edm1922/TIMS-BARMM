<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Models\OfflineServer;
use App\Support\SyncIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $server = $this->server($request);

        $data = $request->validate([
            'app_version' => ['nullable', 'string', 'max:50'],
            'protocol_version' => ['nullable', 'integer', 'min:1'],
            'schema_version' => ['nullable', 'integer', 'min:1'],
            'queue' => ['nullable', 'array'],
            'queue.pending' => ['nullable', 'integer', 'min:0'],
            'queue.failed' => ['nullable', 'integer', 'min:0'],
            'queue.conflicts' => ['nullable', 'integer', 'min:0'],
            'queue.awaiting_review' => ['nullable', 'integer', 'min:0'],
            'queue.synced' => ['nullable', 'integer', 'min:0'],
            'queue.last_error' => ['nullable', 'string', 'max:500'],
            'queue.reported_at' => ['nullable', 'string', 'max:40'],
            'batches' => ['nullable', 'array', 'max:100'],
            'batches.*.uuid' => ['nullable', 'uuid'],
            'batches.*.name' => ['nullable', 'string', 'max:120'],
            'batches.*.deleted' => ['nullable', 'boolean'],
            'batches.*.members' => ['nullable', 'integer', 'min:0'],
        ]);

        $metadata = $server->metadata ?? [];

        if (is_array($data['queue'] ?? null)) {
            $metadata['last_reported'] = array_merge(
                $metadata['last_reported'] ?? [],
                $data['queue'],
                ['reported_at' => $data['queue']['reported_at'] ?? now()->toIso8601String()],
            );
        }

        if (is_array($data['batches'] ?? null)) {
            // The roster drives the central reconciliation report; it replaces
            // the previous one wholesale so the report always reflects a
            // single consistent snapshot of the server's state.
            $metadata['last_roster'] = [
                'captured_at' => now()->toIso8601String(),
                'batches' => $data['batches'],
            ];
        }

        $server->forceFill([
            'app_version' => $data['app_version'] ?? $server->app_version,
            'protocol_version' => $data['protocol_version'] ?? $request->attributes->get('sync_protocol_version', $server->protocol_version),
            'schema_version' => $data['schema_version'] ?? $request->attributes->get('sync_schema_version', $server->schema_version),
            'last_seen_at' => now(),
            'metadata' => $metadata,
        ])->save();

        return response()->json([
            'data' => $this->serverPayload($server, $request),
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $server = $this->server($request);

        $server->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'data' => [
                'status' => 'ok',
                'server_uuid' => $server->server_uuid,
                'server_time' => now()->toIso8601String(),
                'compatibility' => SyncIdentity::compatibility(),
            ],
        ]);
    }

    private function server(Request $request): OfflineServer
    {
        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        return $server;
    }

    /**
     * @return array<string, mixed>
     */
    private function serverPayload(OfflineServer $server, Request $request): array
    {
        return [
            'server_uuid' => $server->server_uuid,
            'name' => $server->name,
            'status' => $server->status,
            'server_time' => now()->toIso8601String(),
            'last_seen_at' => $server->last_seen_at?->toIso8601String(),
            'last_sync_at' => $server->last_sync_at?->toIso8601String(),
            'compatibility' => SyncIdentity::compatibility(),
            'protocol_version' => (int) $request->attributes->get('sync_protocol_version', $server->protocol_version),
            'schema_version' => (int) $request->attributes->get('sync_schema_version', $server->schema_version),
            'scopes' => $server->scopes()
                ->orderBy('location_code')
                ->get()
                ->map(fn ($scope) => [
                    'scope_type' => $scope->scope_type,
                    'location_code' => $scope->location_code,
                    'province' => $scope->province,
                    'city_municipality' => $scope->city_municipality,
                    'barangay' => $scope->barangay,
                ])
                ->all(),
        ];
    }
}
