<?php

namespace App\Http\Controllers\Api\Sync;

use App\Exceptions\Sync\InvalidEnrollmentTokenException;
use App\Exceptions\Sync\ServerAlreadyEnrolledException;
use App\Exceptions\Sync\UnsupportedSyncVersionException;
use App\Http\Controllers\Controller;
use App\Services\Sync\EnrollmentService;
use App\Services\Sync\ScopeService;
use App\Support\SyncIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function compatibility(): JsonResponse
    {
        return response()->json([
            'data' => array_merge(SyncIdentity::compatibility(), [
                'central_server_uuid' => SyncIdentity::centralUuid(),
                'instance_mode' => SyncIdentity::mode(),
            ]),
        ]);
    }

    public function enroll(Request $request, EnrollmentService $enrollmentService, ScopeService $scopes): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'server_uuid' => ['nullable', 'uuid'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'protocol_version' => ['required', 'integer', 'min:1'],
            'schema_version' => ['required', 'integer', 'min:1'],
            'scope_type' => ['nullable', 'in:region,province,city_municipality,barangay,psgc'],
            'scope_code' => ['nullable', 'string', 'regex:/^(BARMM|\d{4,10})$/'],
            'province' => ['nullable', 'string', 'max:150'],
            'city_municipality' => ['nullable', 'string', 'max:150'],
            'barangay' => ['nullable', 'string', 'max:150'],
        ]);

        // Area scoping is optional: an installation that does not name a scope
        // covers the entire BARMM region. Scope is still recorded centrally so
        // snapshots remain explicitly scoped rather than ambiguous.
        $data['scope_type'] = ($data['scope_type'] ?? null) ?: ScopeService::SCOPE_TYPE_REGION;
        $data['scope_code'] = trim((string) ($data['scope_code'] ?? ''));

        if ($data['scope_type'] === ScopeService::SCOPE_TYPE_REGION) {
            $data['scope_code'] = ScopeService::REGION_SENTINEL;
            $data['province'] = null;
            $data['city_municipality'] = null;
            $data['barangay'] = null;
        }

        if ($data['scope_code'] === '') {
            return response()->json([
                'error' => [
                    'code' => 'invalid_scope',
                    'message' => 'A scope code is required unless the installation covers the entire BARMM region.',
                ],
            ], 422);
        }

        if (! $scopes->scopeMatchesLocations($data['scope_type'], $data['scope_code'])) {
            $validCodes = $scopes->provinceSuggestions();

            return response()->json([
                'error' => [
                    'code' => 'invalid_scope',
                    'message' => 'The scope code does not match any BARMM location. Use a valid PSGC province code (4 digits), city/municipality code (7 digits), or barangay code (10 digits), or enroll with scope_type "region" to cover the entire BARMM. Valid province codes: '.implode(', ', array_keys($validCodes)).'.',
                ],
            ], 422);
        }

        try {
            $result = $enrollmentService->enroll($data, $request->user()?->id);
        } catch (UnsupportedSyncVersionException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'unsupported_sync_version',
                    'message' => $exception->getMessage(),
                ],
                'compatibility' => $exception->compatibility,
            ], 426);
        } catch (InvalidEnrollmentTokenException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_enrollment_token',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        } catch (ServerAlreadyEnrolledException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'server_already_enrolled',
                    'message' => $exception->getMessage(),
                ],
            ], 409);
        }

        $server = $result['server'];

        return response()->json([
            'data' => [
                'server_uuid' => $server->server_uuid,
                'server_token' => $result['server_token'],
                'central_server_uuid' => SyncIdentity::centralUuid(),
                'protocol_version' => $server->protocol_version,
                'schema_version' => $server->schema_version,
                'enrolled_at' => $server->enrolled_at?->toIso8601String(),
                'authorized_at' => $result['authorized_at'] ?? null,
                'authorization_expires_at' => $result['authorization_expires_at'] ?? null,
                'authorization_status' => $server->authorizationStatus(),
            ],
        ], 201);
    }
}
