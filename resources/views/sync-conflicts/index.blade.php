@extends('layouts.app')

@section('title', 'Conflict Resolution')

@section('content')
<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .sync-filter-grid { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 14px 20px; }
        .sync-console .sync-filter-grid .sync-field { flex: 0 1 190px; }
        .sync-console .sync-record-link { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; text-decoration: none; }
    </style>

    <header class="sync-page-head">
        <div>
            <div class="sync-breadcrumb mb-2">
                <a href="{{ route('sync-servers.index') }}">Offline Servers</a><span>/</span>Conflict Resolution
            </div>
            <h1 class="sync-page-title">Conflict Resolution</h1>
            <p class="sync-page-subtitle">Unresolved and resolved conflicts from central sync and this installation.</p>
            <div class="sync-meta">
                <span>{{ number_format($counts['open']) }} open</span>
                <span>{{ number_format($counts['resolved']) }} resolved</span>
                <span>{{ number_format($counts['server']) }} from offline servers</span>
                <span>{{ number_format($counts['local']) }} local</span>
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--primary" href="{{ route('review-inbox.index') }}">Review inbox</a>
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-servers.index') }}">Offline servers</a>
        </div>
    </header>

    @if (session('success'))
        <div class="sync-alerts"><div class="alert alert-success mb-0">{{ session('success') }}</div></div>
    @endif

    <section class="sync-summary" aria-label="Conflict overview">
        <article class="sync-card sync-stat sync-stat--danger">
            <div class="sync-stat-label">Open</div>
            <div class="sync-stat-value">{{ number_format($counts['open']) }}</div>
            <div class="sync-stat-note">Waiting for a decision in the review inbox.</div>
        </article>
        <article class="sync-card sync-stat sync-stat--ok">
            <div class="sync-stat-label">Resolved</div>
            <div class="sync-stat-value">{{ number_format($counts['resolved']) }}</div>
            <div class="sync-stat-note">Decisions recorded in the audit history.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">From offline servers</div>
            <div class="sync-stat-value">{{ number_format($counts['server']) }}</div>
            <div class="sync-stat-note">Conflicts raised by an installation upload.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">This installation</div>
            <div class="sync-stat-value">{{ number_format($counts['local']) }}</div>
            <div class="sync-stat-note">Local conflicts recorded on this central server.</div>
        </article>
    </section>

    <form method="GET" action="{{ route('sync-conflicts.index') }}" class="sync-card mb-3">
        <div class="sync-filter-grid">
            <div class="sync-field">
                <label class="sync-kv-label d-block mb-1" for="filter-status">Status</label>
                <select class="sync-input" id="filter-status" name="status" onchange="this.form.submit()">
                    <option value="open" @selected($filters['status'] === 'open')>Open</option>
                    <option value="resolved" @selected($filters['status'] === 'resolved')>Resolved</option>
                    <option value="all" @selected($filters['status'] === 'all')>All</option>
                </select>
            </div>
            <div class="sync-field">
                <label class="sync-kv-label d-block mb-1" for="filter-source">Source</label>
                <select class="sync-input" id="filter-source" name="source" onchange="this.form.submit()">
                    <option value="all" @selected($filters['source'] === 'all')>All sources</option>
                    <option value="server" @selected($filters['source'] === 'server')>Offline servers (central)</option>
                    <option value="local" @selected($filters['source'] === 'local')>This installation (local)</option>
                </select>
            </div>
            <div class="sync-field">
                <label class="sync-kv-label d-block mb-1" for="filter-entity-type">Entity type</label>
                <select class="sync-input" id="filter-entity-type" name="entity_type" onchange="this.form.submit()">
                    <option value="" @selected($filters['entity_type'] === '')>All types</option>
                    <option value="beneficiary" @selected($filters['entity_type'] === 'beneficiary')>Beneficiary</option>
                    <option value="beneficiary_batch" @selected($filters['entity_type'] === 'beneficiary_batch')>Batch</option>
                </select>
            </div>
            <div class="d-flex gap-2">
                <button class="sync-btn sync-btn--primary sync-btn--small" type="submit">Filter</button>
                <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('sync-conflicts.index') }}">Reset</a>
            </div>
        </div>
    </form>

    <section class="sync-card" aria-labelledby="sync-conflict-list-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-conflict-list-title">Conflict history</h2>
                <p class="sync-section-subtitle">Open conflicts link to the review inbox; resolved rows keep the full decision history.</p>
            </div>
            <span class="sync-pill sync-pill--neutral">{{ number_format($conflicts->total()) }} {{ Str::plural('conflict', $conflicts->total()) }}</span>
        </div>

        @if ($conflicts->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No conflicts match this filter</div>
                <div class="sync-empty-note">Try a different status, source or entity type.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Conflict</th>
                            <th scope="col">Source</th>
                            <th scope="col">Entity</th>
                            <th scope="col">Affected record</th>
                            <th scope="col">Status</th>
                            <th scope="col">Detected</th>
                            <th scope="col">Resolved by</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($conflicts as $conflict)
                            @php($link = $recordLinks[$conflict->entity_type.'|'.$conflict->entity_uuid] ?? null)
                            <tr>
                                <td><code class="sync-ops-uuid">{{ substr($conflict->conflict_uuid, 0, 8) }}&hellip;</code></td>
                                <td style="font-size: 12.5px;">
                                    @if ($conflict->offline_server_id)
                                        <span class="sync-pill sync-pill--info">Server</span>
                                        {{ $conflict->server?->name ?? 'Unknown server' }}
                                    @else
                                        <span class="sync-pill sync-pill--neutral">Local</span>
                                        This installation
                                    @endif
                                </td>
                                <td><span class="sync-pill sync-pill--neutral">{{ $conflict->entity_type }}</span></td>
                                <td>
                                    @if ($link)
                                        <a class="sync-record-link" href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                                    @else
                                        <code class="sync-ops-uuid">{{ substr($conflict->entity_uuid, 0, 8) }}&hellip;</code>
                                        <span class="text-muted small">(not available here)</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($conflict->status === 'open')
                                        <span class="sync-pill sync-pill--danger">Open</span>
                                    @else
                                        <span class="sync-pill sync-pill--ok">Resolved</span>
                                    @endif
                                </td>
                                <td class="sync-ops-when">{{ $conflict->created_at?->diffForHumans() }}</td>
                                <td style="font-size: 12.5px;">{{ $conflict->resolver?->name ?? '-' }}</td>
                                <td class="text-end">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small"
                                       href="{{ route('sync-conflicts.show', $conflict) }}">
                                        {{ $conflict->status === 'open' ? 'Review' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($conflicts->hasPages())
                <div class="sync-body--divided">{{ $conflicts->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
