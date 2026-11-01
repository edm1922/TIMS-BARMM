<?php

namespace App\Services\Sync;

use App\Exceptions\Sync\InvalidEnrollmentTokenException;
use App\Exceptions\Sync\ServerAlreadyEnrolledException;
use App\Exceptions\Sync\UnsupportedSyncVersionException;
use App\Models\OfflineServer;
use App\Models\OfflineServerEnrollmentToken;
use App\Models\OfflineServerScope;
use App\Models\SyncEvent;
use App\Support\SyncIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{server: OfflineServer, server_token: string}
     */
    public function enroll(array $data, ?int $createdBy = null): array
    {
        $protocolVersion = (int) ($data['protocol_version'] ?? 0);
        $schemaVersion = (int) ($data['schema_version'] ?? 0);

        if (! SyncIdentity::supports($protocolVersion, $schemaVersion)) {
            throw new UnsupportedSyncVersionException(
                'The offline server protocol or schema version is not supported.',
                SyncIdentity::compatibility(),
            );
        }

        $serverUuid = trim((string) ($data['server_uuid'] ?? ''));

        if ($serverUuid === '') {
            $serverUuid = SyncIdentity::newUuid();
        }

        $tokenHash = SyncIdentity::hashToken((string) $data['enrollment_token']);

        // No scope means the whole BARMM region. The scope is always stored
        // centrally so snapshot distribution stays explicit.
        $scopeType = (string) ($data['scope_type'] ?? '') ?: ScopeService::SCOPE_TYPE_REGION;
        $scopeCode = trim((string) ($data['scope_code'] ?? ''));

        if ($scopeType === ScopeService::SCOPE_TYPE_REGION) {
            $scopeCode = ScopeService::REGION_SENTINEL;
        }

        if ($scopeCode === '') {
            throw new InvalidEnrollmentTokenException('A scope code is required unless the installation covers the entire BARMM region.');
        }

        return DB::transaction(function () use ($data, $createdBy, $serverUuid, $protocolVersion, $schemaVersion, $tokenHash, $scopeType, $scopeCode): array {
            $existing = OfflineServer::query()
                ->where('server_uuid', $serverUuid)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof OfflineServer && ! $existing->isRevoked()) {
                throw new ServerAlreadyEnrolledException('This server UUID is already enrolled.');
            }

            $enrollment = OfflineServerEnrollmentToken::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $enrollment instanceof OfflineServerEnrollmentToken) {
                throw new InvalidEnrollmentTokenException('Invalid enrollment token.');
            }

            if ($enrollment->isUsed()) {
                throw new InvalidEnrollmentTokenException('Enrollment token has already been used.');
            }

            if ($enrollment->isExpired()) {
                throw new InvalidEnrollmentTokenException('Enrollment token has expired.');
            }

            if ($enrollment->token_type !== OfflineServerEnrollmentToken::TYPE_ENROLLMENT) {
                throw new InvalidEnrollmentTokenException('This token cannot be used to enroll a new server.');
            }

            $authorizationExpiresAt = $enrollment->authorization_expires_at;

            if (! $authorizationExpiresAt instanceof Carbon) {
                throw new InvalidEnrollmentTokenException('The enrollment token does not carry an authorization period.');
            }

            $attributes = [
                'name' => trim((string) $data['name']),
                'description' => $enrollment->device_label,
                'app_version' => $data['app_version'] ?? null,
                'protocol_version' => $protocolVersion,
                'schema_version' => $schemaVersion,
                'status' => OfflineServer::STATUS_ACTIVE,
                'enrolled_at' => now(),
                'authorized_at' => now(),
                'authorization_expires_at' => $authorizationExpiresAt,
                'created_by' => $createdBy ?? $enrollment->created_by,
            ];

            if ($existing instanceof OfflineServer) {
                $existing->forceFill($attributes + ['revoked_at' => null])->save();
                $server = $existing;

                if (! $server->scopes()->exists()) {
                    $this->createScope($server, $data, $scopeType, $scopeCode);
                }
            } else {
                $server = OfflineServer::create($attributes + ['server_uuid' => $serverUuid]);
                $this->createScope($server, $data, $scopeType, $scopeCode);
            }

            $serverToken = app(TokenService::class)->issueServerToken($server);

            $enrollment->forceFill([
                'used_at' => now(),
                'used_by_server_id' => $server->id,
            ])->save();

            SyncEvent::create([
                'offline_server_id' => $server->id,
                'type' => 'server.enrolled',
                'severity' => 'info',
                'message' => $existing instanceof OfflineServer
                    ? 'Offline server re-enrolled.'
                    : 'Offline server enrolled.',
                'context' => [
                    'server_uuid' => $server->server_uuid,
                    'protocol_version' => $protocolVersion,
                    'schema_version' => $schemaVersion,
                    'scope_type' => $scopeType,
                    'scope_code' => $scopeCode,
                    'reenrolled' => $existing instanceof OfflineServer,
                    'authorized_at' => $server->authorized_at?->toIso8601String(),
                    'authorization_expires_at' => $server->authorization_expires_at?->toIso8601String(),
                ],
            ]);

            return [
                'server' => $server,
                'server_token' => $serverToken,
                'authorized_at' => $server->authorized_at?->toIso8601String(),
                'authorization_expires_at' => $server->authorization_expires_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Register the server's scope centrally so snapshots include the correct records.
     *
     * @param  array<string, mixed>  $data
     */
    private function createScope(
        OfflineServer $server,
        array $data,
        string $scopeType,
        string $scopeCode,
    ): void {
        OfflineServerScope::create([
            'offline_server_id' => $server->id,
            'scope_type' => $scopeType,
            'location_code' => $scopeCode,
            'province' => $data['province'] ?? null,
            'city_municipality' => $data['city_municipality'] ?? null,
            'barangay' => $data['barangay'] ?? null,
        ]);
    }
}
