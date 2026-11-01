@extends('layouts.app')

@section('title', 'Pending Sync Requests')

@section('content')
<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .sync-filter-grid { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 14px 20px; }
        .sync-console .sync-filter-grid .sync-field { flex: 0 1 220px; }
        .sync-console .sync-request-uuid { font-size: 11.5px; color: #334155; background: #f6f7fb; border: 1px solid #eef2f7; border-radius: 5px; padding: 2px 5px; }
    </style>

    <header class="sync-page-head">
        <div>
            <div class="sync-breadcrumb mb-2">
                <a href="{{ route('sync-servers.index') }}">Offline Servers</a><span>/</span>Upload history
            </div>
            <h1 class="sync-page-title">Pending Sync Requests</h1>
            <p class="sync-page-subtitle">
                Every Sync Now submission from an offline server. Records that could be applied safely were applied
                on receipt; duplicates, conflicts, and invalid records are decided in the Review inbox.
            </p>
            <div class="sync-meta">
                <span>{{ number_format($counts['pending']) }} awaiting review</span>
                <span>{{ number_format($counts['pending_records']) }} records awaiting a decision</span>
                <span>{{ number_format($counts['accepted']) }} accepted</span>
                <span>{{ number_format($counts['rejected']) }} rejected</span>
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

    @if ($errors->any())
        <div class="sync-alerts"><div class="alert alert-danger mb-0">{{ $errors->first() }}</div></div>
    @endif

    <section class="sync-summary" aria-label="Upload history overview">
        <article class="sync-card sync-stat sync-stat--warning">
            <div class="sync-stat-label">Requests awaiting review</div>
            <div class="sync-stat-value">{{ number_format($counts['pending']) }}</div>
            <div class="sync-stat-note">Uploads with records still waiting for a decision.</div>
        </article>
        <article class="sync-card sync-stat sync-stat--danger">
            <div class="sync-stat-label">Records awaiting a decision</div>
            <div class="sync-stat-value">{{ number_format($counts['pending_records']) }}</div>
            <div class="sync-stat-note">The same queue shown in the Review inbox.</div>
        </article>
        <article class="sync-card sync-stat sync-stat--ok">
            <div class="sync-stat-label">Accepted requests</div>
            <div class="sync-stat-value">{{ number_format($counts['accepted']) }}</div>
            <div class="sync-stat-note">Fully applied upload batches.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Rejected requests</div>
            <div class="sync-stat-value">{{ number_format($counts['rejected']) }}</div>
            <div class="sync-stat-note">Uploads whose records were rejected.</div>
        </article>
    </section>

    <form method="GET" action="{{ route('sync-requests.index') }}" class="sync-card mb-3">
        <div class="sync-filter-grid">
            <div class="sync-field">
                <label class="sync-kv-label d-block mb-1" for="filter-status">Status</label>
                <select class="sync-input" id="filter-status" name="status" onchange="this.form.submit()">
                    <option value="pending" @selected($filters['status'] === 'pending')>Awaiting review</option>
                    <option value="all" @selected($filters['status'] === 'all')>All requests</option>
                    <option value="{{ \App\Models\SyncRequest::STATUS_ACCEPTED }}" @selected($filters['status'] === \App\Models\SyncRequest::STATUS_ACCEPTED)>Accepted</option>
                    <option value="{{ \App\Models\SyncRequest::STATUS_PARTIALLY_ACCEPTED }}" @selected($filters['status'] === \App\Models\SyncRequest::STATUS_PARTIALLY_ACCEPTED)>Partially accepted</option>
                    <option value="{{ \App\Models\SyncRequest::STATUS_REJECTED }}" @selected($filters['status'] === \App\Models\SyncRequest::STATUS_REJECTED)>Rejected</option>
                </select>
            </div>
            <div class="d-flex gap-2">
                <button class="sync-btn sync-btn--primary sync-btn--small" type="submit">Filter</button>
                <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('sync-requests.index') }}">Reset</a>
            </div>
        </div>
    </form>

    <section class="sync-card" aria-labelledby="sync-requests-list-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="sync-requests-list-title">Upload batches</h2>
                <p class="sync-section-subtitle">Per-batch record outcomes; open decisions link to the Review inbox.</p>
            </div>
            <span class="sync-pill sync-pill--neutral">{{ number_format($syncRequests->total()) }} {{ Str::plural('request', $syncRequests->total()) }}</span>
        </div>

        @if ($syncRequests->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No sync requests match this filter</div>
                <div class="sync-empty-note">Try another status, or open the Review inbox for pending decisions.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <thead>
                        <tr>
                            <th scope="col">Request</th>
                            <th scope="col">Source server</th>
                            <th scope="col">Area</th>
                            <th scope="col">Submitted</th>
                            <th scope="col" class="text-end">Records</th>
                            <th scope="col" class="text-end">Awaiting</th>
                            <th scope="col" class="text-end">Duplicates</th>
                            <th scope="col" class="text-end">Conflicts</th>
                            <th scope="col" class="text-end">Invalid</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($syncRequests as $syncRequest)
                            @php($badge = match ($syncRequest->status) {
                                \App\Models\SyncRequest::STATUS_AWAITING_REVIEW => 'sync-pill--warning',
                                \App\Models\SyncRequest::STATUS_ACCEPTED => 'sync-pill--ok',
                                \App\Models\SyncRequest::STATUS_PARTIALLY_ACCEPTED => 'sync-pill--info',
                                \App\Models\SyncRequest::STATUS_REJECTED => 'sync-pill--neutral',
                                default => 'sync-pill--neutral',
                            })
                            <tr>
                                <td><span class="sync-request-uuid">{{ substr($syncRequest->request_uuid, 0, 8) }}&hellip;</span></td>
                                <td style="font-size: 12.5px;">
                                    {{ $syncRequest->server?->name ?? 'Unknown server' }}
                                    @if ($syncRequest->submitter)
                                        <div class="text-muted">by {{ $syncRequest->submitter->name }}</div>
                                    @endif
                                </td>
                                <td style="font-size: 12px;" class="text-muted">
                                    @if ($syncRequest->server && $syncRequest->server->scopes->isNotEmpty())
                                        {{ $syncRequest->server->scopes->pluck('location_code')->join(', ') }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="sync-ops-when">{{ $syncRequest->submitted_at?->diffForHumans() ?? $syncRequest->created_at?->diffForHumans() }}</td>
                                <td class="text-end sync-ops-num">{{ number_format($syncRequest->total_operations) }}</td>
                                <td class="text-end sync-ops-num {{ $syncRequest->pending_count > 0 ? 'fw-semibold' : '' }}" @if ($syncRequest->pending_count > 0) style="color: var(--sync-warning);" @endif>
                                    {{ number_format($syncRequest->pending_count) }}
                                </td>
                                <td class="text-end sync-ops-num">{{ number_format($syncRequest->duplicate_count) }}</td>
                                <td class="text-end sync-ops-num">{{ number_format($syncRequest->conflict_count) }}</td>
                                <td class="text-end sync-ops-num">{{ number_format($syncRequest->validation_error_count) }}</td>
                                <td><span class="sync-pill {{ $badge }}">{{ $syncRequest->displayStatus() }}</span></td>
                                <td class="text-end">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('sync-requests.show', $syncRequest) }}">
                                        {{ $syncRequest->needsReview() ? 'Review' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($syncRequests->hasPages())
                <div class="sync-body--divided">{{ $syncRequests->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
