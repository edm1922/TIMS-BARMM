@extends('layouts.app')

@section('title', 'Review inbox')

@section('content')
@php
    $icons = [
        'deletions' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'mappings' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'duplicates' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'conflicts' => '<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 21V9a9 9 0 0 0 9 9"/>',
        'other' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'check' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
    ];

    $categories = [
        ['filter' => 'Deletion approval', 'key' => 'deletions', 'title' => 'Deletion Approvals', 'icon' => $icons['deletions'], 'accent' => '#b91c1c', 'description' => 'Offline removal requests waiting for a decision.'],
        ['filter' => 'Batch mapping', 'key' => 'mappings', 'title' => 'Batch Mappings', 'icon' => $icons['mappings'], 'accent' => '#1E429B', 'description' => 'Records whose batch is unknown to central.'],
        ['filter' => 'Duplicate candidate', 'key' => 'duplicates', 'title' => 'Duplicate Candidates', 'icon' => $icons['duplicates'], 'accent' => '#92400e', 'description' => 'Held because they match existing registrations.'],
        ['filter' => 'Field conflict', 'key' => 'conflicts', 'title' => 'Field Conflicts', 'icon' => $icons['conflicts'], 'accent' => '#7c3aed', 'description' => 'Overlapping edits needing a resolution.'],
        ['filter' => 'other', 'key' => 'other', 'title' => 'Other Holds', 'icon' => $icons['other'], 'accent' => '#64748b', 'description' => 'Any other records waiting on a decision.'],
    ];

    $activeFilterTitle = collect($categories)->firstWhere('filter', $filters['kind'])['title'] ?? null;
@endphp

