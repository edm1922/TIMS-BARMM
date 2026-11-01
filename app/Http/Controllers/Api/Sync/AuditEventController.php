<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OfflineServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuditEventController extends Controller
{
    /**
     * Receive audit events and the encoder registry from an offline server.
     *
     * The records are stored with the identity of the installation that
     * produced them, so the central trail stays attributable and durable even
     * when the original account no longer exists centrally.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        $data = $request->validate([
            'events' => ['nullable', 'array', 'max:500'],
            'events.*.event_uuid' => ['required', 'string', 'max:36'],
            'events.*.action' => ['required', 'string', 'max:80'],
            'events.*.entity_type' => ['nullable', 'string', 'max:80'],
            'events.*.entity_id' => ['nullable', 'integer'],
            'events.*.actor_uuid' => ['nullable', 'string', 'max:36'],
            'events.*.actor_name' => ['nullable', 'string', 'max:255'],
            'events.*.actor_username' => ['nullable', 'string', 'max:50'],
            'events.*.actor_role' => ['nullable', 'string', 'max:20'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.metadata' => ['nullable', 'array'],
            'events.*.ip' => ['nullable', 'string', 'max:45'],
            'encoders' => ['nullable', 'array', 'max:500'],
            'encoders.*.encoder_uuid' => ['required', 'string', 'max:36'],
            'encoders.*.username' => ['required', 'string', 'max:50'],
            'encoders.*.name' => ['nullable', 'string', 'max:255'],
            'encoders.*.role' => ['nullable', 'string', 'max:20'],
            'encoders.*.is_active' => ['nullable', 'boolean'],
        ]);

        $accepted = DB::transaction(function () use ($data, $server): array {
            $accepted = [];
            $created = 0;

            foreach ($data['events'] ?? [] as $event) {
                $exists = AuditLog::query()->where('event_uuid', $event['event_uuid'])->exists();

                AuditLog::query()->updateOrCreate(
                    ['event_uuid' => $event['event_uuid']],
                    [
                        'user_id' => null,
                        'actor_uuid' => $event['actor_uuid'] ?? null,
                        'actor_name' => $event['actor_name'] ?? null,
                        'actor_username' => $event['actor_username'] ?? null,
                        'actor_role' => $event['actor_role'] ?? null,
                        'origin_server_uuid' => $server->server_uuid,
                        'occurred_at' => isset($event['occurred_at'])
                            ? Carbon::parse($event['occurred_at'])
                            : now(),
                        'synced_at' => now(),
                        'action' => $event['action'],
                        'entity_type' => $event['entity_type'] ?? null,
                        'entity_id' => $event['entity_id'] ?? null,
                        'metadata' => $event['metadata'] ?? null,
                        'ip' => $event['ip'] ?? null,
                    ],
                );

                $accepted[] = $event['event_uuid'];

                if (! $exists) {
                    $created++;
                }
            }

            foreach ($data['encoders'] ?? [] as $encoder) {
                $server->encoders()->updateOrCreate(
                    ['encoder_uuid' => $encoder['encoder_uuid']],
                    [
                        'username' => $encoder['username'],
                        'name' => $encoder['name'] ?? null,
                        'role' => $encoder['role'] ?? 'encoder',
                        'is_active' => (bool) ($encoder['is_active'] ?? true),
                        'reported_at' => now(),
                    ],
                );
            }

            return ['accepted' => $accepted, 'created' => $created];
        });

        return response()->json([
            'data' => [
                'accepted_events' => count($accepted['accepted']),
                'accepted_event_uuids' => $accepted['accepted'],
                'created_events' => $accepted['created'],
                'accepted_encoders' => count($data['encoders'] ?? []),
                'server_uuid' => $server->server_uuid,
            ],
        ]);
    }
}
