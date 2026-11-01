<?php

namespace App\Http\Controllers\Api\Sync;

use App\Exceptions\Sync\InvalidEnrollmentTokenException;
use App\Http\Controllers\Controller;
use App\Models\OfflineServer;
use App\Services\Sync\ReauthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReauthorizationController extends Controller
{
    /**
     * Redeem a reauthorization token issued by the central administrator.
     */
    public function store(Request $request, ReauthorizationService $reauthorization): JsonResponse
    {
        $data = $request->validate([
            'reauthorization_token' => ['required', 'string', 'max:255'],
        ]);

        /** @var OfflineServer $server */
        $server = $request->attributes->get('sync_server');

        try {
            $result = $reauthorization->reauthorize($server, (string) $data['reauthorization_token']);
        } catch (InvalidEnrollmentTokenException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_reauthorization_token',
                    'message' => $exception->getMessage(),
                ],
            ], 401);
        }

        return response()->json([
            'data' => [
                'server_uuid' => $server->server_uuid,
                'authorized_at' => $result['authorized_at'],
                'authorization_expires_at' => $result['authorization_expires_at'],
                'authorization_status' => $server->fresh()?->authorizationStatus(),
            ],
        ]);
    }
}