<div class="sync-console review-inbox">
    @include('partials.sync-console-styles')

    <style>
        .review-inbox a.sync-stat { text-decoration: none; color: inherit; }
        .review-inbox a.sync-stat:hover { border-color: #cbd5e1; box-shadow: 0 4px 10px rgba(15, 23, 42, .07); }
        .review-inbox a.sync-stat:focus-visible { outline: 2px solid var(--sync-primary); outline-offset: 2px; }
        .review-inbox a.sync-stat.is-active { border-color: var(--sync-primary); background: #f6f7fb; }
        .review-inbox .ri-category-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            background: color-mix(in srgb, var(--ri-accent) 12%, #ffffff);
            color: var(--ri-accent);
        }
        .review-inbox .ri-category-count {
            font-size: 22px;
            font-weight: 700;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            color: var(--sync-text);
        }
        .review-inbox .ri-empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--sync-success-soft);
            color: var(--sync-success);
            margin: 0 auto 12px;
        }
        .review-inbox .ri-kind-detail { font-size: 12px; color: var(--sync-muted); margin-top: 3px; max-width: 46ch; }
        .review-inbox .ri-record-cell { font-size: 13px; font-weight: 600; }
        .review-inbox .ri-primary-count { font-size: 30px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
        .review-inbox .ri-search { max-width: 240px; }
        @media (max-width: 575.98px) { .review-inbox .ri-search { max-width: none; width: 100%; } }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Review inbox</h1>
            <p class="sync-page-subtitle">
                Every record waiting for an administrator decision, gathered from upload history and conflict
                resolution into one queue. Each row opens the workspace that can decide it.
            </p>
            <div class="sync-meta">
                @if ($allClear)
                    <span class="sync-pill sync-pill--ok"><span class="sync-pill__dot" aria-hidden="true"></span>All clear</span>
                    <span>Nothing needs your attention right now.</span>
                @else
                    <span class="sync-pill sync-pill--warning"><span class="sync-pill__dot" aria-hidden="true"></span>{{ number_format($counts['total']) }} pending</span>
                    <span>Records are waiting for an administrator decision.</span>
                @endif
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-requests.index') }}">Upload history</a>
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-conflicts.index') }}">Conflicts &amp; mappings</a>
        </div>
    </header>

    <section class="sync-summary" aria-label="Pending decisions overview">
        <article class="sync-card sync-stat {{ $allClear ? 'sync-stat--ok' : 'sync-stat--warning' }}">
            <div class="sync-stat-label">Pending Decisions</div>
            <div class="sync-stat-value ri-primary-count">{{ number_format($counts['total']) }}</div>
            <div class="sync-stat-note">
                @if ($allClear)
                    Nothing needs your attention right now.
                @else
                    Records across all categories are waiting for a decision.
                @endif
            </div>
        </article>

        @foreach ($categories as $category)
            <a class="sync-card sync-stat {{ $filters['kind'] === $category['filter'] ? 'is-active' : '' }}"
               href="{{ route('review-inbox.index', array_filter(['kind' => $category['filter'], 'q' => $filters['q']])) }}"
               data-filter="{{ $category['filter'] }}"
               title="Show {{ strtolower($category['title']) }} in the decision queue">
                <span class="d-flex align-items-start justify-content-between w-100">
                    <span class="ri-category-icon" style="--ri-accent: {{ $category['accent'] }};">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $category['icon'] !!}</svg>
                    </span>
                    <span class="ri-category-count">{{ number_format($counts[$category['key']]) }}</span>
                </span>
                <span class="fw-semibold" style="font-size: 13px;">{{ $category['title'] }}</span>
                <span class="sync-stat-note">{{ $category['description'] }}</span>
            </a>
        @endforeach
    </section>

    <section class="sync-card" aria-labelledby="ri-queue-title">
        <div class="sync-section-head">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h2 class="sync-section-title" id="ri-queue-title">Decision Queue</h2>
                <span class="sync-pill sync-pill--neutral">{{ number_format($entries->total()) }}</span>
            </div>
            <form method="GET" action="{{ route('review-inbox.index') }}" class="d-flex align-items-center gap-2 flex-wrap">
                @if ($filters['kind'] !== '')
                    <input type="hidden" name="kind" value="{{ $filters['kind'] }}">
                @endif
                <input type="search" class="sync-input ri-search" id="ri-search" name="q"
                       value="{{ $filters['q'] }}" placeholder="Search records..." aria-label="Search pending decisions">
                <button class="sync-btn sync-btn--secondary sync-btn--small" type="submit">Search</button>
            </form>
        </div>

        @if ($filters['kind'] !== '' || $filters['q'] !== '')
            <div class="sync-body--divided d-flex align-items-center gap-2 flex-wrap" style="font-size: 12.5px;">
                <span class="sync-registry-count">
                    Showing {{ number_format($entries->total()) }} of {{ number_format($counts['total']) }} pending
                    @if ($activeFilterTitle)
                        in <strong>{{ $activeFilterTitle }}</strong>
                    @endif
                </span>
                <a class="sync-copy" href="{{ route('review-inbox.index') }}">Clear filters</a>
            </div>
        @endif

        @if ($allClear)
            <div class="sync-empty" id="ri-empty-state">
                <span class="ri-empty-icon d-inline-flex align-items-center justify-content-center">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">{!! $icons['check'] !!}</svg>
                </span>
                <div class="sync-empty-title">You're all caught up!</div>
                <div class="sync-empty-note">There are no records waiting for an administrator decision.</div>
            </div>
        @elseif ($entries->isEmpty())
            <div class="sync-empty" id="ri-no-match">
                <div class="sync-empty-title">No records match your filters</div>
                <div class="sync-empty-note">
                    {{ number_format($counts['total']) }} decisions are waiting in other categories.
                    <a href="{{ route('review-inbox.index') }}">Clear search and filters</a>
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table" id="ri-table">
                    <thead>
                        <tr>
                            <th scope="col">Decision needed</th>
                            <th scope="col">Record</th>
                            <th scope="col">Installation</th>
                            <th scope="col">Detected</th>
                            <th scope="col" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            @php
                                $kindPill = match ($entry['label']) {
                                    'Deletion approval' => 'sync-pill--danger',
                                    'Batch mapping' => 'sync-pill--info',
                                    'Duplicate candidate' => 'sync-pill--warning',
                                    'Field conflict' => 'sync-pill--warning',
                                    default => 'sync-pill--neutral',
                                };
                            @endphp
                            <tr data-kind="{{ $entry['label'] }}">
                                <td>
                                    <span class="sync-pill {{ $kindPill }}">{{ $entry['label'] }}</span>
                                    <div class="ri-kind-detail">{{ $entry['summary'] }}</div>
                                </td>
                                <td>
                                    <div class="ri-record-cell">{{ $entry['entity'] }} - {{ $entry['detail'] }}</div>
                                </td>
                                <td style="font-size: 12.5px;">{{ $entry['server'] }}</td>
                                <td class="sync-ops-when" title="{{ $entry['created'] }}">{{ $entry['created']?->diffForHumans() ?? '-' }}</td>
                                <td class="text-end">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small"
                                       style="{{ $entry['severity'] === 'danger' ? 'color: var(--sync-danger); border-color: #fecaca;' : '' }}"
                                       href="{{ route('review-inbox.show', $entry['key']) }}">Review</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($entries->hasPages())
                <div class="sync-body--divided">
                    {{ $entries->links() }}
                </div>
            @endif
        @endif
    </section>
</div>
@endsection
