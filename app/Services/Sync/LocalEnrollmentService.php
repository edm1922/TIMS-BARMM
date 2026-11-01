<?php

namespace App\Services\Sync;

use App\Models\SyncSetting;
use App\Support\SyncIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LocalEnrollmentService
{
    /**
     * @return array<string, mixed>
     */
    public function enroll(
        string $centralUrl,
        string $enrollmentToken,
        string $serverName,
        string $scopeType,
        string $scopeCode,
        ?string $serverUuid = null,
    ): array {
        $centralUrl = rtrim(trim($centralUrl), '/');
        $serverUuid ??= (string) (
            SyncSetting::get('server_uuid')
            ?: SyncSetting::get('local_server_uuid')
            ?: config('sync.local_server_uuid')
            ?: SyncIdentity::newUuid()
        );

        $client = Http::baseUrl($centralUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->withHeaders([
                'X-TIMS-Protocol-Version' => (string) SyncIdentity::protocolVersion(),
                'X-TIMS-Schema-Version' => (string) SyncIdentity::schemaVersion(),
            ]);

        try {
            $compatibility = $client->get('/api/sync/v1/compatibility');
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Could not reach the Central Server at '.$centralUrl.'. Check that the address is reachable from this device and that Apache is running there.',
                0,
                $exception,
            );
        }

        if (! $compatibility->successful()) {
            throw new RuntimeException(sprintf(
                'The address %s answered the compatibility check with HTTP %d, so it is not the TIMS-BARMM Central Server (expected a JSON response from %s/api/sync/v1/compatibility). Use the central installation URL that ends in /public.',
                $centralUrl,
                $compatibility->status(),
                $centralUrl,
            ));
        }

        $compatibilityData = (array) $compatibility->json('data', []);

        if (! isset($compatibilityData['protocol_version'], $compatibilityData['schema_version'])) {
            throw new RuntimeException(
                'The address '.$centralUrl.' responded, but not with a TIMS-BARMM compatibility document. Use the central installation URL that ends in /public.',
            );
        }

        if (! SyncIdentity::supports(
            (int) ($compatibilityData['protocol_version'] ?? 0),
            (int) ($compatibilityData['schema_version'] ?? 0),
        )) {
            throw new RuntimeException('The central server protocol or schema version is not compatible.');
        }

        $response = $client->post('/api/sync/v1/enroll', [
            'enrollment_token' => $enrollmentToken,
            'name' => $serverName,
            'server_uuid' => $serverUuid,
            'app_version' => (string) config('app.version', '1.0.0'),
            'protocol_version' => SyncIdentity::protocolVersion(),
            'schema_version' => SyncIdentity::schemaVersion(),
            'scope_type' => $scopeType,
            'scope_code' => $scopeCode,
        ]);

        if ($response->status() === 426) {
            throw new RuntimeException('The central server rejected this client protocol or schema version.');
        }

        if ($response->status() === 409) {
            throw new RuntimeException('This server UUID is already enrolled on the central server.');
        }

        if (! $response->successful()) {
            throw new RuntimeException((string) $response->json('error.message', 'Enrollment was rejected by the central server.'));
        }

        $data = (array) $response->json('data', []);
        $serverToken = $data['server_token'] ?? null;
        $serverUuid = (string) ($data['server_uuid'] ?? $serverUuid);

        if (! is_string($serverToken) || $serverToken === '') {
            throw new RuntimeException('The central server did not return a server token.');
        }

        DB::transaction(function () use ($centralUrl, $serverUuid, $serverName, $data, $serverToken, $scopeType, $scopeCode): void {
            SyncSetting::set('central_url', $centralUrl);
            SyncSetting::set('central_server_uuid', (string) ($data['central_server_uuid'] ?? ''));
            SyncSetting::set('server_uuid', $serverUuid);
            SyncSetting::set('local_server_uuid', $serverUuid);
            SyncSetting::set('server_name', $serverName);
            SyncSetting::set('authorized_at', (string) ($data['authorized_at'] ?? ''));
            SyncSetting::set('authorization_expires_at', (string) ($data['authorization_expires_at'] ?? ''));
            SyncSetting::set('authorization_status', (string) ($data['authorization_status'] ?? ''));

            // Enrollment tokens are one-time; the returned token is stored
            // encrypted because it grants full access to this server scope.
            SyncSetting::setEncrypted('server_token', $serverToken);
            SyncSetting::set('enrolled_at', (string) ($data['enrolled_at'] ?? now()->toIso8601String()));
            SyncSetting::set('scope_type', $scopeType);
            SyncSetting::set('scope_code', $scopeCode);
        });

        SyncSetting::flushCache();

        return [
            'server_uuid' => $serverUuid,
            'central_server_uuid' => $data['central_server_uuid'] ?? null,
            'scope_type' => $scopeType,
            'scope_code' => $scopeCode,
            'enrolled_at' => $data['enrolled_at'] ?? null,
            'authorized_at' => $data['authorized_at'] ?? null,
            'authorization_expires_at' => $data['authorization_expires_at'] ?? null,
        ];
    }
}
