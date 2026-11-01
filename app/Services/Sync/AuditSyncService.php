<?php

namespace App\Services\Sync;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AuditSyncService
{
    /**
     * Audit records that have not reached the central server yet.
     *
     * @return Collection<int, AuditLog>
     */
    public function pending(int $limit = 100): Collection
    {
        return AuditLog::query()
            ->whereNull('synced_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function pendingCount(): int
    {
        return AuditLog::query()->whereNull('synced_at')->count();
    }

    /**
     * The payload sent to the central server. It carries only the identity
     * and the structured metadata of each event.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payload(int $limit = 100): array
    {
        return $this->pending($limit)
            ->map(fn (AuditLog $log) => [
                'event_uuid' => (string) $log->event_uuid,
                'action' => (string) $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'actor_uuid' => $log->actor_uuid,
                'actor_name' => $log->actor_name,
                'actor_username' => $log->actor_username,
                'actor_role' => $log->actor_role,
                'occurred_at' => ($log->occurred_at ?? $log->created_at)?->toIso8601String(),
                'metadata' => $log->metadata,
                'ip' => $log->ip,
            ])
            ->all();
    }

    /**
     * Mark the given events as synchronized.
     *
     * @param  array<int, string>  $eventUuids
     */
    public function markSynced(array $eventUuids): int
    {
        if ($eventUuids === []) {
            return 0;
        }

        return AuditLog::query()
            ->whereIn('event_uuid', $eventUuids)
            ->whereNull('synced_at')
            ->update(['synced_at' => now()]);
    }

    /**
     * The minimal account registry reported to the central server: a stable
     * identifier, the username, the person's name, the role, and whether the
     * account is active. It covers the Manager that owns this installation and
     * the Encoder accounts that Manager created, so the central server can show
     * a complete User Monitoring view and name the actor of every audit event.
     * Passwords, hashes, and contact details are never included.
     *
     * @return array<int, array<string, mixed>>
     */
    public function registry(): array
    {
        return User::query()
            ->whereIn('role', [User::ROLE_MANAGER, User::ROLE_ENCODER])
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [User::ROLE_MANAGER])
            ->orderBy('username')
            ->get()
            ->map(fn (User $user) => [
                'encoder_uuid' => (string) $user->uuid,
                'username' => (string) $user->username,
                'name' => (string) $user->name,
                'role' => (string) $user->role,
                'is_active' => (bool) $user->is_active,
            ])
            ->all();
    }

    /**
     * Push pending audit events and the encoder registry to the central server.
     *
     * @return array{pushed: int, accepted: int, registry: int, error: string|null}
     */
    public function push(SyncClient $client, int $limit = 100): array
    {
        $events = $this->payload($limit);
        $registry = $this->registry();

        if ($events === [] && $registry === []) {
            return ['pushed' => 0, 'accepted' => 0, 'registry' => 0, 'error' => null];
        }

        $response = $client->pushAuditEvents($events, $registry);

        if (! $response->successful()) {
            return [
                'pushed' => 0,
                'accepted' => 0,
                'registry' => count($registry),
                'error' => 'The central server returned HTTP '.$response->status().'.',
            ];
        }

        $accepted = (array) $response->json('data.accepted_event_uuids', []);

        $this->markSynced($accepted);

        return [
            'pushed' => count($events),
            'accepted' => count($accepted),
            'registry' => count($registry),
            'error' => null,
        ];
    }
}
