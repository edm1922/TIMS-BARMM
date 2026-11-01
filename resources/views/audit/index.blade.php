@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
@php
    $filterLabels = [];
    if ($filters['q'] !== '') {
        $filterLabels['Search'] = $filters['q'];
    }
    if ($filters['action'] !== '') {
        $filterLabels['Action'] = $filters['action'];
    }
    if ($filters['user_id'] !== '') {
        $filterLabels['User'] = $users->firstWhere('id', (int) $filters['user_id'])?->name ?? '#'.$filters['user_id'];
    }
    if ($filters['origin'] !== '') {
        $filterLabels['Origin'] = substr($filters['origin'], 0, 8).'...';
    }
    if ($filters['created_from']) {
        $filterLabels['From'] = $filters['created_from'];
    }
    if ($filters['created_to']) {
        $filterLabels['To'] = $filters['created_to'];
    }
    $hasFilters = $filterLabels !== [];
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .audit-filter-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; padding: 18px 20px; }
        .sync-console .audit-filter-grid .span-3 { grid-column: span 3; }
        .sync-console .audit-filter-grid .span-2 { grid-column: span 2; }
        @media (max-width: 991.98px) {
            .sync-console .audit-filter-grid .span-3, .sync-console .audit-filter-grid .span-2 { grid-column: span 6; }
        }
        @media (max-width: 575.98px) {
            .sync-console .audit-filter-grid { grid-template-columns: 1fr; padding: 16px; }
            .sync-console .audit-filter-grid > * { grid-column: auto !important; }
        }
        .sync-console .audit-search-wrap { position: relative; }
        .sync-console .audit-search-wrap .sync-input { padding-left: 34px; }
        .sync-console .audit-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--sync-muted); pointer-events: none; display: inline-flex; }
        .sync-console .audit-when { white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 12.5px; font-weight: 500; }
        .sync-console .audit-when-relative { font-size: 11px; color: var(--sync-muted); }
        .sync-console .audit-actor { font-size: 13px; font-weight: 600; }
        .sync-console .audit-actor-role { font-size: 11.5px; color: var(--sync-muted); }
        .sync-console .audit-actor-system { font-weight: 500; color: var(--sync-muted); }
        .sync-console .audit-origin { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; color: #334155; }
        .sync-console .audit-action { font-size: 11.5px; font-weight: 600; overflow-wrap: anywhere; }
        .sync-console .audit-entity { font-size: 12.5px; }
        .sync-console .audit-ip { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; }
        .sync-console .audit-details { min-width: 18rem; max-width: 30rem; font-size: 12px; }
        .sync-console .audit-details .text-muted { color: var(--sync-muted) !important; }
        .sync-console .audit-empty { padding: 48px 20px; text-align: center; }
        .sync-console .audit-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .sync-console .audit-modal-meta { font-size: 12.5px; }
        .sync-console .audit-modal-meta .text-nowrap { white-space: normal !important; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Audit log</h1>
            <p class="sync-page-subtitle">Track sensitive operations, administrative activities and system events. Read-only history.</p>
            <div class="sync-meta">
                <span><strong>{{ number_format($logs->total()) }}</strong> audit {{ Str::plural('entry', $logs->total()) }}</span>
                @if ($logs->hasPages())
                    <span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
                @endif
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--secondary" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    <form method="GET" action="{{ route('audit.index') }}" class="sync-card mb-3">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title">Search &amp; filter audit events</h2>
                <p class="sync-section-subtitle">Filters apply to the audit trail only; records are never edited or removed.</p>
            </div>
            @if ($hasFilters)
                <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('audit.index') }}">Clear filters</a>
            @endif
        </div>

        <div class="audit-filter-grid">
            <div class="span-3">
                <label class="sync-kv-label d-block mb-1" for="q">Search</label>
                <div class="audit-search-wrap">
                    <span class="audit-search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="search" class="sync-input" id="q" name="q" value="{{ $filters['q'] }}"
                           placeholder="Search by user, action, entity, or IP address...">
                </div>
            </div>

            <div class="span-3">
                <label class="sync-kv-label d-block mb-1" for="action">Action</label>
                <select class="sync-input" id="action" name="action">
                    <option value="">All actions</option>
                    @foreach ($actionGroups as $group => $options)
                        <optgroup label="{{ $group }}">
                            @foreach ($options as $option)
                                <option value="{{ $option['value'] }}" @selected($filters['action'] === $option['value'])>
                                    {{ $option['label'] }} ({{ number_format($option['count']) }})
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="user_id">User</label>
                <select class="sync-input" id="user_id" name="user_id">
                    <option value="">All users</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['user_id'] === (string) $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="origin">Origin installation</label>
                <select class="sync-input" id="origin" name="origin">
                    <option value="">All installations</option>
                    @foreach ($origins as $origin)
                        <option value="{{ $origin }}" @selected($filters['origin'] === $origin)>{{ substr($origin, 0, 8) }}...</option>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="created_from">From</label>
                <input type="date" class="sync-input" id="created_from" name="created_from" value="{{ $filters['created_from'] }}">
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="created_to">To</label>
                <input type="date" class="sync-input" id="created_to" name="created_to" value="{{ $filters['created_to'] }}">
            </div>

            <div class="span-4 d-flex align-items-end gap-2">
                <button class="sync-btn sync-btn--primary" type="submit">Apply filters</button>
                @if ($hasFilters)
                    <a class="sync-btn sync-btn--secondary" href="{{ route('audit.index') }}">Reset</a>
                @endif
            </div>
        </div>
    </form>

    <section class="sync-card" aria-labelledby="audit-table-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="audit-table-title">Audit records</h2>
                <p class="sync-section-subtitle">Newest events first; entries are immutable and read-only.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @foreach ($filterLabels as $label => $value)
                    <span class="audit-chip">{{ $label }}: <strong>{{ $value }}</strong></span>
                @endforeach
            </div>
        </div>

        @if ($logs->isEmpty())
            <div class="audit-empty">
                <div class="sync-empty-title">No audit entries match these filters.</div>
                <div class="sync-empty-note">Adjust the filters, or <a href="{{ route('audit.index') }}">clear all filters</a>.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Audit events matching the current filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Actor</th>
                            <th scope="col">Origin</th>
                            <th scope="col">Action</th>
                            <th scope="col">Entity</th>
                            <th scope="col">IP</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            @php($actorName = $actorNames[$log->id] ?? null)
                            <tr>
                                <td>
                                    <div class="audit-when" title="{{ ($log->occurred_at ?? $log->created_at)?->toDayDateTimeString() }}">
                                        {{ ($log->occurred_at ?? $log->created_at)?->format('Y-m-d H:i:s') }}
                                    </div>
                                    <div class="audit-when-relative">{{ ($log->occurred_at ?? $log->created_at)?->diffForHumans() }}</div>
                                </td>
                                <td>
                                    @if ($actorName)
                                        <div class="audit-actor">{{ $actorName }}</div>
                                        @if ($log->actor_role)
                                            <div class="audit-actor-role">{{ $roleLabels[$log->actor_role] ?? $log->actor_role }}</div>
                                        @endif
                                    @elseif ($log->actor_username)
                                        <div class="audit-actor">{{ $log->actor_username }}</div>
                                        @if ($log->actor_role)
                                            <div class="audit-actor-role">{{ $roleLabels[$log->actor_role] ?? $log->actor_role }}</div>
                                        @endif
                                    @else
                                        <span class="audit-actor-system">System</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($log->origin_server_uuid)
                                        <div class="d-flex align-items-center gap-1 flex-wrap">
                                            <span class="audit-origin" title="{{ $log->origin_server_uuid }}">{{ substr($log->origin_server_uuid, 0, 8) }}&hellip;</span>
                                            <button type="button" class="sync-copy" data-copy="{{ $log->origin_server_uuid }}" aria-label="Copy origin UUID">Copy</button>
                                        </div>
                                        @if ($log->synced_at)
                                            <span class="sync-pill sync-pill--info mt-1">synced</span>
                                        @endif
                                    @else
                                        <span class="text-muted">Local</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="sync-pill sync-pill--neutral audit-action" title="{{ $log->action }}">
                                        {{ $actionLabels[$log->action] ?? $log->action }}
                                    </span>
                                </td>
                                <td class="audit-entity">
                                    @if ($log->entity_type)
                                        {{ $log->entity_type }}@if ($log->entity_id) #{{ $log->entity_id }}@endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="audit-ip">{{ $log->ip ?? '-' }}</td>
                                <td class="audit-details">
                                    @if ($log->metadata)
                                        @include('partials.audit-metadata', ['metadata' => $log->metadata, 'names' => $beneficiaryNames[$log->id] ?? []])
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                    <button type="button" class="sync-btn sync-btn--secondary sync-btn--small mt-2"
                                            data-bs-toggle="modal" data-bs-target="#auditDetails{{ $log->id }}">
                                        View details
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="sync-body--divided d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="sync-registry-count">
                    Showing {{ number_format($logs->firstItem() ?? 0) }}-{{ number_format($logs->lastItem() ?? 0) }}
                    of {{ number_format($logs->total()) }} entries
                </span>
                {{ $logs->links() }}
            </div>
        @endif
    </section>

    {{-- Read-only event details: the complete stored metadata for each row. --}}
    @foreach ($logs as $log)
        <div class="modal fade" id="auditDetails{{ $log->id }}" tabindex="-1" aria-labelledby="auditDetailsLabel{{ $log->id }}" aria-hidden="true">
            <div class="modal-dialog tims-modal--lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <span class="tims-modal-icon" aria-hidden="true">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>
                            </svg>
                        </span>
                        <div>
                            <h2 class="modal-title" id="auditDetailsLabel{{ $log->id }}">Audit event details</h2>
                            <p class="tims-modal-subtitle">Read-only record from the immutable audit trail.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body audit-modal-meta">
                        <dl class="sync-kv">
                            <div class="sync-kv-row"><dt class="sync-kv-label">Action</dt><dd class="sync-kv-value">{{ $actionLabels[$log->action] ?? $log->action }} <code>{{ $log->action }}</code></dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">When</dt><dd class="sync-kv-value">{{ ($log->occurred_at ?? $log->created_at)?->format('Y-m-d H:i:s') }} ({{ ($log->occurred_at ?? $log->created_at)?->diffForHumans() }})</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Actor</dt><dd class="sync-kv-value">{{ $actorNames[$log->id] ?? ($log->actor_username ?: 'System') }}{{ $log->actor_role ? ' - '.($roleLabels[$log->actor_role] ?? $log->actor_role) : '' }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Entity</dt><dd class="sync-kv-value">{{ $log->entity_type ?: '-' }}@if ($log->entity_id) #{{ $log->entity_id }}@endif</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Origin installation</dt><dd class="sync-kv-value font-monospace" style="font-size: 12px;">{{ $log->origin_server_uuid ?: 'Local (this installation)' }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">IP address</dt><dd class="sync-kv-value font-monospace" style="font-size: 12px;">{{ $log->ip ?: '-' }}</dd></div>
                            <div class="sync-kv-row"><dt class="sync-kv-label">Event UUID</dt><dd class="sync-kv-value font-monospace" style="font-size: 12px; overflow-wrap: anywhere;">{{ $log->event_uuid }}</dd></div>
                        </dl>

                        <h3 class="sync-subhead mt-3">Recorded metadata</h3>
                        @if ($log->metadata)
                            <div class="audit-modal-meta">
                                @include('partials.audit-metadata', ['metadata' => $log->metadata, 'names' => $beneficiaryNames[$log->id] ?? []])
                            </div>
                        @else
                            <p class="sync-help mb-0">No additional metadata was recorded for this event.</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
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
