<?php

namespace App\Services\Sync;

use App\Exceptions\Sync\InvalidEnrollmentTokenException;
use App\Models\OfflineServer;
use App\Models\OfflineServerEnrollmentToken;
use App\Models\SyncEvent;
use App\Support\SyncIdentity;
use Illuminate\Support\Facades\DB;

class ReauthorizationService
{
    /**
     * Redeem a reauthorization token for an already enrolled server.
     *
     * The token must be bound to the authenticated server, unused, and not
     * expired. Reauthorization only extends the authorization window: audit
     * history, sync history, and pending operations are untouched.
     *
     * @return array{server: OfflineServer, authorized_at: string, authorization_expires_at: string}
     */
    public function reauthorize(OfflineServer $server, string $plainTextToken): array
    {
        $tokenHash = SyncIdentity::hashToken(trim($plainTextToken));

        return DB::transaction(function () use ($server, $tokenHash): array {
            $token = OfflineServerEnrollmentToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $token instanceof OfflineServerEnrollmentToken) {
                throw new InvalidEnrollmentTokenException('Invalid reauthorization token.');
            }

            if ($token->token_type !== OfflineServerEnrollmentToken::TYPE_REAUTHORIZATION) {
                throw new InvalidEnrollmentTokenException('This token cannot be used for reauthorization.');
            }

            if ($token->isUsed()) {
                throw new InvalidEnrollmentTokenException('Reauthorization token has already been used.');
            }

            if ($token->isExpired()) {
                throw new InvalidEnrollmentTokenException('Reauthorization token has expired.');
            }

            if ((int) $token->target_offline_server_id !== (int) $server->id) {
                throw new InvalidEnrollmentTokenException('This token is not valid for this installation.');
            }

            $expiresAt = $token->authorization_expires_at;

            if ($expiresAt === null) {
                throw new InvalidEnrollmentTokenException('The reauthorization token does not carry an authorization period.');
            }

            $server->forceFill([
                'authorized_at' => now(),
                'authorization_expires_at' => $expiresAt,
            ])->save();

            $token->forceFill([
                'used_at' => now(),
                'used_by_server_id' => $server->id,
            ])->save();

            SyncEvent::create([
                'offline_server_id' => $server->id,
                'type' => 'server.reauthorized',
                'severity' => 'info',
                'message' => 'Offline installation reauthorized.',
                'context' => [
                    'server_uuid' => $server->server_uuid,
                    'authorized_at' => $server->authorized_at?->toIso8601String(),
                    'authorization_expires_at' => $server->authorization_expires_at?->toIso8601String(),
                ],
            ]);

            return [
                'server' => $server,
                'authorized_at' => (string) $server->authorized_at?->toIso8601String(),
                'authorization_expires_at' => (string) $server->authorization_expires_at?->toIso8601String(),
            ];
        });
    }
}
