@extends('layouts.app')

@section('title', 'Offline Servers')

@section('content')
<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .sync-summary--five { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        @media (max-width: 1199.98px) { .sync-console .sync-summary--five { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 767.98px) { .sync-console .sync-summary--five { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 420px) { .sync-console .sync-summary--five { grid-template-columns: 1fr; } }

        .sync-console a.sync-stat { text-decoration: none; color: inherit; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease; }
        .sync-console a.sync-stat:hover { border-color: #cbd5e1; box-shadow: 0 4px 10px rgba(15, 23, 42, .07); }
        .sync-console a.sync-stat:focus-visible { outline: 2px solid var(--sync-primary); outline-offset: 2px; }

        .sync-console .sync-stat-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 4px;
            background: #f1f5f9;
            color: #475569;
        }
        .sync-console .sync-stat-icon--ok { background: var(--sync-success-soft); color: var(--sync-success); }
        .sync-console .sync-stat-icon--warning { background: var(--sync-warning-soft); color: var(--sync-warning); }
        .sync-console .sync-stat-icon--danger { background: var(--sync-danger-soft); color: var(--sync-danger); }
        .sync-console .sync-stat-icon--info { background: var(--sync-info-soft); color: var(--sync-info); }

        .sync-console .sync-toolbar { padding: 14px 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .sync-console .sync-toolbar .sync-field { flex: 1 1 260px; }
        .sync-console .sync-toolbar .sync-field--status { flex: 0 0 190px; }
        @media (max-width: 575.98px) { .sync-console .sync-toolbar .sync-field--status { flex: 1 1 100%; } }

        .sync-console .sync-search-wrap { position: relative; }
        .sync-console .sync-search-wrap .sync-input { padding-left: 34px; }
        .sync-console .sync-search-icon {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--sync-muted);
            pointer-events: none;
            display: inline-flex;
        }

        .sync-console .sync-registry-count { font-size: 12.5px; color: var(--sync-muted); }
        .sync-console .sync-server-name { font-weight: 600; font-size: 13.5px; margin-bottom: 3px; }
        .sync-console .sync-server-cell { min-width: 190px; }
        .sync-console .sync-queue { display: grid; gap: 2px; font-size: 12.5px; min-width: 148px; }
        .sync-console .sync-queue-row { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .sync-console .sync-queue-row--pending { color: var(--sync-primary); }
        .sync-console .sync-queue-row--failed { color: var(--sync-danger); }
        .sync-console .sync-queue-row--review { color: var(--sync-warning); }
        .sync-console .sync-queue-row--conflicts { color: var(--sync-danger); }
        .sync-console .sync-queue-meta { color: var(--sync-muted); font-size: 11.5px; white-space: nowrap; }
        .sync-console .sync-scope-list { color: var(--sync-muted); font-size: 12px; max-width: 210px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sync-console .sync-ops-table td { vertical-align: top; }
        .sync-console .sync-auth-date { font-size: 11.5px; color: var(--sync-muted); margin-top: 3px; white-space: nowrap; }
        .sync-console .sync-auth-date--warning { color: var(--sync-warning); font-weight: 600; }
        .sync-console .sync-version-meta { color: var(--sync-muted); font-size: 11.5px; margin-top: 2px; }
        .sync-console .sync-time-cell { white-space: nowrap; font-size: 12.5px; color: var(--sync-muted); }
        .sync-console .sync-time-cell--stale { color: var(--sync-warning); font-weight: 600; }
        .sync-console .sync-ops-table thead th:first-child,
        .sync-console .sync-ops-table tbody td:first-child { position: sticky; left: 0; background: #fff; z-index: 1; }
        .sync-console .sync-ops-table thead th:first-child { background: #f6f7fb; z-index: 2; }
        .sync-console .sync-ops-table tbody tr:hover td:first-child { background: #f6f7fb; }
        @media (max-width: 991.98px) { .sync-console .sync-ops-table thead th:first-child, .sync-console .sync-ops-table tbody td:first-child { position: static; } }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Offline Servers</h1>
            <p class="sync-page-subtitle">
                Registered offline installations, authorization, scopes, health, and sync history.
            </p>
            <div class="sync-meta">
                <span>{{ number_format($counts['total']) }} registered</span>
                <span>{{ number_format($counts['active']) }} active</span>
                <span>{{ number_format($counts['conflicts']) }} open {{ Str::plural('conflict', $counts['conflicts']) }}</span>
            </div>
        </div>
        <div class="sync-page-actions">
            <button class="sync-btn sync-btn--primary" type="button" data-bs-toggle="collapse" data-bs-target="#new-installation"
                    aria-expanded="false" aria-controls="new-installation">
                Prepare an installation
            </button>
            <a class="sync-btn sync-btn--secondary" href="{{ route('enroll-server.index') }}">Enroll Server</a>
            <a class="sync-btn sync-btn--secondary" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    @if (session('success'))
        <div class="sync-alerts">
            <div class="alert alert-success mb-0">{{ session('success') }}</div>
        </div>
    @endif

    @if (session('enrollment_token'))
        <div class="sync-alerts">
            <div class="alert alert-warning border-warning mb-0">
                <div class="fw-semibold mb-1">Enrollment token (shown once)</div>
                <p class="small mb-2">
                    Give this token to the offline installer. It expires
                    {{ \Illuminate\Support\Carbon::parse(session('enrollment_token_expires_at'))->format('M d, Y g:i A') }}
                    and can be used only once. The installation will be authorized until
                    {{ \Illuminate\Support\Carbon::parse(session('enrollment_authorization_expires_at'))->format('M d, Y g:i A') }}.
                </p>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="enrollment-token"
                           value="{{ session('enrollment_token') }}" readonly>
                    <button class="btn btn-outline-dark" type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('enrollment-token').value); this.textContent = 'Copied';">
                        Copy
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="collapse mb-3" id="new-installation">
        <section class="sync-card">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title">Prepare an offline installation</h2>
                    <p class="sync-section-subtitle">Issue a one-time enrollment token and set its authorization period.</p>
                </div>
            </div>
            <div class="sync-body">
                <p class="sync-help">
                    The token below authorizes one offline installation and is used only once.
                    Set the authorization period explicitly: it defines when the installation
                    must be reauthorized before it can save records or synchronize again.
                    The same token can also restore a revoked installation through
                    <strong>Recover this installation</strong> on the offline Enroll Server page;
                    its local data and server UUID are preserved.
                </p>
                <form method="POST" action="{{ route('sync-servers.enrollment-token') }}" class="sync-form-row">
                    @csrf
                    <div class="sync-field">
                        <label for="device_label">Device / installation label</label>
                        <input type="text" class="sync-input" id="device_label" name="device_label"
                               value="{{ old('device_label') }}" maxlength="120"
                               placeholder="e.g. Zamboanga City Laptop 1">
                    </div>
                    <div class="sync-field" style="flex-basis: 190px;">
                        <label for="authorization_days">Authorization period (days)</label>
                        <input type="number" class="sync-input" id="authorization_days" name="authorization_days"
                               value="{{ old('authorization_days') }}" min="{{ (int) config('sync.authorization_min_days', 1) }}"
                               max="{{ (int) config('sync.authorization_max_days', 3650) }}" required>
                        @error('authorization_days')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="sync-field">
                        <label for="note">Note</label>
                        <input type="text" class="sync-input" id="note" name="note"
                               value="{{ old('note') }}" maxlength="120" placeholder="Optional reference">
                    </div>
                    <div>
                        <button class="sync-btn sync-btn--primary" type="submit">Generate token</button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <section class="sync-summary sync-summary--five" aria-label="Offline server overview">
        <a class="sync-card sync-stat" href="{{ route('sync-servers.index') }}">
            <span class="sync-stat-icon sync-stat-icon--info" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 16 16" fill="currentColor"><path d="M5 1h6a2 2 0 0 1 2 2v1H3V3a2 2 0 0 1 2-2M2 5h12v3H2zm0 4h12v3H2zm2 4h8a2 2 0 0 0 2-2v-1H2v1a2 2 0 0 0 2 2"/></svg>
            </span>
            <div class="sync-stat-label">Total servers</div>
            <div class="sync-stat-value">{{ number_format($counts['total']) }}</div>
            <div class="sync-stat-note">All registered installations.</div>
        </a>

        <a class="sync-card sync-stat sync-stat--ok" href="{{ route('sync-servers.index', ['status' => 'active']) }}">
            <span class="sync-stat-icon sync-stat-icon--ok" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a1 1 0 0 1 1 1v5.586l2.293-2.293a1 1 0 1 1 1.414 1.414L8 10.414 3.293 5.707a1 1 0 1 1 1.414-1.414L7 6.586V1a1 1 0 0 1 1-1M1 12a1 1 0 0 1 1 1v1h12v-1a1 1 0 1 1 2 0v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-1a1 1 0 0 1 1-1"/></svg>
            </span>
            <div class="sync-stat-label">Active</div>
            <div class="sync-stat-value">{{ number_format($counts['active']) }}</div>
            <div class="sync-stat-note">Authorized and operational.</div>
        </a>

        <a class="sync-card sync-stat sync-stat--warning" href="{{ route('sync-servers.index', ['status' => 'suspended']) }}">
            <span class="sync-stat-icon sync-stat-icon--warning" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1M5.5 7.25A.75.75 0 0 1 6.25 8v2a.75.75 0 0 1-1.5 0V8a.75.75 0 0 1 .75-.75m4.25 0A.75.75 0 0 1 10.5 8v2a.75.75 0 0 1-1.5 0V8a.75.75 0 0 1 .75-.75"/></svg>
            </span>
            <div class="sync-stat-label">Suspended</div>
            <div class="sync-stat-value">{{ number_format($counts['suspended']) }}</div>
            <div class="sync-stat-note">Temporarily blocked installations.</div>
        </a>

        <a class="sync-card sync-stat sync-stat--danger" href="{{ route('sync-servers.index', ['status' => 'revoked']) }}">
            <span class="sync-stat-icon sync-stat-icon--danger" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0 1 3v5c0 3.6 2.5 6.9 7 8 4.5-1.1 7-4.4 7-8V3zm0 3.5a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0v-3A.75.75 0 0 1 8 3.5m0 6.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/></svg>
            </span>
            <div class="sync-stat-label">Revoked</div>
            <div class="sync-stat-value">{{ number_format($counts['revoked']) }}</div>
            <div class="sync-stat-note">Authorization revoked.</div>
        </a>

        <a class="sync-card sync-stat sync-stat--danger" href="{{ route('sync-conflicts.index') }}">
            <span class="sync-stat-icon sync-stat-icon--danger" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a1 1 0 0 1 .86.49l6.5 11A1 1 0 0 1 14.5 14h-13a1 1 0 0 1-.86-1.51l6.5-11A1 1 0 0 1 8 1m0 4a.75.75 0 0 0-.75.75v3a.75.75 0 0 0 1.5 0v-3A.75.75 0 0 0 8 5m0 5.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2"/></svg>
            </span>
            <div class="sync-stat-label">Open conflicts</div>
            <div class="sync-stat-value">{{ number_format($counts['conflicts']) }}</div>
            <div class="sync-stat-note">Awaiting administrator resolution.</div>
        </a>
    </section>

    <form method="GET" action="{{ route('sync-servers.index') }}" class="sync-card mb-3" role="search">
        <div class="sync-toolbar">
            <div class="sync-field">
                <label class="sync-kv-label d-block mb-1" for="q">Search</label>
                <div class="sync-search-wrap">
                    <span class="sync-search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="search" class="sync-input" id="q" name="q"
                           value="{{ $filters['q'] }}" placeholder="Search by server name or UUID">
                </div>
            </div>
            <div class="sync-field sync-field--status">
                <label class="sync-kv-label d-block mb-1" for="status">Status</label>
                <select class="sync-input" id="status" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach (['active', 'suspended', 'revoked'] as $status)
                        <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="d-flex gap-2">
                <button class="sync-btn sync-btn--primary" type="submit">Apply filters</button>
                @if ($filters['q'] !== '' || $filters['status'] !== '')
                    <a class="sync-btn sync-btn--secondary" href="{{ route('sync-servers.index') }}">Reset</a>
                @endif
            </div>
        </div>
    </form>

    <section class="sync-card" aria-labelledby="sync-registry-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-registry-title">Server registry</h2>
                <p class="sync-section-subtitle">Authorization, scopes, versions and the last reported queue for each installation.</p>
            </div>
            <div class="sync-registry-count">
                Showing {{ number_format($servers->count()) }} of {{ number_format($servers->total()) }}
                {{ Str::plural('server', $servers->total()) }}
                @if ($filters['status'] !== '')
                    - {{ ucfirst($filters['status']) }} only
                @endif
            </div>
        </div>

        @if ($servers->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">
                    @if ($filters['q'] !== '' || $filters['status'] !== '')
                        No servers match the current filters
                    @else
                        No offline installations registered yet
                    @endif
                </div>
                <div class="sync-empty-note">
                    @if ($filters['q'] !== '' || $filters['status'] !== '')
                        Try a different search term, or reset the filters.
                    @else
                        Prepare an installation to begin enrollment.
                    @endif
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Server</th>
                            <th scope="col">Status</th>
                            <th scope="col">Authorization</th>
                            <th scope="col">Scopes</th>
                            <th scope="col">Versions</th>
                            <th scope="col">Last seen</th>
                            <th scope="col">Last sync</th>
                            <th scope="col">Reported queue</th>
                            <th scope="col">Open conflicts</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($servers as $server)
                            @php
                                $statusPill = match ($server->status) {
                                    'active' => 'sync-pill--ok',
                                    'suspended' => 'sync-pill--warning',
                                    'revoked' => 'sync-pill--danger',
                                    default => 'sync-pill--neutral',
                                };
                                $authorizationPill = match ($server->authorizationStatus()) {
                                    'active' => 'sync-pill--ok',
                                    'expired', 'revoked' => 'sync-pill--danger',
                                    'suspended' => 'sync-pill--warning',
                                    default => 'sync-pill--neutral',
                                };
                                $expires = $server->authorization_expires_at;
                                $expiringSoon = $expires !== null
                                    && $expires->isFuture()
                                    && $expires->lessThanOrEqualTo(now()->addDays(7));
                                $reported = data_get($server->metadata, 'last_reported');
                                $serverConflicts = (int) ($openConflicts[$server->id] ?? 0);
                                $staleSync = $server->last_sync_at === null || $server->last_sync_at->lt(now()->subDay());
                            @endphp
                            <tr>
                                <td>
                                    <div class="sync-server-cell">
                                        <div class="sync-server-name">{{ $server->name }}</div>
                                        <div class="sync-ops-entity">
                                            <code class="sync-ops-uuid" title="{{ $server->server_uuid }}">{{ $server->server_uuid }}</code>
                                            <button type="button" class="sync-copy" data-copy="{{ $server->server_uuid }}"
                                                    aria-label="Copy server UUID">Copy</button>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="sync-pill {{ $statusPill }}">{{ ucfirst($server->status) }}</span></td>
                                <td>
                                    <span class="sync-pill {{ $authorizationPill }}">{{ ucfirst($server->authorizationStatus()) }}</span>
                                    <div class="sync-auth-date {{ $expiringSoon ? 'sync-auth-date--warning' : '' }}">
                                        @if ($expires)
                                            Until {{ $expires->format('M d, Y g:i A') }}{{ $expiringSoon ? ' - expiring soon' : '' }}
                                        @else
                                            No authorization period set
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if ($server->scopes->isEmpty())
                                        <span class="text-muted small">None</span>
                                    @else
                                        <span class="sync-pill sync-pill--neutral">{{ $server->scopes->count() }} {{ Str::plural('scope', $server->scopes->count()) }}</span>
                                        <div class="sync-scope-list" title="{{ $server->scopes->pluck('location_code')->filter()->join(', ') }}">
                                            {{ $server->scopes->pluck('location_code')->filter()->join(', ') }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-size: 12.5px;">{{ $server->app_version ?: '-' }}</div>
                                    <div class="sync-version-meta">Protocol {{ $server->protocol_version }} / Schema {{ $server->schema_version }}</div>
                                </td>
                                <td>
                                    <span class="sync-time-cell" title="{{ $server->last_seen_at?->toDayDateTimeString() ?? 'Never' }}">
                                        {{ $server->last_seen_at?->diffForHumans() ?? 'Never' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="sync-time-cell {{ $staleSync ? 'sync-time-cell--stale' : '' }}"
                                          title="{{ $server->last_sync_at?->toDayDateTimeString() ?? 'Never' }}">
                                        {{ $server->last_sync_at?->diffForHumans() ?? 'Never' }}
                                    </span>
                                </td>
                                <td>
                                    @if (is_array($reported))
                                        <div class="sync-queue">
                                            <div class="sync-queue-row sync-queue-row--pending">Pending: {{ (int) ($reported['pending'] ?? 0) }}</div>
                                            <div class="sync-queue-row sync-queue-row--failed">Failed: {{ (int) ($reported['failed'] ?? 0) }}</div>
                                            <div class="sync-queue-row sync-queue-row--review">Awaiting review: {{ (int) ($reported['awaiting_review'] ?? 0) }}</div>
                                            <div class="sync-queue-row sync-queue-row--conflicts">Conflicts: {{ (int) ($reported['conflicts'] ?? 0) }}</div>
                                        </div>
                                        <div class="sync-queue-meta mt-1">
                                            Reported {{ $reported['reported_at'] ?? 'unknown' }}
                                        </div>
                                        @if (! empty($reported['last_error']))
                                            <div class="text-warning-emphasis mt-1" style="font-size: 11.5px;" title="{{ $reported['last_error'] }}">
                                                Last error reported
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted small">Not reported yet</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($serverConflicts > 0)
                                        <span class="sync-pill sync-pill--danger">{{ $serverConflicts }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('sync-servers.show', $server) }}">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($servers->hasPages())
            <div class="sync-body--divided">
                {{ $servers->links() }}
            </div>
        @endif
    </section>
</div>
@endsection

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
