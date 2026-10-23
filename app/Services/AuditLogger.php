<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * Record a sensitive operation. Never pass passwords, tokens, or bulk
     * personal data in $metadata — keep entries minimal and structured.
     */
    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $metadata = [],
        ?Authenticatable $actor = null,
    ): AuditLog {
        $actor ??= auth()->user();
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->getAuthIdentifier(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip' => $request instanceof Request ? $request->ip() : null,
        ]);
    }
}
