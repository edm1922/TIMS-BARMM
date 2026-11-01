<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\SyncIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Record a sensitive operation. Never pass passwords, tokens, or bulk
     * personal data in $metadata — keep entries minimal and structured.
     *
     * Every record carries the identity of the actor and the installation that
     * produced it, so the trail stays attributable once it synchronizes to the
     * central server.
     */
    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = [],
        ?Authenticatable $actor = null,
        ?string $ip = null,
    ): AuditLog {
        $actor ??= auth()->user();
        $request = request();

        $actorUuid = $actor instanceof Model ? $actor->getAttribute('uuid') : null;
        $actorName = $actor instanceof Model ? $actor->getAttribute('name') : null;
        $actorUsername = $actor instanceof Model ? $actor->getAttribute('username') : null;
        $actorRole = $actor instanceof Model ? $actor->getAttribute('role') : null;

        return AuditLog::create([
            'event_uuid' => (string) Str::uuid(),
            'user_id' => $actor?->getAuthIdentifier(),
            'actor_uuid' => is_string($actorUuid) ? $actorUuid : null,
            'actor_name' => is_string($actorName) && $actorName !== '' ? $actorName : null,
            'actor_username' => is_string($actorUsername) ? $actorUsername : null,
            'actor_role' => is_string($actorRole) ? $actorRole : null,
            'origin_server_uuid' => SyncIdentity::originUuid(),
            'occurred_at' => now(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip' => $ip ?? ($request instanceof Request ? $request->ip() : null),
        ]);
    }
}
