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
        .sync-console .audit-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; border: 1px solid #e5e7eb; }
        .sync-console .audit-modal-meta { font-size: 12.5px; }
        .sync-console .audit-modal-meta .text-nowrap { white-space: normal !important; }
    </style>

    <style>
        .audit-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
        .audit-toolbar-title { font-size: 1.45rem; font-weight: 700; margin: 0; color: var(--sync-text); }
        .audit-toolbar-subtitle { margin: 2px 0 0; font-size: .82rem; color: var(--sync-muted); }
        .audit-toolbar-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .audit-search { position: relative; min-width: 240px; }
        .audit-search .sync-input { padding-left: 34px; min-height: 38px; font-size: 12.5px; border-radius: 6px; }
        .audit-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--sync-muted); pointer-events: none; display: inline-flex; }
        .audit-filter-btn {
            display: inline-flex; align-items: center; gap: 7px; min-height: 38px; padding: 4px 12px;
            border: 1px solid var(--sync-primary); border-radius: 6px; background: var(--sync-primary);
            color: #fff; font-size: 12.5px; font-weight: 600; white-space: nowrap;
        }
        .audit-filter-btn:hover, .audit-filter-btn:focus { background: var(--sync-primary-strong); border-color: var(--sync-primary-strong); color: #fff; }
        .audit-filter-btn select { appearance: none; border: 0; background: transparent; color: #fff; font: inherit; font-weight: 600; cursor: pointer; outline: none; padding-right: 2px; max-width: 190px; }
        .audit-filter-btn select option { color: #0f172a; background: #fff; }
        .audit-filter-btn .audit-filter-caret { pointer-events: none; opacity: .85; }
        .audit-date-panel { border: 1px solid var(--sync-border); border-radius: 8px; box-shadow: 0 12px 30px rgba(15, 23, 42, .18); padding: 14px; min-width: 250px; }
        .audit-date-panel .audit-date-label { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--sync-muted); margin-bottom: 4px; display: block; }
        .audit-table-card { padding: 0; overflow: hidden; }
        .audit-table-card table { table-layout: fixed; min-width: 1040px; }
        .audit-table-card .audit-details { min-width: 0; max-width: none; }
        .audit-table-card .audit-detail-text { max-width: 100%; overflow-wrap: anywhere; }
        .audit-table-card .audit-action-pill { white-space: normal; overflow-wrap: anywhere; }
        .audit-filter-bar { margin-bottom: 18px; }
        .audit-filter-form { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .audit-search { flex: 1 1 230px; max-width: 330px; }
        .audit-filter-btn { flex: 0 0 auto; }
        .audit-filter-btn select { width: 150px; max-width: 150px; }
        .audit-filter-btn--action select { width: 185px; max-width: 185px; }
        .audit-origin-trigger { border: 0; background: transparent; color: #fff; font: inherit; font-size: 12px; font-weight: 600; cursor: pointer; padding: 0; }
        .audit-origin-menu { min-width: 190px; max-height: 300px; overflow-y: auto; border: 1px solid var(--sync-border); border-radius: 8px; }
        .audit-entity { color: var(--sync-text); }
        .audit-user-cell { display: flex; align-items: center; gap: 10px; min-width: 170px; }
        .audit-avatar { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; flex: 0 0 auto; border-radius: 50%; background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 700; }
        .audit-user-name { font-size: 12.5px; font-weight: 700; color: var(--sync-text); line-height: 1.25; }
        .audit-user-role { font-size: 9.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--sync-muted); }
        .audit-action-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; border: 1px solid currentColor; font-size: 9.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; white-space: nowrap; }
        .audit-action-pill--info { color: #0284c7; background: #e0f2fe; }
        .audit-action-pill--success { color: #15803d; background: #dcfce7; }
        .audit-action-pill--warning { color: #b45309; background: #fef3c7; }
        .audit-action-pill--danger { color: #b91c1c; background: #fee2e2; }
        .audit-action-pill--neutral { color: #64748b; background: #f1f5f9; }
        .audit-action-pill .audit-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
        .audit-detail-text { font-size: 12px; color: var(--sync-text); }
        .audit-detail-text .text-muted { color: var(--sync-muted) !important; }
        .audit-ip-chip { display: inline-block; padding: 2px 7px; border-radius: 5px; background: #f1f5f9; border: 1px solid #e5e7eb; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; color: #475569; }
        .audit-time-main { font-size: 12px; font-weight: 600; color: var(--sync-text); white-space: nowrap; }
        .audit-time-note { font-size: 11px; color: var(--sync-muted); white-space: nowrap; }
        html[data-bs-theme="dark"] .audit-avatar { background: #1b2740; color: #cbd5e1; }
        html[data-bs-theme="dark"] .audit-ip-chip { background: #0f1a2b; border-color: #334155; color: #cbd5e1; }
        html[data-bs-theme="dark"] .audit-action-pill--success { background: #14351f; }
        html[data-bs-theme="dark"] .audit-action-pill--danger { background: #3b1418; }
        html[data-bs-theme="dark"] .audit-action-pill--warning { background: #3a2a0b; }
        html[data-bs-theme="dark"] .audit-action-pill--info { background: #0d2b3e; }
        html[data-bs-theme="dark"] .audit-action-pill--neutral { background: #1b2740; }
        @media (max-width: 767.98px) {
            .audit-toolbar-actions { width: 100%; }
            .audit-search { width: 100%; }
            .audit-filter-btn { flex: 1 1 auto; justify-content: center; }
        }
    </style>

    <header class="audit-toolbar">
        <div>
            <h1 class="audit-toolbar-title">Audit &amp; Activity Logs</h1>
            <p class="audit-toolbar-subtitle">Track system activities and administrative actions.</p>
        </div>
        <div class="audit-toolbar-actions">
            <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    <div class="audit-filter-bar">
            <form method="GET" action="{{ route('audit.index') }}" class="audit-filter-form" id="auditFilterForm">
                <h2 class="visually-hidden">Search &amp; filter audit events</h2>
                <div class="audit-search">
                    <span class="audit-search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="search" class="sync-input" id="q" name="q" value="{{ $filters['q'] }}"
                           placeholder="Search user, action, entity, or IP..." aria-label="Search audit events">
                </div>

                <div class="dropdown">
                    <button class="audit-filter-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                        {{ $filters['created_from'] || $filters['created_to'] ? 'Date Screened' : 'Date Range' }}
                        <svg class="audit-filter-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="dropdown-menu audit-date-panel">
                        <div class="mb-2">
                            <label class="audit-date-label" for="created_from">From Date</label>
                            <input type="date" class="form-control form-control-sm" id="created_from" name="created_from"
                                   value="{{ $filters['created_from'] }}" data-audit-auto-submit>
                        </div>
                        <div class="mb-2">
                            <label class="audit-date-label" for="created_to">To Date</label>
                            <input type="date" class="form-control form-control-sm" id="created_to" name="created_to"
                                   value="{{ $filters['created_to'] }}" data-audit-auto-submit>
                        </div>
                        @if ($filters['created_from'] || $filters['created_to'])
                            <a class="btn btn-sm btn-light w-100 border-0" href="{{ route('audit.index', request()->except(['created_from', 'created_to'])) }}">Clear date range</a>
                        @endif
                    </div>
                </div>

                <div class="audit-filter-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></svg>
                    <select name="user_id" id="user_id" aria-label="Filter by user" data-audit-auto-submit>
                        <option value="">All Users</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected($filters['user_id'] === (string) $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    <svg class="audit-filter-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </div>

                <div class="audit-filter-btn audit-filter-btn--action">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16l-6 8v6l-4 2v-8Z"/></svg>
                    <select name="action" id="action" aria-label="Filter by action" data-audit-auto-submit>
                        <option value="">All Actions</option>
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
                    <svg class="audit-filter-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </div>

                <div class="audit-filter-btn audit-filter-btn--origin dropdown">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01"/><path d="M10 8h8"/><path d="M6 12h.01"/><path d="M10 12h8"/><path d="M6 16h.01"/><path d="M10 16h8"/></svg>
                    <button type="button" class="audit-origin-trigger" data-bs-toggle="dropdown" aria-expanded="false">
                        {{ $filters['origin'] !== '' ? substr($filters['origin'], 0, 8).'...' : 'All Origins' }}
                    </button>
                    <input type="hidden" name="origin" id="origin" value="{{ $filters['origin'] }}">
                    <ul class="dropdown-menu audit-origin-menu">
                        <li><button type="button" class="dropdown-item" data-origin-value="">All Origins</button></li>
                        @foreach ($origins as $origin)
                            <li>
                                <button type="button" class="dropdown-item" data-origin-value="{{ $origin }}">
                                    {{ substr($origin, 0, 8) }}...
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <svg class="audit-filter-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </div>

                @if ($hasFilters)
                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('audit.index') }}">Clear</a>
                @endif
            </form>
    </div>

    <section class="sync-card audit-table-card" aria-labelledby="audit-table-title">
        <h2 class="visually-hidden" id="audit-table-title">Audit records</h2>
        @if ($hasFilters)
            <div class="d-flex flex-wrap gap-2 px-3 pt-3">
                @foreach ($filterLabels as $label => $value)
                    <span class="audit-chip">{{ $label }}: <strong>{{ $value }}</strong></span>
                @endforeach
            </div>
        @endif

        @if ($logs->isEmpty())
            <div class="audit-empty">
                <div class="sync-empty-title">No audit entries match these filters.</div>
                <div class="sync-empty-note">Adjust the filters, or <a href="{{ route('audit.index') }}">clear all filters</a>.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Audit events matching the current filters</caption>
                    <colgroup>
                        <col style="width: 12%;">
                        <col style="width: 11%;">
                        <col style="width: 33%;">
                        <col style="width: 11%;">
                        <col style="width: 12%;">
                        <col style="width: 9%;">
                        <col style="width: 12%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Action</th>
                            <th scope="col">Details</th>
                            <th scope="col">Entity</th>
                            <th scope="col">Origin</th>
                            <th scope="col">IP Address</th>
                            <th scope="col">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            @php
                                $actorName = $actorNames[$log->id] ?? null;
                                $actionKey = strtolower((string) $log->action);
                                $actionTone = 'neutral';
                                if (str_contains($actionKey, 'fail') || str_contains($actionKey, 'delete') || str_contains($actionKey, 'reject') || str_contains($actionKey, 'revoke')) {
                                    $actionTone = 'danger';
                                } elseif (str_contains($actionKey, 'create') || str_contains($actionKey, 'login') || str_contains($actionKey, 'accept') || str_contains($actionKey, 'resolve') || str_contains($actionKey, 'publish')) {
                                    $actionTone = 'success';
                                } elseif (str_contains($actionKey, 'update') || str_contains($actionKey, 'edit') || str_contains($actionKey, 'sync') || str_contains($actionKey, 'export')) {
                                    $actionTone = 'info';
                                } elseif (str_contains($actionKey, 'archive') || str_contains($actionKey, 'revoke') || str_contains($actionKey, 'expire')) {
                                    $actionTone = 'warning';
                                }
                                $actorLabel = $actorName ?: ($log->actor_username ?: 'System');
                                $actorRole = $log->actor_role ? ($roleLabels[$log->actor_role] ?? $log->actor_role) : ($actorName ? 'User' : 'System');
                                $actorInitial = mb_strtoupper(mb_substr($actorLabel, 0, 1));
                            @endphp
                            <tr>
                                <td>
                                    <div class="audit-user-cell">
                                        <span class="audit-avatar" aria-hidden="true">{{ $actorInitial }}</span>
                                        <div>
                                            <div class="audit-user-name">{{ $actorLabel }}</div>
                                            <div class="audit-user-role">{{ $actorRole }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="audit-action-pill audit-action-pill--{{ $actionTone }}" title="{{ $log->action }}">
                                        <span class="audit-dot" aria-hidden="true"></span>
                                        {{ $actionLabels[$log->action] ?? $log->action }}
                                    </span>
                                </td>
                                <td class="audit-details">
                                    @if ($log->metadata)
                                        <div class="audit-detail-text">
                                            @include('partials.audit-metadata', ['metadata' => $log->metadata, 'names' => $beneficiaryNames[$log->id] ?? []])
                                        </div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                    <button type="button" class="sync-btn sync-btn--secondary sync-btn--small mt-2"
                                            data-bs-toggle="modal" data-bs-target="#auditDetails{{ $log->id }}">
                                        View details
                                    </button>
                                </td>
                                <td class="audit-entity">
                                    @if ($log->entity_type)
                                        <span class="fw-semibold">{{ $log->entity_type }}</span>
                                        @if ($log->entity_id) <span class="text-muted">#{{ $log->entity_id }}</span> @endif
                                    @else
                                        <span class="text-muted">—</span>
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
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><span class="audit-ip-chip">{{ $log->ip ?? '—' }}</span></td>
                                <td>
                                    <div class="audit-time-main">{{ ($log->occurred_at ?? $log->created_at)?->format('M d, Y') }}</div>
                                    <div class="audit-time-note">{{ ($log->occurred_at ?? $log->created_at)?->format('h:i A') }}</div>
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

    @push('scripts')
    <script>
    (function () {
        var form = document.getElementById('auditFilterForm');
        if (!form) return;
        form.querySelectorAll('[data-audit-auto-submit]').forEach(function (field) {
            field.addEventListener('change', function () { form.submit(); });
        });
        form.querySelectorAll('[data-origin-value]').forEach(function (option) {
            option.addEventListener('click', function () {
                var field = document.getElementById('origin');
                if (field) {
                    field.value = option.getAttribute('data-origin-value') || '';
                    form.submit();
                }
            });
        });

        var search = form.querySelector('#q');
        if (search) {
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    form.submit();
                }
            });
        }
    })();
    </script>
    @endpush

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
