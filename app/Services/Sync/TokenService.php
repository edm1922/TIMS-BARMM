<?php

namespace App\Services\Sync;

use App\Models\OfflineServer;
use App\Models\OfflineServerEnrollmentToken;
use App\Models\SyncEvent;
use App\Support\SyncIdentity;
use Illuminate\Support\Str;

class TokenService
{
    /**
     * Issue a one-time enrollment token for a new offline installation.
     *
     * The authorization period is set explicitly by the administrator. The
     * token itself expires quickly; the granted authorization period is
     * carried on the token and applied to the server at enrollment time.
     *
     * @return array{token: string, expires_at: \Illuminate\Support\Carbon, authorization_expires_at: \Illuminate\Support\Carbon, id: int}
     */
    public function generateEnrollmentToken(
        ?int $createdBy = null,
        ?string $deviceLabel = null,
        int $authorizationDays = 90,
        ?int $targetOfflineServerId = null,
    ): array {
        $plainText = $this->createToken();
        $authorizationExpiresAt = now()->addDays($authorizationDays);

        $token = OfflineServerEnrollmentToken::create([
            'token_type' => OfflineServerEnrollmentToken::TYPE_ENROLLMENT,
            'device_label' => $deviceLabel,
            'token_hash' => SyncIdentity::hashToken($plainText),
            'created_by' => $createdBy,
            'expires_at' => now()->addMinutes((int) config('sync.enrollment_token_ttl_minutes', 1440)),
            'authorization_expires_at' => $authorizationExpiresAt,
            'target_offline_server_id' => $targetOfflineServerId,
        ]);

        return [
            'token' => $plainText,
            'expires_at' => $token->expires_at,
            'authorization_expires_at' => $authorizationExpiresAt,
            'id' => $token->id,
        ];
    }

    /**
     * Issue a one-time reauthorization token bound to an existing server.
     */
    public function generateReauthorizationToken(
        OfflineServer $server,
        ?int $createdBy = null,
        int $authorizationDays = 90,
    ): array {
        $plainText = $this->createToken();
        $authorizationExpiresAt = now()->addDays($authorizationDays);

        $token = OfflineServerEnrollmentToken::create([
            'token_type' => OfflineServerEnrollmentToken::TYPE_REAUTHORIZATION,
            'device_label' => $server->name,
            'token_hash' => SyncIdentity::hashToken($plainText),
            'created_by' => $createdBy,
            'expires_at' => now()->addMinutes((int) config('sync.enrollment_token_ttl_minutes', 1440)),
            'authorization_expires_at' => $authorizationExpiresAt,
            'target_offline_server_id' => $server->id,
        ]);

        return [
            'token' => $plainText,
            'expires_at' => $token->expires_at,
            'authorization_expires_at' => $authorizationExpiresAt,
            'id' => $token->id,
        ];
    }

    public function issueServerToken(OfflineServer $server): string
    {
        $plainText = $this->createToken();

        $server->forceFill([
            'api_token_hash' => SyncIdentity::hashToken($plainText),
            'api_token_last_used_at' => null,
        ])->save();

        return $plainText;
    }

    public function findServerByToken(?string $token): ?OfflineServer
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $hash = SyncIdentity::hashToken($token);

        return OfflineServer::query()
            ->where('api_token_hash', $hash)
            ->first();
    }

    public function revoke(OfflineServer $server, ?int $actorId = null): void
    {
        $server->forceFill([
            'status' => OfflineServer::STATUS_REVOKED,
            'api_token_hash' => null,
            'revoked_at' => now(),
        ])->save();

        SyncEvent::create([
            'offline_server_id' => $server->id,
            'type' => 'server.revoked',
            'severity' => 'warning',
            'message' => 'Offline server revoked.',
            'context' => ['revoked_by' => $actorId],
        ]);
    }

    public function touchLastUsed(OfflineServer $server): void
    {
        $throttle = (int) config('sync.token_last_used_throttle_seconds', 60);

        if ($server->api_token_last_used_at && $server->api_token_last_used_at->diffInSeconds(now()) < $throttle) {
            return;
        }

        $server->forceFill(['api_token_last_used_at' => now()])->save();
    }

    private function createToken(): string
    {
        $prefix = (string) config('sync.token_prefix', 'tims_sync_');

        return $prefix.Str::random(48);
    }
}
