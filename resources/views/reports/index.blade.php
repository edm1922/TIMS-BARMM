@extends('layouts.app')

@section('title', 'Reports')

@section('content')
@php
    $filterLabels = [];
    if ($filters['q'] !== '') {
        $filterLabels['Search'] = $filters['q'];
    }
    if ($filters['batch'] !== '') {
        $batchLabel = $filters['batch'] === 'unassigned'
            ? 'Unassigned'
            : (\App\Models\BeneficiaryBatch::query()->whereKey((int) $filters['batch'])->value('name') ?? $filters['batch']);
        $filterLabels['Batch'] = $batchLabel;
    }
    if (($filters['age_range'] ?? '') !== '') {
        $filterLabels['Age distribution'] = [
            'below18' => 'Below 18',
            '18-25' => '18-25',
            '26-31' => '26-31',
            '32-40' => '32-40',
            '41-50' => '41-50',
            '51-60' => '51-60',
            '61plus' => '61 and above',
            'unclassified' => 'Unclassified',
        ][$filters['age_range']] ?? $filters['age_range'];
    }
    if (($filters['sex'] ?? '') !== '') {
        $filterLabels['Sex'] = $filters['sex'];
    }
    if (($filters['civil_status'] ?? '') !== '') {
        $filterLabels['Civil status'] = $filters['civil_status'];
    }
    if ($filters['duplicate_status'] !== '') {
        $filterLabels['Duplicates'] = str_replace('_', ' ', $filters['duplicate_status']);
    }
    if ($filters['created_from']) {
        $filterLabels['Created from'] = $filters['created_from'];
    }
    if ($filters['created_to']) {
        $filterLabels['Created to'] = $filters['created_to'];
    }
    $filterLabels['Records'] = match ($filters['status']) {
        'missing' => 'Missing fields',
        'archived' => 'Archived',
        'all' => 'All',
        default => 'Active',
    };
    $hasFilters = count($filterLabels) > 1
        || $filters['status'] !== 'active'
        || $filters['q'] !== ''
        || $filters['batch'] !== ''
        || $filters['age_status'] !== ''
        || ($filters['age_range'] ?? '') !== ''
        || ($filters['sex'] ?? '') !== ''
        || ($filters['civil_status'] ?? '') !== ''
        || $filters['duplicate_status'] !== ''
        || $filters['created_from']
        || $filters['created_to'];

    $cards = [
        ['label' => 'Total records', 'value' => $summary['total'], 'link' => ['status' => 'all'], 'tone' => 'info', 'icon' => 'database'],
        ['label' => 'Active', 'value' => $summary['active'], 'link' => ['status' => 'active'], 'tone' => 'ok', 'icon' => 'check'],
        ['label' => 'Archived', 'value' => $summary['archived'], 'link' => ['status' => 'archived'], 'tone' => 'neutral', 'icon' => 'archive'],
        ['label' => 'Underage', 'value' => $summary['underage'], 'link' => ['status' => 'active', 'age_range' => 'below18'], 'tone' => 'warning', 'icon' => 'alert'],
        ['label' => 'Needs review (age)', 'value' => $summary['unknown_age'], 'link' => ['status' => 'active', 'age_range' => 'unclassified'], 'tone' => 'warning', 'icon' => 'calendar'],
        ['label' => 'Duplicate candidates', 'value' => $summary['duplicate_candidates'], 'link' => ['status' => 'all', 'duplicate_status' => 'candidate'], 'tone' => 'danger', 'icon' => 'copy'],
    ];

    $icons = [
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'alert' => '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
        'calendar' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    ];
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .report-summary { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        @media (max-width: 1199.98px) { .sync-console .report-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 767.98px) { .sync-console .report-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .sync-console a.sync-stat { text-decoration: none; color: inherit; }
        .sync-console a.sync-stat:hover { border-color: #cbd5e1; box-shadow: 0 4px 10px rgba(15, 23, 42, .07); }
        .sync-console a.sync-stat:focus-visible { outline: 2px solid var(--sync-primary); outline-offset: 2px; }
        .sync-console .sync-stat-icon { width: 34px; height: 34px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; background: #f1f5f9; color: #475569; margin-bottom: 4px; }
        .sync-console .sync-stat-icon--ok { background: var(--sync-success-soft); color: var(--sync-success); }
        .sync-console .sync-stat-icon--warning { background: var(--sync-warning-soft); color: var(--sync-warning); }
        .sync-console .sync-stat-icon--danger { background: var(--sync-danger-soft); color: var(--sync-danger); }
        .sync-console .sync-stat-icon--info { background: var(--sync-info-soft); color: var(--sync-info); }

        .sync-console .report-filter-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; padding: 18px 20px; }
        .sync-console .report-filter-grid .span-4 { grid-column: span 4; }
        .sync-console .report-filter-grid .span-3 { grid-column: span 3; }
        .sync-console .report-filter-grid .span-2 { grid-column: span 2; }
        @media (max-width: 991.98px) {
            .sync-console .report-filter-grid .span-4, .sync-console .report-filter-grid .span-3 { grid-column: span 6; }
            .sync-console .report-filter-grid .span-2 { grid-column: span 4; }
        }
        @media (max-width: 575.98px) {
            .sync-console .report-filter-grid { grid-template-columns: 1fr; padding: 16px; }
            .sync-console .report-filter-grid > * { grid-column: auto !important; }
        }
        .sync-console .report-search-wrap { position: relative; }
        .sync-console .report-search-wrap .sync-input { padding-left: 34px; }
        .sync-console .report-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--sync-muted); pointer-events: none; display: inline-flex; }
        .sync-console .report-status { display: flex; flex-wrap: wrap; gap: 6px; }
        .sync-console .report-status .form-check { margin: 0; padding: 6px 12px 6px 30px; border: 1px solid var(--sync-border); border-radius: 8px; background: #fff; min-height: 40px; display: flex; align-items: center; }
        .sync-console .report-status .form-check-input { margin-top: 0; }
        .sync-console .report-status .form-check:has(input:checked) { border-color: var(--sync-primary); background: #eff6ff; }
        .sync-console .report-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .sync-console .report-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .sync-console .report-id { display: block; max-width: 26ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; }
        .sync-console .report-name { font-weight: 600; font-size: 13px; color: var(--sync-primary); text-decoration: none; }
        .sync-console .report-name:hover { text-decoration: underline; }
        .sync-console .report-archived { background: #f8fafc; opacity: .82; }
        .sync-console .report-export-actions .sync-btn { gap: 6px; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Reports</h1>
            <p class="sync-page-subtitle">Beneficiary records, reporting statistics and exports for the current filter selection.</p>
            <div class="sync-meta">
                <span><strong>{{ number_format($beneficiaries->total()) }}</strong> {{ Str::plural('record', $beneficiaries->total()) }} match the current filters</span>
                @if ($beneficiaries->hasPages())
                    <span>Page {{ $beneficiaries->currentPage() }} of {{ $beneficiaries->lastPage() }}</span>
                @endif
            </div>
        </div>
        @can('export-data')
            <div class="sync-page-actions report-export-actions">
                <a class="sync-btn sync-btn--secondary"
                   href="{{ route('reports.export', array_merge($filters, ['format' => 'csv'])) }}">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Export CSV
                </a>
                <a class="sync-btn sync-btn--primary"
                   href="{{ route('reports.export', array_merge($filters, ['format' => 'xlsx'])) }}">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Export XLSX
                </a>
            </div>
        @endcan
    </header>

    <section class="sync-summary report-summary" aria-label="Reporting overview">
        @foreach ($cards as $card)
            <a class="sync-card sync-stat" href="{{ route('reports.index', $card['link']) }}">
                <span class="sync-stat-icon sync-stat-icon--{{ $card['tone'] }}" aria-hidden="true">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$card['icon']] !!}</svg>
                </span>
                <div class="sync-stat-label">{{ $card['label'] }}</div>
                <div class="sync-stat-value">{{ number_format($card['value']) }}</div>
            </a>
        @endforeach
    </section>

    <form method="GET" action="{{ route('reports.index') }}" class="sync-card mb-3">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title">Search &amp; filter beneficiaries</h2>
                <p class="sync-section-subtitle">Filters apply to the on-screen list and to both exports.</p>
            </div>
            @if ($hasFilters)
                <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('reports.index') }}">Clear filters</a>
            @endif
        </div>

        <div class="report-filter-grid">
            <div class="span-4">
                <label class="sync-kv-label d-block mb-1" for="q">Search</label>
                <div class="report-search-wrap">
                    <span class="report-search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="search" class="sync-input" id="q" name="q" value="{{ $filters['q'] }}"
                           placeholder="Search by beneficiary ID, name, ID number, barangay, or occupation...">
                </div>
            </div>

            <div class="span-3">
                <label class="sync-kv-label d-block mb-1" for="batch">Batch</label>
                <select class="sync-input" id="batch" name="batch">
                    <option value="">All batches</option>
                    <option value="unassigned" @selected($filters['batch'] === 'unassigned')>Unassigned</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" @selected($filters['batch'] === (string) $batch->id)>
                            {{ $batch->name }} ({{ $batch->memberCount() }}){{ $batch->isOpen() ? '' : ' - closed' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="age_range">Age distribution</label>
                <select class="sync-input" id="age_range" name="age_range">
                    <option value="">All ages</option>
                    @foreach ([
                        'below18' => 'Below 18',
                        '18-25' => '18-25 years',
                        '26-31' => '26-31 years',
                        '32-40' => '32-40 years',
                        '41-50' => '41-50 years',
                        '51-60' => '51-60 years',
                        '61plus' => '61 years and above',
                        'unclassified' => 'Unclassified',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['age_range'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="sex">Sex</label>
                <select class="sync-input" id="sex" name="sex">
                    <option value="">All</option>
                    <option value="MALE" @selected(($filters['sex'] ?? '') === 'MALE')>MALE</option>
                    <option value="FEMALE" @selected(($filters['sex'] ?? '') === 'FEMALE')>FEMALE</option>
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="civil_status">Civil status</label>
                <select class="sync-input" id="civil_status" name="civil_status">
                    <option value="">All</option>
                    @foreach ($civilStatuses as $statusOption)
                        <option value="{{ $statusOption }}" @selected(($filters['civil_status'] ?? '') === $statusOption)>{{ $statusOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="span-3">
                <label class="sync-kv-label d-block mb-1" for="duplicate_status">Duplicates</label>
                <select class="sync-input" id="duplicate_status" name="duplicate_status">
                    <option value="">All</option>
                    @foreach (['none' => 'None', 'candidate' => 'Candidate', 'confirmed_duplicate' => 'Confirmed', 'cleared' => 'Cleared'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['duplicate_status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="created_from">Created from</label>
                <input type="date" class="sync-input" id="created_from" name="created_from" value="{{ $filters['created_from'] }}">
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="created_to">Created to</label>
                <input type="date" class="sync-input" id="created_to" name="created_to" value="{{ $filters['created_to'] }}">
            </div>

            <div class="span-4">
                <span class="sync-kv-label d-block mb-1">Records</span>
                <div class="report-status" role="radiogroup" aria-label="Record status">
                    @foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All', 'missing' => 'Missing fields'] as $value => $label)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="status" id="report-status-{{ $value }}"
                                   value="{{ $value }}" @checked($filters['status'] === $value)>
                            <label class="form-check-label small" for="report-status-{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="span-4 d-flex align-items-end gap-2">
                <button class="sync-btn sync-btn--primary" type="submit">Filter</button>
                @if ($hasFilters)
                    <a class="sync-btn sync-btn--secondary" href="{{ route('reports.index') }}">Reset</a>
                @endif
            </div>
        </div>
    </form>

    <section class="sync-card" aria-labelledby="report-table-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="report-table-title">Beneficiary records</h2>
                <p class="sync-section-subtitle">
                    {{ number_format($beneficiaries->total()) }} matching {{ Str::plural('record', $beneficiaries->total()) }}
                    @if ($beneficiaries->hasPages())
                        - page {{ $beneficiaries->currentPage() }} of {{ $beneficiaries->lastPage() }}
                    @endif
                </p>
            </div>
            <div class="report-chips">
                @foreach ($filterLabels as $label => $value)
                    <span class="report-chip">{{ $label }}: <strong>{{ $value }}</strong></span>
                @endforeach
            </div>
        </div>

        @if ($beneficiaries->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No records match these filters</div>
                <div class="sync-empty-note">Try removing a filter, or <a href="{{ route('reports.index') }}">clear all filters</a>.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Beneficiary records matching the current filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">Beneficiary ID</th>
                            <th scope="col">Name</th>
                            <th scope="col">Batch</th>
                            <th scope="col" class="text-end">Age</th>
                            <th scope="col">Age status</th>
                            <th scope="col">Sex</th>
                            <th scope="col">Barangay / City</th>
                            <th scope="col">Duplicate</th>
                            <th scope="col">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($beneficiaries as $beneficiary)
                            <tr class="{{ $beneficiary->archived_at ? 'report-archived' : '' }}">
                                <td>
                                    <span class="report-id" title="{{ $beneficiary->beneficiary_code }}">{{ $beneficiary->beneficiary_code }}</span>
                                    @if ($beneficiary->archived_at)
                                        <span class="sync-pill sync-pill--neutral">Archived</span>
                                    @endif
                                </td>
                                <td>
                                    <a class="report-name" href="{{ route('beneficiaries.show', $beneficiary) }}">
                                        {{ $beneficiary->full_name }}
                                    </a>
                                </td>
                                <td>
                                    @if ($beneficiary->beneficiaryBatch)
                                        <a href="{{ route('beneficiary-batches.show', $beneficiary->beneficiaryBatch) }}" class="text-decoration-none">
                                            @include('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])
                                        </a>
                                    @else
                                        @include('partials.batch-badge', ['batch' => null])
                                    @endif
                                </td>
                                <td class="text-end sync-ops-num">{{ $beneficiary->computedAge() ?? '-' }}</td>
                                <td>@include('partials.age-badge', ['status' => $beneficiary->ageStatus()])</td>
                                <td style="font-size: 12.5px;">{{ $beneficiary->sex ?? '-' }}</td>
                                <td style="font-size: 12.5px;">{{ trim(($beneficiary->barangay ?? '-').' / '.($beneficiary->city_municipality ?? '-')) }}</td>
                                <td>@include('partials.duplicate-badge', ['status' => $beneficiary->duplicate_status, 'rule' => $beneficiary->duplicate_rule])</td>
                                <td class="sync-ops-when">{{ $beneficiary->created_at?->format('Y-m-d') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="sync-body--divided d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="sync-registry-count">
                    Showing {{ number_format($beneficiaries->firstItem() ?? 0) }}-{{ number_format($beneficiaries->lastItem() ?? 0) }}
                    of {{ number_format($beneficiaries->total()) }} - page {{ $beneficiaries->currentPage() }} of {{ $beneficiaries->lastPage() }}
                </span>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <form method="GET" action="{{ route('reports.index') }}" class="d-flex align-items-center gap-2">
                        @foreach ($filters as $key => $value)
                            @if ($value !== null && $value !== '' && $key !== 'per_page')
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <label class="sync-kv-label mb-0" for="per-page">Rows per page</label>
                        <select class="sync-input" id="per-page" name="per_page" style="min-width: 92px; min-height: 36px; padding: 4px 10px;" onchange="this.form.submit()">
                            @foreach ([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </form>
                    {{ $beneficiaries->links() }}
                </div>
            </div>
        @endif
    </section>
</div>

@push('scripts')
<script>
(function () {
    // Export buttons: disable after the first click so a slow report cannot be
    // requested twice; restore when the browser returns to the page.
    document.querySelectorAll('.report-export-actions a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (link.dataset.exporting === '1') {
                return;
            }
            link.dataset.exporting = '1';
            link.style.opacity = '.65';
            link.style.pointerEvents = 'none';
        });
    });

    window.addEventListener('pageshow', function () {
        document.querySelectorAll('.report-export-actions a').forEach(function (link) {
            delete link.dataset.exporting;
            link.style.opacity = '';
            link.style.pointerEvents = '';
        });
    });

    // Filter submit feedback (single submit, button label reflects progress).
    document.querySelectorAll('form[action="{{ route('reports.index') }}"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (button && !button.disabled) {
                button.disabled = true;
                button.textContent = 'Filtering...';
            }
        });
    });
})();
</script>
@endpush
@endsection
