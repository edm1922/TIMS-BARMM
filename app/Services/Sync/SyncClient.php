<?php

namespace App\Services\Sync;

use App\Models\SyncSetting;
use App\Support\SyncIdentity;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SyncClient
{
    /**
     * Overrides the central URL that is otherwise read from the local
     * sync settings. The central server itself needs this because it
     * publishes installation packages to its own public URL.
     */
    private ?string $centralUrlOverride = null;

    /**
     * Point this client at a specific central server.
     */
    public function setCentralUrl(?string $url): self
    {
        $this->centralUrlOverride = is_string($url) && trim($url) !== ''
            ? rtrim($url, '/')
            : null;

        return $this;
    }

    public function centralUrl(): ?string
    {
        if ($this->centralUrlOverride !== null) {
            return $this->centralUrlOverride;
        }

        $url = SyncSetting::get('central_url');

        return is_string($url) && trim($url) !== '' ? rtrim($url, '/') : null;
    }

    public function isEnrolled(): bool
    {
        return $this->centralUrl() !== null
            && SyncSetting::get('server_uuid') !== null
            && SyncSetting::getEncrypted('server_token') !== null;
    }

    public function compatibility(): Response
    {
        return $this->request()->get('/api/sync/v1/compatibility');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function enroll(array $data): Response
    {
        return $this->request(withToken: false)->post('/api/sync/v1/enroll', $data);
    }

    public function snapshot(): Response
    {
        return $this->request()->get('/api/sync/v1/snapshot');
    }

    public function snapshotBeneficiaries(string $snapshotUuid, ?int $cursor = null, ?int $limit = null): Response
    {
        return $this->request()->get("/api/sync/v1/snapshot/{$snapshotUuid}/beneficiaries", array_filter([
            'cursor' => $cursor,
            'limit' => $limit,
        ], fn ($value) => $value !== null));
    }

    public function snapshotReference(string $snapshotUuid): Response
    {
        return $this->request()->get("/api/sync/v1/snapshot/{$snapshotUuid}/reference");
    }

    public function changes(int $since = 0, ?int $limit = null): Response
    {
        return $this->request()->get('/api/sync/v1/changes', array_filter([
            'since' => $since,
            'limit' => $limit,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     */
    public function operations(array $operations): Response
    {
        return $this->request()->post('/api/sync/v1/operations', [
            'operations' => $operations,
        ]);
    }

    public function ack(int $downloadRevision, int $uploadRevision): Response
    {
        return $this->request()->post('/api/sync/v1/ack', [
            'download_revision' => $downloadRevision,
            'upload_revision' => $uploadRevision,
        ]);
    }

    /**
     * Review decisions the central server recorded for records it held. The
     * cursor is the last item id already consumed.
     */
    public function requestOutcomes(int $since = 0, ?int $limit = null): Response
    {
        return $this->request()->get('/api/sync/v1/request-outcomes', array_filter([
            'since' => $since,
            'limit' => $limit,
        ], fn ($value) => $value !== null));
    }

    public function conflicts(): Response
    {
        return $this->request()->get('/api/sync/v1/conflicts');
    }

    /**
     * Report local queue health and the batch roster to the central server so
     * the Offline Servers screen can show real numbers and per-batch deltas
     * instead of guessing.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array<int, array<string, mixed>>  $batches
     */
    public function heartbeat(array $metrics, array $batches = []): Response
    {
        return $this->request()->post('/api/sync/v1/heartbeat', [
            'queue' => $metrics,
            'batches' => $batches,
        ]);
    }

    /**
     * Send audit events and the encoder registry to the central server.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $encoders
     */
    public function pushAuditEvents(array $events, array $encoders = []): Response
    {
        return $this->request()->post('/api/sync/v1/audit-events', [
            'events' => $events,
            'encoders' => $encoders,
        ]);
    }

    /**
     * Redeem a reauthorization token issued by the central administrator.
     */
    public function reauthorize(string $token): Response
    {
        return $this->request()->post('/api/sync/v1/reauthorize', [
            'reauthorization_token' => $token,
        ]);
    }

    /**
     * Prepare for a new encoding session: performs full sync and obtains
     * a new time-limited authorization token from the central server.
     */
    public function prepare(string $token): Response
    {
        return $this->request()->post('/api/sync/v1/prepare', [
            'preparation_token' => $token,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveConflict(string $conflictUuid, array $payload): Response
    {
        return $this->request()->post("/api/sync/v1/conflicts/{$conflictUuid}/resolve", $payload);
    }

    /**
     * Check central server connectivity and authorization status.
     *
     * @return array{status: string, message: string, detail: array<string, mixed>|null}
     */
    public function checkConnection(): array
    {
        $url = $this->centralUrl();

        if ($url === null) {
            return [
                'status' => 'not_configured',
                'message' => 'Central server URL not configured.',
                'detail' => null,
            ];
        }

        // Try compatibility endpoint first (public, no auth required)
        try {
            $response = Http::baseUrl($url)
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->withHeaders([
                    'X-TIMS-Protocol-Version' => (string) SyncIdentity::protocolVersion(),
                    'X-TIMS-Schema-Version' => (string) SyncIdentity::schemaVersion(),
                ])
                ->get('/api/sync/v1/compatibility');
        } catch (Throwable $exception) {
            return [
                'status' => 'offline',
                'message' => 'Central server is unreachable.',
                'detail' => ['error' => $exception->getMessage()],
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 'offline',
                'message' => 'Central server returned HTTP '.$response->status().'.',
                'detail' => $response->json() ?? null,
            ];
        }

        // If enrolled, also check health endpoint to verify authorization
        if ($this->isEnrolled()) {
            try {
                $health = $this->request()->get('/api/sync/v1/health');

                if ($health->status() === 401 || $health->status() === 403) {
                    return [
                        'status' => 'auth_error',
                        'message' => 'Server token rejected (authorization expired or revoked).',
                        'detail' => $health->json() ?? null,
                    ];
                }

                if (! $health->successful()) {
                    return [
                        'status' => 'sync_error',
                        'message' => 'Health check returned HTTP '.$health->status().'.',
                        'detail' => $health->json() ?? null,
                    ];
                }

                return [
                    'status' => 'connected',
                    'message' => 'Central server reachable and authorized.',
                    'detail' => $health->json('data') ?? null,
                ];
            } catch (Throwable $exception) {
                return [
                    'status' => 'sync_error',
                    'message' => 'Health check failed: '.$exception->getMessage(),
                    'detail' => null,
                ];
            }
        }

        // Not enrolled but compatibility works
        return [
            'status' => 'connected',
            'message' => 'Central server reachable (not enrolled).',
            'detail' => $response->json('data') ?? null,
        ];
    }

    /**
     * Ask the central server whether a newer application package was
     * published for this installation.
     *
     * @return array<string, mixed>|null
     */
    public function checkAppUpdate(): ?array
    {
        if (! $this->isEnrolled()) {
            return null;
        }

        try {
            $response = $this->request()->get('/api/sync/v1/app-update/check');

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json('data');

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Download the published application package so the local updater can
     * verify its checksum and apply it.
     */
    public function downloadAppUpdate(string $filename): ?Response
    {
        if (! $this->isEnrolled()) {
            return null;
        }

        try {
            return $this->request()
                ->timeout(600)
                ->get('/api/sync/v1/app-update/download/'.rawurlencode($filename));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tell the central server that a published package was applied so it is
     * not offered again.
     */
    public function ackAppUpdate(string $sha256): ?Response
    {
        if (! $this->isEnrolled()) {
            return null;
        }

        try {
            return $this->request()->post('/api/sync/v1/app-update/ack', [
                'sha256' => $sha256,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function request(bool $withToken = true): PendingRequest
    {
        return $this->configuredRequest('json', $withToken);
    }

    /**
     * A request that uploads multipart/form-data instead of JSON. Used when a
     * built package is pushed to the central server.
     */
    public function multipartRequest(bool $withToken = true): PendingRequest
    {
        return $this->configuredRequest('multipart', $withToken);
    }

    private function configuredRequest(string $format, bool $withToken): PendingRequest
    {
        $request = Http::baseUrl((string) $this->centralUrl())
            ->acceptJson()
            ->timeout(30)
            ->withHeaders([
                'X-TIMS-Protocol-Version' => (string) SyncIdentity::protocolVersion(),
                'X-TIMS-Schema-Version' => (string) SyncIdentity::schemaVersion(),
            ]);

        $format === 'multipart' ? $request->asMultipart() : $request->asJson();

        if ($withToken) {
            $token = SyncSetting::getEncrypted('server_token');

            if (is_string($token) && $token !== '') {
                $request->withHeader(
                    (string) config('sync.server_token_header', 'X-TIMS-Server-Token'),
                    $token,
                );
            }
        }

        return $request;
    }
}
