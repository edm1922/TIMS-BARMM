<?php

namespace App\Http\Middleware;

use App\Models\OfflineServer;
use App\Services\Sync\TokenService;
use App\Support\SyncIdentity;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSyncServer
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokenFromRequest($request);
        $server = app(TokenService::class)->findServerByToken($token);

        if (! $server instanceof OfflineServer) {
            $message = $request->routeIs('api.sync.v1.reauthorize')
                ? 'This installation could not authenticate because its server token is invalid or was revoked. Reauthorization cannot restore a revoked installation. Generate a new enrollment token on the central server and re-enroll this installation.'
                : 'A valid server token is required.';

            return $this->error('invalid_server_token', $message, 401);
        }

        if ($server->isRevoked()) {
            return $this->error('server_revoked', 'This offline server has been revoked.', 403);
        }

        if (! $server->isActive()) {
            return $this->error('server_inactive', 'This offline server is not active.', 403);
        }

        $protocolVersion = (int) $request->header(
            (string) config('sync.protocol_header', 'X-TIMS-Protocol-Version'),
            $server->protocol_version,
        );
        $schemaVersion = (int) $request->header(
            (string) config('sync.schema_header', 'X-TIMS-Schema-Version'),
            $server->schema_version,
        );

        if (! SyncIdentity::supports($protocolVersion, $schemaVersion)) {
            return $this->error(
                'unsupported_sync_version',
                'The sync protocol or schema version is not supported.',
                426,
                ['compatibility' => SyncIdentity::compatibility()],
            );
        }

        app(TokenService::class)->touchLastUsed($server);

        $request->attributes->set('sync_server', $server);
        $request->attributes->set('sync_protocol_version', $protocolVersion);
        $request->attributes->set('sync_schema_version', $schemaVersion);

        return $next($request);
    }

    private function tokenFromRequest(Request $request): ?string
    {
        $header = (string) config('sync.server_token_header', 'X-TIMS-Server-Token');
        $token = $request->header($header);

        if (is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        $authorization = $request->header('Authorization', '');

        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            return trim(substr($authorization, 7));
        }

        return null;
    }

    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $extra), $status);
    }
}
