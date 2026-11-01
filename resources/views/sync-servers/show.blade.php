@extends('layouts.app')

@section('title', $server->name)

@section('content')
@php
    $statusPill = match ($server->status) {
        'active' => 'sync-pill--ok',
        'suspended' => 'sync-pill--warning',
        'revoked' => 'sync-pill--danger',
        default => 'sync-pill--neutral',
    };
    $authorizationPill = match ($server->authorizationStatus()) {
        'active' => 'sync-pill--ok',
        'expired' => 'sync-pill--danger',
        'suspended' => 'sync-pill--warning',
        'revoked' => 'sync-pill--danger',
        default => 'sync-pill--neutral',
    };
    $reported = data_get($server->metadata, 'last_reported');
    $authorizationExpiringSoon = $server->authorization_expires_at !== null
        && $server->authorization_expires_at->isFuture()
        && $server->authorization_expires_at->lessThanOrEqualTo(now()->addDays(7));
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .sync-breadcrumb { font-size: 12.5px; color: var(--sync-muted); margin-bottom: 6px; }
        .sync-console .sync-breadcrumb a { color: var(--sync-muted); text-decoration: none; }
        .sync-console .sync-breadcrumb a:hover { color: var(--sync-primary); }
        .sync-console .sync-breadcrumb span { margin: 0 6px; color: #cbd5e1; }
        .sync-console .sync-authorization-warning {
            background: #fffbeb;
            border-color: #fde68a;
        }
        .sync-console .sync-ops-table td.sync-batch-name { min-width: 210px; }
        .sync-console .sync-delta { font-variant-numeric: tabular-nums; font-weight: 600; }
        .sync-console .sync-delta--positive { color: var(--sync-warning); }
        .sync-console .sync-delta--negative { color: var(--sync-info); }
        .sync-console .sync-delta--zero { color: var(--sync-muted); }
        .sync-console .sync-token-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .sync-console .sync-token-row .sync-input { flex: 1 1 260px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
    </style>

    <header class="sync-page-head">
        <div>
            <div class="sync-breadcrumb">
                <a href="{{ route('sync-servers.index') }}">Offline Servers</a><span>/</span>{{ $server->name }}
            </div>
            <h1 class="sync-page-title">{{ $server->name }}</h1>
            <div class="sync-meta">
                <code class="sync-ops-uuid" title="{{ $server->server_uuid }}">{{ $server->server_uuid }}</code>
                <button type="button" class="sync-copy" data-copy="{{ $server->server_uuid }}" aria-label="Copy server UUID">Copy</button>
                <span class="sync-pill {{ $statusPill }}">{{ ucfirst($server->status) }}</span>
                <span class="sync-pill {{ $authorizationPill }}">{{ ucfirst($server->authorizationStatus()) }}</span>
                <span>Last seen <strong>{{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}</strong></span>
                <span>Last sync <strong>{{ $server->last_sync_at?->diffForHumans() ?? 'Never' }}</strong></span>
            </div>
        </div>
        <div class="sync-page-actions">
            <form method="POST" action="{{ route('sync-servers.rotate-token', $server) }}" class="m-0"
                  onsubmit="return confirm('Rotate this server token? The current token will stop working immediately.');">
                @csrf
                <button type="submit" class="sync-btn sync-btn--secondary" @disabled($server->isRevoked())>Rotate token</button>
            </form>
            @unless ($server->isRevoked())
                <form method="POST" action="{{ route('sync-servers.revoke', $server) }}" class="m-0"
                      onsubmit="return confirm('Revoke this server? Its token will be cleared. You can restore it later with a new enrollment token.');">
                    @csrf
                    <button type="submit" class="sync-btn sync-btn--small"
                            style="color: var(--sync-danger); background: #fff; border-color: #fecaca; min-height: 42px; padding: 0 16px; font-size: 14px;">
                        Revoke
                    </button>
                </form>
            @endunless
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-servers.index') }}">Back</a>
        </div>
    </header>

    @if (session('success'))
        <div class="sync-alerts">
            <div class="alert alert-success mb-0">{{ session('success') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="sync-alerts">
            <div class="alert alert-danger mb-0">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    @if (session('server_token'))
        <div class="sync-alerts">
            <div class="alert alert-warning border-warning mb-0">
                <div class="fw-semibold mb-1">New server token (shown once)</div>
                <p class="small mb-2">Update the offline server configuration now. The previous token is invalid.</p>
                <div class="sync-token-row">
                    <input type="text" class="sync-input" id="server-token" value="{{ session('server_token') }}" readonly>
                    <button class="sync-btn sync-btn--secondary" type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('server-token').value); this.textContent = 'Copied';">
                        Copy
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if (session('reauthorization_token'))
        <div class="sync-alerts">
            <div class="alert alert-warning border-warning mb-0">
                <div class="fw-semibold mb-1">Reauthorization token (shown once)</div>
                <p class="small mb-2">
                    Enter this token on the offline installation to restore its authorization period.
                    The token expires {{ \Illuminate\Support\Carbon::parse(session('reauthorization_token_expires_at'))->format('M d, Y g:i A') }}
                    and can be used only once. The new authorization period ends
                    {{ \Illuminate\Support\Carbon::parse(session('reauthorization_authorization_expires_at'))->format('M d, Y g:i A') }}.
                </p>
                <div class="sync-token-row">
                    <input type="text" class="sync-input" id="reauthorization-token"
                           value="{{ session('reauthorization_token') }}" readonly>
                    <button class="sync-btn sync-btn--secondary" type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('reauthorization-token').value); this.textContent = 'Copied';">
                        Copy
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if (session('preparation_token'))
        <div class="sync-alerts">
            <div class="alert alert-warning border-warning mb-0">
                <div class="fw-semibold mb-1">Preparation token (shown once)</div>
                <p class="small mb-2">
                    Enter this token on the offline installation to prepare it for a new encoding session.
                    The token expires {{ \Illuminate\Support\Carbon::parse(session('preparation_token_expires_at'))->format('M d, Y g:i A') }}
                    and can be used only once. The new authorization period ends
                    {{ \Illuminate\Support\Carbon::parse(session('preparation_authorization_expires_at'))->format('M d, Y g:i A') }}.
                </p>
                <div class="sync-token-row">
                    <input type="text" class="sync-input" id="preparation-token"
                           value="{{ session('preparation_token') }}" readonly>
                    <button class="sync-btn sync-btn--secondary" type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('preparation-token').value); this.textContent = 'Copied';">
                        Copy
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($server->authorizationStatus() === 'expired')
        <div class="sync-alerts">
            <div class="alert alert-danger mb-0">
                <div class="fw-semibold">Authorization expired</div>
                <div class="small mt-1">
                    The authorization period for this installation expired
                    {{ $server->authorization_expires_at?->format('M d, Y g:i A') }}.
                    Uploads are rejected by the central server until the installation is reauthorized.
                    Existing records, audit history, and pending operations are preserved.
                </div>
            </div>
        </div>
    @elseif ($authorizationExpiringSoon)
        <div class="sync-alerts">
            <div class="alert alert-warning mb-0">
                <div class="fw-semibold">Authorization expires soon</div>
                <div class="small mt-1">
                    This installation is authorized until {{ $server->authorization_expires_at?->format('M d, Y g:i A') }}.
                    Reauthorize it before that date to avoid rejected uploads.
                </div>
            </div>
        </div>
    @endif

    <section class="sync-summary" aria-label="Server summary">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Assigned scopes</div>
            <div class="sync-stat-value">{{ number_format($server->scopes->count()) }}</div>
            <div class="sync-stat-note">Coverage areas this installation receives.</div>
        </article>
        <article class="sync-card sync-stat {{ $server->conflicts->count() > 0 ? 'sync-stat--danger' : '' }}">
            <div class="sync-stat-label">Open conflicts</div>
            <div class="sync-stat-value">{{ number_format($server->conflicts->count()) }}</div>
            <div class="sync-stat-note">Awaiting administrator resolution.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Recent snapshots</div>
            <div class="sync-stat-value">{{ number_format($server->snapshots->count()) }}</div>
            <div class="sync-stat-note">Full data pulls by this installation.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Recent events</div>
            <div class="sync-stat-value">{{ number_format($server->events->count()) }}</div>
            <div class="sync-stat-note">Newest synchronization events recorded.</div>
        </article>
    </section>

    <section class="sync-card mb-3" aria-labelledby="sync-reconciliation-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-reconciliation-title">Reconciliation</h2>
                <p class="sync-section-subtitle">Central register vs the installation's last reported batch roster.</p>
            </div>
            @if ($reconciliation['captured_at'])
                <span class="sync-registry-count">Roster captured {{ \Illuminate\Support\Carbon::parse($reconciliation['captured_at'])->diffForHumans() }}</span>
            @endif
        </div>

        @if (! $reconciliation['roster_available'])
            <div class="sync-empty">
                <div class="sync-empty-title">No batch roster reported yet</div>
                <div class="sync-empty-note">
                    This card fills in after the installation's next <strong>Sync Now</strong> run against a central
                    server with this feature.
                </div>
            </div>
        @else
            @php
                $verdictBadges = [
                    \App\Services\Sync\SyncReconciliationService::IN_SYNC => ['sync-pill--ok', 'In sync'],
                    \App\Services\Sync\SyncReconciliationService::MISSING_ON_OFFLINE => ['sync-pill--warning', 'Missing on this installation'],
                    \App\Services\Sync\SyncReconciliationService::MISSING_ON_CENTRAL => ['sync-pill--info', 'Not on central'],
                    \App\Services\Sync\SyncReconciliationService::UNKNOWN_TO_CENTRAL => ['sync-pill--danger', 'Unknown to central'],
                    \App\Services\Sync\SyncReconciliationService::DIVERGENT => ['sync-pill--warning', 'Divergent'],
                ];
            @endphp

            <div class="sync-body">
                <div class="sync-summary" style="margin-bottom: 16px;">
                    <div class="sync-card sync-stat" style="padding: 14px 16px;">
                        <div class="sync-stat-label">Central (in scope)</div>
                        <div class="sync-stat-value" style="font-size: 22px;">{{ number_format($reconciliation['totals']['central_in_scope']) }}</div>
                    </div>
                    <div class="sync-card sync-stat sync-stat--warning" style="padding: 14px 16px;">
                        <div class="sync-stat-label">Delivery backlog</div>
                        <div class="sync-stat-value" style="font-size: 22px; color: var(--sync-warning);">{{ number_format($reconciliation['delivery_backlog']) }}</div>
                    </div>
                    <div class="sync-card sync-stat" style="padding: 14px 16px;">
                        <div class="sync-stat-label">Held for review</div>
                        <div class="sync-stat-value" style="font-size: 22px;">{{ number_format($reconciliation['held_for_review']) }}</div>
                    </div>
                    <div class="sync-card sync-stat" style="padding: 14px 16px;">
                        <div class="sync-stat-label">In deleted batches</div>
                        <div class="sync-stat-value" style="font-size: 22px;">{{ number_format($reconciliation['totals']['ghosted_central']) }}</div>
                    </div>
                    <div class="sync-card sync-stat" style="padding: 14px 16px;">
                        <div class="sync-stat-label">Open conflicts</div>
                        <div class="sync-stat-value" style="font-size: 22px;">{{ number_format($reconciliation['open_conflicts']) }}</div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Batch</th>
                            <th scope="col" class="text-center">Central</th>
                            <th scope="col" class="text-center">This installation</th>
                            <th scope="col" class="text-center">Difference</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reconciliation['batches'] as $row)
                            @php
                                $badge = $verdictBadges[$row['verdict']] ?? ['sync-pill--neutral', $row['verdict']];
                                $deltaClass = $row['delta'] > 0 ? 'sync-delta--positive' : ($row['delta'] < 0 ? 'sync-delta--negative' : 'sync-delta--zero');
                            @endphp
                            <tr>
                                <td class="sync-batch-name" style="font-size: 13px;">
                                    {{ $row['name'] }}
                                    @if ($row['deleted_offline'])
                                        <span class="sync-pill sync-pill--neutral" title="This batch was deleted on the installation, but its members remain active there.">deleted locally</span>
                                    @endif
                                </td>
                                <td class="text-center sync-ops-num">{{ $row['central_members'] === null ? '-' : number_format($row['central_members']) }}</td>
                                <td class="text-center sync-ops-num">{{ number_format($row['offline_members']) }}</td>
                                <td class="text-center sync-delta {{ $deltaClass }}">
                                    {{ $row['delta'] > 0 ? '+' : '' }}{{ number_format($row['delta']) }}
                                </td>
                                <td><span class="sync-pill {{ $badge[0] }}">{{ $badge[1] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="sync-body--divided" style="font-size: 12.5px; color: var(--sync-muted);">
                "Missing on this installation" marks central records the installation has not reported - they arrive with the next
                delta/snapshot sync. "Unknown to central" marks locally created batches that predate the installation's sync baseline;
                those need the operator-run batch backfill to upload. Totals never overwrite either side automatically.
            </div>
        @endif
    </section>

    <div class="sync-grid sync-grid--main">
        <section class="sync-card" aria-labelledby="sync-status-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-status-title">Status and authorization</h2>
                    <p class="sync-section-subtitle">Identity, enrollment and reported queue for this installation.</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="sync-pill {{ $statusPill }}">{{ ucfirst($server->status) }}</span>
                    <span class="sync-pill {{ $authorizationPill }}">{{ ucfirst($server->authorizationStatus()) }}</span>
                </div>
            </div>
            <div class="sync-body">
                <dl class="sync-kv">
                    <div class="sync-kv-row"><dt class="sync-kv-label">Enrolled</dt><dd class="sync-kv-value">{{ $server->enrolled_at?->format('M d, Y g:i A') ?? '-' }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Authorized at</dt><dd class="sync-kv-value">{{ $server->authorized_at?->format('M d, Y g:i A') ?? '-' }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Authorization expires</dt><dd class="sync-kv-value">{{ $server->authorization_expires_at?->format('M d, Y g:i A') ?? '-' }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Last seen</dt><dd class="sync-kv-value">{{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Last sync</dt><dd class="sync-kv-value">{{ $server->last_sync_at?->diffForHumans() ?? 'Never' }}</dd></div>
                    <div class="sync-kv-row">
                        <dt class="sync-kv-label">Reported queue</dt>
                        <dd class="sync-kv-value">
                            @if (is_array($reported))
                                {{ (int) ($reported['pending'] ?? 0) }} pending,
                                {{ (int) ($reported['failed'] ?? 0) }} failed,
                                {{ (int) ($reported['conflicts'] ?? 0) }} conflicting
                                <div class="text-muted">Reported {{ $reported['reported_at'] ?? 'unknown' }}</div>
                                @if (! empty($reported['last_error']))
                                    <div class="text-warning-emphasis small">{{ $reported['last_error'] }}</div>
                                @endif
                            @else
                                Not reported yet
                            @endif
                        </dd>
                    </div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">App version</dt><dd class="sync-kv-value">{{ $server->app_version ?: '-' }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Protocol</dt><dd class="sync-kv-value">v{{ $server->protocol_version }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Schema</dt><dd class="sync-kv-value">v{{ $server->schema_version }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Enrolled by</dt><dd class="sync-kv-value">{{ $server->creator?->name ?? 'System' }}</dd></div>
                </dl>
            </div>

            @unless ($server->isRevoked())
                <div class="sync-body--divided">
                    <form method="POST" action="{{ route('sync-servers.status', $server) }}" class="sync-form-row mb-3">
                        @csrf
                        @method('PUT')
                        <div class="sync-field" style="flex-basis: 200px;">
                            <label for="status">Change status</label>
                            <select class="sync-input" id="status" name="status">
                                <option value="active" @selected($server->status === 'active')>Active</option>
                                <option value="suspended" @selected($server->status === 'suspended')>Suspended</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="sync-btn sync-btn--secondary sync-btn--small">Save</button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('sync-servers.reauthorization-token', $server) }}" class="sync-form-row">
                        @csrf
                        <div class="sync-field" style="flex-basis: 220px;">
                            <label for="authorization_days">Reauthorize for (days)</label>
                            <input type="number" class="sync-input" id="authorization_days" name="authorization_days"
                                   min="{{ (int) config('sync.authorization_min_days', 1) }}"
                                   max="{{ (int) config('sync.authorization_max_days', 3650) }}" required>
                        </div>
                        <div>
                            <button type="submit" class="sync-btn sync-btn--primary sync-btn--small">Issue reauthorization token</button>
                        </div>
                    </form>
                </div>
            @endunless

            @if ($server->isRevoked())
                <div class="sync-body--divided">
                    <div class="sync-help mb-2">
                        Revoked installations cannot be reauthorized or receive a new token. Their stored
                        server token no longer works. To restore this installation without losing local data,
                        generate a new enrollment token on the Offline Servers page, then use
                        <strong>Recover this installation</strong> on the offline Enroll Server page. The same
                        server UUID is reactivated.
                    </div>
                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('sync-servers.index') }}">
                        Generate an enrollment token
                    </a>
                </div>
            @endif
        </section>

        <section class="sync-card" aria-labelledby="sync-users-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-users-title">User Monitoring</h2>
                    <p class="sync-section-subtitle">Accounts this installation reported. Read-only registry.</p>
                </div>
                <span class="sync-pill sync-pill--info">{{ number_format($server->encoders->count()) }}</span>
            </div>
            <div class="sync-body">
                <dl class="sync-kv">
                    <div class="sync-kv-row">
                        <dt class="sync-kv-label">Device</dt>
                        <dd class="sync-kv-value">
                            {{ $server->name }}
                            <span class="text-muted font-monospace">{{ $server->server_uuid }}</span>
                        </dd>
                    </div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">App version</dt><dd class="sync-kv-value">{{ $server->app_version ?? 'Unknown' }}</dd></div>
                    <div class="sync-kv-row"><dt class="sync-kv-label">Enrolled</dt><dd class="sync-kv-value">{{ $server->enrolled_at?->diffForHumans() ?? 'Not enrolled' }}</dd></div>
                    <div class="sync-kv-row">
                        <dt class="sync-kv-label">Last seen / last sync</dt>
                        <dd class="sync-kv-value">
                            {{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}
                            /
                            {{ $server->last_sync_at?->diffForHumans() ?? 'Never' }}
                        </dd>
                    </div>
                </dl>
                <p class="sync-help mt-3 mb-0">
                    The Manager is created during offline setup and creates the Encoder accounts; the central server
                    keeps this registry read-only for monitoring.
                </p>
            </div>
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Account</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reported</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($server->encoders as $account)
                            <tr>
                                <td>
                                    <div style="font-size: 13px;">{{ $account->username }}</div>
                                    <div class="text-muted font-monospace" style="font-size: 11.5px;">{{ $account->encoder_uuid }}</div>
                                </td>
                                <td>
                                    @if ($account->role === \App\Models\User::ROLE_MANAGER)
                                        <span class="sync-pill sync-pill--info">Manager</span>
                                    @else
                                        <span class="sync-pill sync-pill--neutral">Encoder</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($account->is_active)
                                        <span class="sync-pill sync-pill--ok">Active</span>
                                    @else
                                        <span class="sync-pill sync-pill--neutral">Disabled</span>
                                    @endif
                                </td>
                                <td class="sync-ops-when">{{ $account->reported_at?->diffForHumans() ?? 'Never' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    This installation has not reported any accounts yet. The registry is filled the
                                    first time the installation synchronizes.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="sync-body--divided" style="font-size: 12.5px; color: var(--sync-muted);">
                No passwords, tokens, or contact details are ever synchronized. Audit records received from this
                installation: <strong>{{ number_format($reportedAuditCount) }}</strong>.
            </div>
        </section>
    </div>

    <section class="sync-card mb-3" aria-labelledby="sync-scopes-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-scopes-title">Assigned scopes</h2>
                <p class="sync-section-subtitle">Beneficiaries this installation is allowed to receive.</p>
            </div>
            <span class="sync-pill sync-pill--neutral">{{ $server->scopes->count() }} {{ Str::plural('scope', $server->scopes->count()) }}</span>
        </div>
        <div class="table-responsive">
            <table class="sync-ops-table">
                <thead>
                    <tr>
                        <th scope="col">Type</th>
                        <th scope="col">Location code</th>
                        <th scope="col">Area</th>
                        <th scope="col" class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($server->scopes as $scope)
                        <tr>
                            <td>{{ $scopeTypes[$scope->scope_type] ?? ucfirst(str_replace('_', ' ', $scope->scope_type)) }}</td>
                            <td>
                                @if ($scope->scope_type === \App\Services\Sync\ScopeService::SCOPE_TYPE_REGION)
                                    <span class="sync-pill sync-pill--info">All BARMM</span>
                                @else
                                    <code class="sync-ops-uuid">{{ $scope->location_code }}</code>
                                @endif
                            </td>
                            <td class="text-muted" style="font-size: 12.5px;">
                                @if ($scope->scope_type === \App\Services\Sync\ScopeService::SCOPE_TYPE_REGION)
                                    Every province in the BARMM region
                                @else
                                    {{ collect([$scope->barangay, $scope->city_municipality, $scope->province])->filter()->join(', ') ?: '-' }}
                                @endif
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('sync-servers.scopes.destroy', [$server, $scope]) }}"
                                      onsubmit="return confirm('Remove this scope?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="sync-btn sync-btn--small" type="submit"
                                            style="color: var(--sync-danger); background: #fff; border-color: #fecaca;">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                No scopes assigned. This server will receive no beneficiary rows.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="sync-body--divided">
            <form method="POST" action="{{ route('sync-servers.scopes.store', $server) }}" class="sync-form-row">
                @csrf
                <div class="sync-field" style="flex: 0 0 170px;">
                    <label for="scope_type">Type</label>
                    <select class="sync-input" id="scope_type" name="scope_type">
                        @foreach ($scopeTypes as $value => $label)
                            <option value="{{ $value }}" @selected(old('scope_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sync-field">
                    <label for="location_code">PSGC code</label>
                    <input class="sync-input @error('location_code') is-invalid @enderror"
                           id="location_code" name="location_code" value="{{ old('location_code') }}"
                           inputmode="numeric" pattern="\d{4,10}" list="scopeCodes" placeholder="e.g. 1900702000" required>
                    <datalist id="scopeCodes">
                        @foreach ($scopeSuggestions as $code => $province)
                            <option value="{{ $code }}">{{ $province }}</option>
                        @endforeach
                    </datalist>
                    <div class="form-text small mt-1" id="scope-code-help">
                        Examples: {{ $scopeExamples }}. For cities use 7 digits; barangays use 10.
                    </div>
                    @error('location_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="sync-field">
                    <label for="city_municipality">City / municipality</label>
                    <input class="sync-input" id="city_municipality" name="city_municipality"
                           value="{{ old('city_municipality') }}" placeholder="Optional label">
                </div>
                <div>
                    <button class="sync-btn sync-btn--primary" type="submit">Add scope</button>
                </div>
            </form>
        </div>
    </section>

    <div class="sync-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
        <section class="sync-card" aria-labelledby="sync-events-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-events-title">Recent sync events</h2>
                    <p class="sync-section-subtitle">Newest events recorded for this installation.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Type</th>
                            <th scope="col">Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($server->events as $event)
                            <tr>
                                <td class="sync-ops-when">{{ $event->created_at?->diffForHumans() }}</td>
                                <td>
                                    @php
                                        $eventPill = match ($event->severity) {
                                            'error', 'critical' => 'sync-pill--danger',
                                            'warning' => 'sync-pill--warning',
                                            default => 'sync-pill--neutral',
                                        };
                                    @endphp
                                    <span class="sync-pill {{ $eventPill }}">{{ $event->type }}</span>
                                </td>
                                <td style="font-size: 12.5px;">{{ $event->message ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No sync events yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sync-card" aria-labelledby="sync-snapshots-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-snapshots-title">Snapshots</h2>
                    <p class="sync-section-subtitle">Full data pulls available to this installation.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Created</th>
                            <th scope="col">Rows</th>
                            <th scope="col">Revision</th>
                            <th scope="col">Expires</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($server->snapshots as $snapshot)
                            <tr>
                                <td class="sync-ops-when">{{ $snapshot->created_at?->format('M d g:i A') }}</td>
                                <td class="sync-ops-num">{{ number_format($snapshot->row_count) }}</td>
                                <td class="sync-ops-num">{{ $snapshot->revision_to ?? '-' }}</td>
                                <td class="sync-ops-when">{{ $snapshot->expires_at?->diffForHumans() ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No snapshots pulled yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sync-card" aria-labelledby="sync-conflicts-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-conflicts-title">Open conflicts</h2>
                    <p class="sync-section-subtitle">Records requiring administrator review.</p>
                </div>
                @if ($server->conflicts->count() > 0)
                    <a href="{{ route('sync-conflicts.index', ['source' => 'server']) }}" class="sync-btn sync-btn--small"
                       style="color: var(--sync-danger); background: #fff; border-color: #fecaca;">
                        Review all
                    </a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Entity</th>
                            <th scope="col">UUID</th>
                            <th scope="col">Origin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($server->conflicts as $conflict)
                            <tr>
                                <td>{{ $conflict->entity_type }}</td>
                                <td><code class="sync-ops-uuid" title="{{ $conflict->entity_uuid }}">{{ $conflict->entity_uuid }}</code></td>
                                <td><code class="sync-ops-uuid" title="{{ $conflict->origin_server_uuid }}">{{ $conflict->origin_server_uuid }}</code></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No open conflicts.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sync-card" aria-labelledby="sync-checkpoints-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="sync-checkpoints-title">Checkpoints</h2>
                    <p class="sync-section-subtitle">Acknowledged synchronization progress.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Direction</th>
                            <th scope="col">Last revision</th>
                            <th scope="col">Synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($server->checkpoints as $checkpoint)
                            <tr>
                                <td>{{ ucfirst($checkpoint->direction) }}</td>
                                <td class="sync-ops-num">{{ number_format($checkpoint->last_revision) }}</td>
                                <td class="sync-ops-when">{{ $checkpoint->synced_at?->diffForHumans() ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No checkpoints recorded.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var typeSelect = document.getElementById('scope_type');
    var codeInput = document.getElementById('location_code');
    var codeHelp = document.getElementById('scope-code-help');

    if (!typeSelect || !codeInput) {
        return;
    }

    var defaultHelp = codeHelp ? codeHelp.innerHTML : '';
    var defaultPlaceholder = codeInput.getAttribute('placeholder') || '';

    function syncScopeCodeInput() {
        var isRegion = typeSelect.value === 'region';

        if (isRegion) {
            codeInput.value = 'BARMM';
            codeInput.readOnly = true;
            codeInput.removeAttribute('pattern');
            codeInput.setAttribute('placeholder', 'Entire BARMM region');
            codeInput.classList.remove('is-invalid');

            if (codeHelp) {
                codeHelp.textContent = 'This scope covers every province in the BARMM region. No specific PSGC code is needed.';
            }

            return;
        }

        codeInput.readOnly = false;
        codeInput.setAttribute('pattern', '\\d{4,10}');
        codeInput.setAttribute('placeholder', defaultPlaceholder);

        if (codeInput.value === 'BARMM') {
            codeInput.value = '';
        }

        if (codeHelp) {
            codeHelp.innerHTML = defaultHelp;
        }
    }

    typeSelect.addEventListener('change', syncScopeCodeInput);
    syncScopeCodeInput();
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) {
            return;
        }

        var value = button.getAttribute('data-copy') || '';
        var done = function () {
            button.textContent = 'Copied';
            window.setTimeout(function () { button.textContent = 'Copy'; }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(function () {
                button.textContent = 'Copy failed';
            });
        }
    });
})();
</script>
@endpush
