<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OfflineServer;
use App\Models\OfflineServerScope;
use App\Models\SyncConflict;
use App\Services\AuditLogger;
use App\Services\Sync\ScopeService;
use App\Services\Sync\SyncReconciliationService;
use App\Services\Sync\TokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SyncServerController extends Controller
{
    public const SCOPE_TYPES = [
        'province' => 'Province',
        'city_municipality' => 'City / Municipality',
        'barangay' => 'Barangay',
        'psgc' => 'Auto-detect from PSGC',
        'region' => 'Entire BARMM region',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
        ];

        $query = OfflineServer::query()
            ->with('scopes')
            ->latest('id');

        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(function ($serverQuery) use ($term): void {
                $serverQuery->where('name', 'like', $term)
                    ->orWhere('server_uuid', 'like', $term);
            });
        }

        if (in_array($filters['status'], [
            OfflineServer::STATUS_ACTIVE,
            OfflineServer::STATUS_SUSPENDED,
            OfflineServer::STATUS_REVOKED,
        ], true)) {
            $query->where('status', $filters['status']);
        }

        $servers = $query->paginate(20)->withQueryString();

        // Open conflicts per server for the monitoring table; one grouped
        // query keeps the page at a fixed query count.
        $openConflicts = SyncConflict::query()
            ->where('status', 'open')
            ->whereNotNull('offline_server_id')
            ->groupBy('offline_server_id')
            ->selectRaw('offline_server_id, COUNT(*) AS open_conflicts')
            ->pluck('open_conflicts', 'offline_server_id');

        return view('sync-servers.index', [
            'servers' => $servers,
            'filters' => $filters,
            'openConflicts' => $openConflicts,
            'counts' => [
                'total' => OfflineServer::count(),
                'active' => OfflineServer::where('status', OfflineServer::STATUS_ACTIVE)->count(),
                'suspended' => OfflineServer::where('status', OfflineServer::STATUS_SUSPENDED)->count(),
                'revoked' => OfflineServer::where('status', OfflineServer::STATUS_REVOKED)->count(),
                'conflicts' => SyncConflict::where('status', 'open')->count(),
            ],
        ]);
    }

    public function show(OfflineServer $syncServer, ScopeService $scopes): View
    {
        $syncServer->load([
            'scopes' => fn ($query) => $query->orderBy('location_code'),
            'events' => fn ($query) => $query->latest('id')->limit(20),
            'snapshots' => fn ($query) => $query->latest('id')->limit(10),
            'conflicts' => fn ($query) => $query->where('status', 'open')->latest('id')->limit(20),
            'checkpoints' => fn ($query) => $query->latest('id')->limit(10),
            'enrollmentTokens' => fn ($query) => $query->latest('id')->limit(10),
            'encoders' => fn ($query) => $query->orderBy('username'),
        ]);

        return view('sync-servers.show', [
            'server' => $syncServer,
            'scopeTypes' => self::SCOPE_TYPES,
            'scopeSuggestions' => $scopes->provinceSuggestions(),
            'scopeExamples' => $scopes->provinceExampleText(),
            'reconciliation' => app(SyncReconciliationService::class)->forServer($syncServer),
            'reportedAuditCount' => AuditLog::query()
                ->where('origin_server_uuid', $syncServer->server_uuid)
                ->count(),
        ]);
    }

    public function createToken(Request $request, TokenService $tokens): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:120'],
            'device_label' => ['nullable', 'string', 'max:120'],
            'authorization_days' => [
                'required',
                'integer',
                'min:'.(int) config('sync.authorization_min_days', 1),
                'max:'.(int) config('sync.authorization_max_days', 3650),
            ],
        ]);

        $issued = $tokens->generateEnrollmentToken(
            $request->user()->id,
            $validated['device_label'] ?? null,
            (int) $validated['authorization_days'],
        );

        AuditLogger::log('sync_server.enrollment_token.created', 'enrollment_token', $issued['id'], [
            'note' => $validated['note'] ?? null,
            'device_label' => $validated['device_label'] ?? null,
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'authorization_expires_at' => $issued['authorization_expires_at']->toIso8601String(),
        ]);

        return redirect()
            ->route('sync-servers.index')
            ->with('success', 'Enrollment token generated. Copy it now; it will not be shown again.')
            ->with('enrollment_token', $issued['token'])
            ->with('enrollment_token_expires_at', $issued['expires_at']->toIso8601String())
            ->with('enrollment_authorization_expires_at', $issued['authorization_expires_at']->toIso8601String());
    }

    /**
     * Issue a one-time reauthorization token for an existing installation.
     */
    public function createReauthorizationToken(Request $request, OfflineServer $syncServer, TokenService $tokens): RedirectResponse
    {
        if ($syncServer->isRevoked()) {
            return back()->with('error', 'Revoked servers cannot be reauthorized. Re-enroll this installation with a new enrollment token instead.');
        }

        $validated = $request->validate([
            'authorization_days' => [
                'required',
                'integer',
                'min:'.(int) config('sync.authorization_min_days', 1),
                'max:'.(int) config('sync.authorization_max_days', 3650),
            ],
        ]);

        $issued = $tokens->generateReauthorizationToken(
            $syncServer,
            $request->user()->id,
            (int) $validated['authorization_days'],
        );

        AuditLogger::log('sync_server.reauthorization_token.created', 'offline_server', $syncServer->id, [
            'server_uuid' => $syncServer->server_uuid,
            'token_id' => $issued['id'],
            'authorization_expires_at' => $issued['authorization_expires_at']->toIso8601String(),
        ]);

        return redirect()
            ->route('sync-servers.show', $syncServer)
            ->with('success', 'Reauthorization token generated. Copy it now; it will not be shown again.')
            ->with('reauthorization_token', $issued['token'])
            ->with('reauthorization_token_expires_at', $issued['expires_at']->toIso8601String())
            ->with('reauthorization_authorization_expires_at', $issued['authorization_expires_at']->toIso8601String());
    }

    /**
     * Issue a one-time preparation token for an existing installation.
     *
     * This token is used by the offline device to prepare for a new encoding session.
     * It performs full sync and obtains a new time-limited authorization.
     */
    public function createPreparationToken(Request $request, OfflineServer $syncServer, TokenService $tokens): RedirectResponse
    {
        if ($syncServer->isRevoked()) {
            return back()->with('error', 'Revoked servers cannot be prepared. Re-enroll this installation with a new enrollment token instead.');
        }

        $validated = $request->validate([
            'authorization_days' => [
                'required',
                'integer',
                'min:'.(int) config('sync.authorization_min_days', 1),
                'max:'.(int) config('sync.authorization_max_days', 3650),
            ],
        ]);

        $issued = $tokens->generateReauthorizationToken(
            $syncServer,
            $request->user()->id,
            (int) $validated['authorization_days'],
        );

        AuditLogger::log('sync_server.preparation_token.created', 'offline_server', $syncServer->id, [
            'server_uuid' => $syncServer->server_uuid,
            'token_id' => $issued['id'],
            'authorization_expires_at' => $issued['authorization_expires_at']->toIso8601String(),
        ]);

        return redirect()
            ->route('sync-servers.show', $syncServer)
            ->with('success', 'Preparation token generated. Copy it now; it will not be shown again.')
            ->with('preparation_token', $issued['token'])
            ->with('preparation_token_expires_at', $issued['expires_at']->toIso8601String())
            ->with('preparation_authorization_expires_at', $issued['authorization_expires_at']->toIso8601String());
    }

    public function rotateToken(Request $request, OfflineServer $syncServer, TokenService $tokens): RedirectResponse
    {
        if ($syncServer->isRevoked()) {
            return back()->with('error', 'Revoked servers cannot receive a new token. Re-enroll this installation with a new enrollment token instead.');
        }

        $plainText = $tokens->issueServerToken($syncServer);

        AuditLogger::log('sync_server.token.rotated', 'offline_server', $syncServer->id, [
            'server_uuid' => $syncServer->server_uuid,
        ]);

        return redirect()
            ->route('sync-servers.show', $syncServer)
            ->with('success', 'Server token rotated. The previous token is now invalid.')
            ->with('server_token', $plainText);
    }

    public function revoke(Request $request, OfflineServer $syncServer, TokenService $tokens): RedirectResponse
    {
        if ($syncServer->isRevoked()) {
            return back()->with('error', 'This server is already revoked.');
        }

        $tokens->revoke($syncServer, $request->user()->id);

        AuditLogger::log('sync_server.revoked', 'offline_server', $syncServer->id, [
            'server_uuid' => $syncServer->server_uuid,
        ]);

        return redirect()
            ->route('sync-servers.show', $syncServer)
            ->with('success', 'Server revoked and its API token cleared.');
    }

    public function updateStatus(Request $request, OfflineServer $syncServer): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                OfflineServer::STATUS_ACTIVE,
                OfflineServer::STATUS_SUSPENDED,
            ])],
        ]);

        if ($syncServer->isRevoked()) {
            return back()->with('error', 'Revoked servers cannot be reactivated. Re-enroll this installation with a new enrollment token instead.');
        }

        $syncServer->update(['status' => $validated['status']]);

        AuditLogger::log('sync_server.status.updated', 'offline_server', $syncServer->id, [
            'server_uuid' => $syncServer->server_uuid,
            'status' => $syncServer->status,
        ]);

        return back()->with('success', 'Server status updated to '.$syncServer->status.'.');
    }

    public function storeScope(Request $request, OfflineServer $syncServer, ScopeService $scopes): RedirectResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(array_keys(self::SCOPE_TYPES))],
            'location_code' => ['required', 'string', 'regex:/^(BARMM|\d{4,10})$/'],
            'province' => ['nullable', 'string', 'max:150'],
            'city_municipality' => ['nullable', 'string', 'max:150'],
            'barangay' => ['nullable', 'string', 'max:150'],
        ]);

        $isRegion = $validated['scope_type'] === ScopeService::SCOPE_TYPE_REGION;

        if ($isRegion) {
            $validated['location_code'] = ScopeService::REGION_SENTINEL;
            $validated['province'] = null;
            $validated['city_municipality'] = null;
            $validated['barangay'] = null;
        }

        if (! $scopes->scopeMatchesLocations($validated['scope_type'], $validated['location_code'])) {
            $validCodes = implode(', ', array_keys($scopes->provinceSuggestions()));

            return back()->withInput()->withErrors([
                'location_code' => 'That scope code does not match any BARMM location. Use a valid PSGC province code (4 digits), city/municipality code (7 digits), or barangay code (10 digits). Valid province codes: '.$validCodes.'.',
            ]);
        }

        $scope = $syncServer->scopes()->firstOrCreate(
            [
                'scope_type' => $validated['scope_type'],
                'location_code' => $validated['location_code'],
            ],
            [
                'province' => $validated['province'] ?? null,
                'city_municipality' => $validated['city_municipality'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
            ],
        );

        if (! $scope->wasRecentlyCreated) {
            return back()->with('error', 'That scope is already assigned to this server.');
        }

        AuditLogger::log('sync_server.scope.created', 'offline_server', $syncServer->id, [
            'scope_id' => $scope->id,
            'scope_type' => $scope->scope_type,
            'location_code' => $scope->location_code,
        ]);

        return back()->with('success', 'Scope added. The server will see it on the next snapshot or delta pull.');
    }

    public function destroyScope(OfflineServer $syncServer, OfflineServerScope $scope): RedirectResponse
    {
        abort_unless($scope->offline_server_id === $syncServer->id, 404);

        $scope->delete();

        AuditLogger::log('sync_server.scope.deleted', 'offline_server', $syncServer->id, [
            'scope_type' => $scope->scope_type,
            'location_code' => $scope->location_code,
        ]);

        return back()->with('success', 'Scope removed.');
    }
}
