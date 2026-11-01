<?php

namespace App\Http\Middleware;

use App\Models\OfflineServer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the offline installation authorization period on the central
 * server. The decision is always taken from the central record: a local
 * flag on the device is never proof of authorization.
 *
 * Usage:
 *   ->middleware('sync.authorized:write')  blocks uploads and resolutions
 *   ->middleware('sync.authorized:read')   blocks snapshot and delta pulls
 */
class EnsureServerAuthorization
{
    public function handle(Request $request, Closure $next, string $scope = 'write'): Response
    {
        /** @var OfflineServer|null $server */
        $server = $request->attributes->get('sync_server');

        if (! $server instanceof OfflineServer) {
            return $this->error('invalid_server_token', 'A valid server token is required.', 401);
        }

        if ($server->isAuthorized()) {
            return $next($request);
        }

        if ($scope === 'none') {
            return $next($request);
        }

        $allowed = $scope === 'read'
            ? (bool) config('sync.allow_download_after_expiry', false)
            : (bool) config('sync.allow_upload_after_expiry', false);

        if ($allowed) {
            return $next($request);
        }

        return $this->error(
            'authorization_expired',
            'The authorization period for this installation has expired. Reauthorization is required.',
            403,
            [
                'authorization_status' => $server->authorizationStatus(),
                'authorization_expires_at' => $server->authorization_expires_at?->toIso8601String(),
            ],
        );
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
