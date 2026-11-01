@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    use Illuminate\Support\Str;

    $isAdmin = auth()->user()->isAdmin();
    $total = $assignment['total'];
    $assigned = $assignment['assigned'];
    $unassigned = $assignment['unassigned'];

    $formatPercent = fn (?float $percent): string => $percent === null
        ? '—'
        : rtrim(rtrim(number_format($percent, 1), '0'), '.') . '%';

    $genderTotal = $demographics['gender'] ? array_sum(array_column($demographics['gender'], 'count')) : 0;
    $ageTotal = $demographics['age'] ? array_sum(array_column($demographics['age'], 'count')) : 0;
    $maritalTotal = $demographics['marital'] ? array_sum(array_column($demographics['marital'], 'count')) : 0;

    $statusMeta = [
        'applied' => ['label' => 'Applied', 'class' => 'status-badge-success'],
        'previewed' => ['label' => 'Previewed', 'class' => 'status-badge-info'],
        'uploaded' => ['label' => 'Uploaded', 'class' => 'status-badge-neutral'],
        'failed' => ['label' => 'Failed', 'class' => 'status-badge-danger'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'status-badge-warning'],
    ];
@endphp

<div class="dashboard-shell">
    {{-- Header --}}
    <div class="dashboard-header">
        <h1 class="dashboard-header-title">Dashboard</h1>
        <div class="dashboard-header-subtitle">TUPAD INFORMATION MANAGEMENT SYSTEM OF BARMM</div>
        <p class="dashboard-header-description">
            Overview of beneficiary records, profiling batches, demographic distribution, and recent import activities.
        </p>
    </div>

    {{-- Row 1: primary KPI + batch summary cards --}}
    <div class="row g-3 align-items-stretch">
        <div class="col-12 col-xl-6">
            <div class="card kpi-hero h-100">
                <div class="kpi-hero-body">
                    <span class="kpi-hero-icon">
                        <svg width="24" height="24" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
                        </svg>
                    </span>
                    <div class="kpi-hero-text">
                        <div class="kpi-hero-label">Total beneficiaries</div>
                        <div class="kpi-hero-meta">Active records currently registered</div>
                        <div class="kpi-hero-links">
                            <a class="kpi-hero-link" href="{{ route('beneficiaries.index') }}">Browse all beneficiaries</a>
                            <span class="kpi-hero-dot" aria-hidden="true">·</span>
                            <a class="kpi-hero-link subtle" href="{{ route('beneficiaries.index', ['batch' => 'unassigned']) }}">
                                {{ number_format($unassigned) }} unassigned
                            </a>
                            <span class="kpi-hero-dot" aria-hidden="true">·</span>
                            <a class="kpi-hero-link subtle" href="{{ route('beneficiaries.index', ['status' => 'archived']) }}">
                                {{ number_format($counts['archived']) }} archived
                            </a>
                        </div>
                    </div>
                    <div class="kpi-hero-figure">
                        <div class="kpi-hero-value">{{ number_format($total) }}</div>
                        <div class="kpi-hero-caption">Active</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="card cohort-card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-start justify-content-between">
                        <span class="cohort-icon">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                <path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2"/>
                                <path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1z"/>
                            </svg>
                        </span>
                        <span class="cohort-count">{{ number_format($counts['active_batches']) }}</span>
                    </div>
                    <div class="cohort-label">Open batches</div>
                    <div class="cohort-meta">Accepting beneficiary assignments</div>
                    @if (Route::has('beneficiary-batches.index'))
                        <a class="cohort-link" href="{{ route('beneficiary-batches.index') }}">Manage batches</a>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="card cohort-card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex align-items-start justify-content-between">
                        <span class="cohort-icon cohort-icon-neutral">
                            <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                <path d="M2 2.5a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5m0 4a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5m0 4a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5m0 4a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5"/>
                            </svg>
                        </span>
                        <span class="cohort-count">{{ number_format($counts['total_batches']) }}</span>
                    </div>
                    <div class="cohort-label">Total batches</div>
                    <div class="cohort-meta">Open and closed profiling batches</div>
                    @if (Route::has('beneficiary-batches.index'))
                        <a class="cohort-link" href="{{ route('beneficiary-batches.index') }}">View all batches</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Row 2: analytics - registration trend + year-based batch distribution --}}
    @php
        $unifiedYears = array_values(array_unique(array_merge(
            array_map('intval', $registrationTrend['years']),
            array_map('intval', $batchYearData['years']),
        )));
        rsort($unifiedYears);
    @endphp
    <div class="d-flex justify-content-end align-items-center gap-2 mb-2 dashboard-year-toolbar">
        <label class="form-label small mb-0" for="dashboard-year">Year</label>
        <select class="form-select form-select-sm w-auto" id="dashboard-year">
            @foreach ($unifiedYears as $year)
                <option value="{{ $year }}" @selected($registrationTrend['current'] === $year)>{{ $year }}</option>
            @endforeach
            <option value="all">All years</option>
        </select>
    </div>
    <div class="row g-3 align-items-stretch mt-0">
        <div class="col-12 col-xl-7">
            <div class="card panel-card h-100 registration-trend" data-registration-trend="{{ json_encode($registrationTrend) }}">
                <div class="panel-card-header flex-wrap gap-2">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Beneficiary Registration Trend</h2>
                        <div class="panel-card-subtitle">Monthly beneficiary registration activity for the selected year.</div>
                    </div>
                </div>
                <div class="panel-card-body">
                    @php
                        $trendYear = $registrationTrend['current'];
                        $trendMonths = $registrationTrend['months'][$trendYear] ?? [];
                        $trendMax = max(1, $trendMonths === [] ? 0 : max($trendMonths));
                    @endphp
                    <div data-trend-chart>
                        <div class="chart-canvas-wrap">
                            <canvas id="registrationTrendChart" role="img"
                                    aria-label="Monthly beneficiary registrations in {{ $trendYear }}"></canvas>
                        </div>
                    </div>
                    <div class="year-chart-footer">
                        <a data-trend-link href="{{ route('beneficiaries.index', ['registered_year' => $trendYear]) }}">View beneficiaries registered in {{ $trendYear }}</a>
                        @if ($registrationTrend['unclassified'] > 0)
                            <span aria-hidden="true">·</span>
                            <a href="{{ route('beneficiaries.index', ['registered_year' => 'unclassified']) }}">
                                {{ number_format($registrationTrend['unclassified']) }} without a registration date
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card panel-card h-100 batch-distribution-card" data-batch-data="{{ json_encode($batchYearData) }}">
                <div class="panel-card-header flex-wrap gap-2">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Year-Based Batch Distribution</h2>
                        <div class="panel-card-subtitle">Beneficiary distribution across batches for the selected year.</div>
                    </div>
                    <span class="sync-pill sync-pill--neutral" data-batch-count>
                        {{ number_format($batchGroup['batch_count'] ?? 0) }} {{ Str::plural('batch', $batchGroup['batch_count'] ?? 0) }}
                    </span>
                </div>
                <div class="panel-card-body">
                    @php
                        $batchYear = $batchYearData['default'];
                        $batchGroup = $batchYear !== null ? ($batchYearData['by_year'][(string) $batchYear] ?? null) : null;
                    @endphp
                    <div class="batch-chart-wrap">
                        <canvas id="batchDistributionChart" role="img"
                                aria-label="Beneficiary batch distribution for the selected year"></canvas>
                        <div class="batch-chart-empty d-none" data-batch-empty>No batches recorded for this selection.</div>
                    </div>
                    <ul class="batch-chart-fallback visually-hidden" data-batch-fallback>
                        @if ($batchGroup !== null)
                            @foreach ($batchGroup['batches'] as $row)
                                <li>
                                    <a href="{{ route('beneficiaries.index', ['batch' => $row['id']]) }}">{{ $row['name'] }}</a>
                                    — {{ number_format($row['count']) }} {{ Str::plural('beneficiary', $row['count']) }}
                                </li>
                            @endforeach
                        @endif
                    </ul>
                    <div class="year-chart-footer" id="batch-dist-footer">
                        <span data-batch-footnote>
                            @if ($batchYear !== null && $batchGroup !== null)
                                {{ number_format($batchGroup['total']) }} active beneficiaries across
                                {{ number_format($batchGroup['batch_count']) }} {{ Str::plural('batch', $batchGroup['batch_count']) }} in {{ $batchYear }}.
                            @endif
                        </span>
                        @if (Route::has('beneficiary-batches.index'))
                            <span aria-hidden="true">·</span>
                            <a href="{{ route('beneficiary-batches.index') }}">View batch management</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 2b: batch overview --}}
    <div class="row g-3 mt-0">
        <div class="col-12">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Batch overview</h2>
                        <div class="panel-card-subtitle">Open and closed release batches</div>
                    </div>
                    @if (Route::has('beneficiary-batches.index'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('beneficiary-batches.index') }}">Manage</a>
                    @endif
                </div>
                <div class="panel-card-body batch-panel">
                    <div class="batch-summary">
                        <div class="batch-summary-figure">
                            <div class="batch-summary-value">{{ number_format($counts['active_batches']) }}</div>
                            <div class="batch-summary-label">Open batches</div>
                        </div>
                        <div class="batch-summary-meta">
                            <span class="batch-summary-total">{{ number_format($counts['total_batches']) }}</span>
                            <span class="batch-summary-total-label">{{ Str::plural('batch', $counts['total_batches']) }} in total</span>
                        </div>
                    </div>
                    <ul class="batch-list">
                        @forelse ($batches as $batch)
                            <li class="batch-list-item {{ $batch->isOpen() ? 'is-open' : '' }}">
                                <a class="batch-list-link" href="{{ route('beneficiary-batches.show', $batch) }}">
                                    <span class="batch-list-main">
                                        <span class="batch-list-name">{{ $batch->name }}</span>
                                        <span class="batch-list-meta">
                                            {{ number_format($batch->memberCount()) }} {{ Str::plural('member', $batch->memberCount()) }}
                                            @if ($batch->updated_at !== null)
                                                · updated {{ $batch->updated_at->diffForHumans() }}
                                            @endif
                                        </span>
                                    </span>
                                    <span class="status-badge {{ $batch->isOpen() ? 'status-badge-info' : 'status-badge-neutral' }}">
                                        {{ $batch->isOpen() ? 'Open' : 'Closed' }}
                                    </span>
                                </a>
                            </li>
                        @empty
                            <li class="batch-list-empty">
                                No batches yet.
                                @if ($isAdmin && Route::has('beneficiary-batches.index'))
                                    <a href="{{ route('beneficiary-batches.index') }}">Create one to group beneficiaries for a payout round.</a>
                                @endif
                            </li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 3: beneficiary demographics --}}
    <div class="row g-3 mt-0">
        <div class="col-12 col-lg-6 col-xl-3">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Gender distribution</h2>
                        <div class="panel-card-subtitle">Active beneficiaries by sex</div>
                    </div>
                </div>
                <div class="panel-card-body">
                    <div class="demo-segmented" role="img" aria-label="Gender distribution bar">
                        @foreach ($demographics['gender'] as $item)
                            @if ($item['count'] > 0)
                                <span class="demo-segment" style="width: {{ $item['percent'] ?? 0 }}%; background-color: {{ $item['color'] }};" title="{{ $item['label'] }}: {{ number_format($item['count']) }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <ul class="demo-legend">
                        @foreach ($demographics['gender'] as $item)
                            <li class="demo-legend-item">
                                @if ($item['filter'])
                                    <a class="demo-legend-link" href="{{ route('beneficiaries.index', ['sex' => $item['filter']]) }}" title="View {{ strtolower($item['label']) }} beneficiaries">
                                        <span class="demo-dot" style="background-color: {{ $item['color'] }};" aria-hidden="true"></span>
                                        <span class="demo-legend-label">{{ $item['label'] }}</span>
                                        <span class="demo-legend-count">{{ number_format($item['count']) }}</span>
                                        <span class="demo-legend-percent">{{ $formatPercent($item['percent']) }}</span>
                                    </a>
                                @else
                                    <span class="demo-legend-link is-static">
                                        <span class="demo-dot" style="background-color: {{ $item['color'] }};" aria-hidden="true"></span>
                                        <span class="demo-legend-label">{{ $item['label'] }}</span>
                                        <span class="demo-legend-count">{{ number_format($item['count']) }}</span>
                                        <span class="demo-legend-percent">{{ $formatPercent($item['percent']) }}</span>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="demo-footnote">Total: {{ number_format($genderTotal) }} active beneficiaries</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-12 col-xl-5">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Age distribution</h2>
                        <div class="panel-card-subtitle">Calculated from birthdates</div>
                    </div>
                </div>
                <div class="panel-card-body">
                    <ul class="age-bars">
                        @foreach ($demographics['age'] as $item)
                            <li class="age-bar-item">
                                <a class="age-bar-link" href="{{ route('beneficiaries.index', ['age_range' => $item['filter']]) }}" title="View beneficiaries {{ strtolower($item['label']) }}">
                                    <span class="age-bar-label">{{ $item['label'] }}</span>
                                    <span class="age-bar-track">
                                        <span class="age-bar-fill" style="width: {{ $item['percent'] ?? 0 }}%;"></span>
                                    </span>
                                    <span class="age-bar-count">{{ number_format($item['count']) }}</span>
                                    <span class="age-bar-percent">{{ $formatPercent($item['percent']) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="demo-footnote">Total: {{ number_format($ageTotal) }} active beneficiaries</div>
                    <div class="demo-footnote demo-checks">
                        <span>Data checks:</span>
                        <a href="{{ route('beneficiaries.index', ['age_range' => 'below18']) }}">{{ number_format($counts['underage']) }} underage</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ route('beneficiaries.index', ['age_range' => 'unclassified']) }}">{{ number_format($counts['unknown_age']) }} missing birthdate</a>
                        <span aria-hidden="true">·</span>
                        <a href="{{ route('beneficiaries.index', ['status' => 'missing']) }}">Missing fields</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6 col-xl-4">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Marital status</h2>
                        <div class="panel-card-subtitle">Active beneficiaries by civil status</div>
                    </div>
                </div>
                <div class="panel-card-body">
                    <div class="demo-segmented" role="img" aria-label="Marital status distribution bar">
                        @foreach ($demographics['marital'] as $item)
                            @if ($item['count'] > 0)
                                <span class="demo-segment" style="width: {{ $item['percent'] ?? 0 }}%; background-color: {{ $item['color'] }};" title="{{ $item['label'] }}: {{ number_format($item['count']) }}"></span>
                            @endif
                        @endforeach
                    </div>
                    <ul class="demo-legend">
                        @foreach ($demographics['marital'] as $item)
                            <li class="demo-legend-item">
                                @if ($item['filter'])
                                    <a class="demo-legend-link" href="{{ route('beneficiaries.index', ['civil_status' => $item['filter']]) }}" title="View beneficiaries with status {{ strtolower($item['label']) }}">
                                        <span class="demo-dot" style="background-color: {{ $item['color'] }};" aria-hidden="true"></span>
                                        <span class="demo-legend-label">{{ $item['label'] }}</span>
                                        <span class="demo-legend-count">{{ number_format($item['count']) }}</span>
                                        <span class="demo-legend-percent">{{ $formatPercent($item['percent']) }}</span>
                                    </a>
                                @else
                                    <span class="demo-legend-link is-static">
                                        <span class="demo-dot" style="background-color: {{ $item['color'] }};" aria-hidden="true"></span>
                                        <span class="demo-legend-label">{{ $item['label'] }}</span>
                                        <span class="demo-legend-count">{{ number_format($item['count']) }}</span>
                                        <span class="demo-legend-percent">{{ $formatPercent($item['percent']) }}</span>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="demo-footnote">Total: {{ number_format($maritalTotal) }} active beneficiaries</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 4: recent imports + quick actions --}}
    <div class="row g-3 align-items-stretch mt-0">
        <div class="col-12 col-xl-8">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Recent import batches</h2>
                        <div class="panel-card-subtitle">Latest Excel imports and their outcomes</div>
                    </div>
                    @if (Route::has('imports.index'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('imports.index') }}">View all</a>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table panel-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Batch</th>
                                <th>File</th>
                                <th>Status</th>
                                <th class="text-end">Rows read</th>
                                <th class="text-end">New</th>
                                <th class="text-end">Updated</th>
                                <th class="text-end">Needs review</th>
                                <th>Target batch</th>
                                <th>Uploaded by</th>
                                <th>When</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentBatches as $batch)
                                @php
                                    $outcomeCounts = $batch->outcome_counts ?? ['new' => 0, 'update' => 0, 'review' => 0, 'invalid' => 0];
                                    $status = $statusMeta[$batch->status] ?? ['label' => str_replace('_', ' ', $batch->status), 'class' => 'status-badge-neutral'];
                                @endphp
                                <tr>
                                    <td class="text-nowrap fw-medium">#{{ $batch->id }}</td>
                                    <td class="text-truncate" style="max-width: 180px;" title="{{ $batch->original_filename }}">{{ $batch->original_filename }}</td>
                                    <td class="text-nowrap"><span class="status-badge {{ $status['class'] }}">{{ $status['label'] }}</span></td>
                                    <td class="text-end">{{ number_format($batch->rows_read) }}</td>
                                    <td class="text-end">{{ number_format($outcomeCounts['new'] ?? 0) }}</td>
                                    <td class="text-end">{{ number_format($outcomeCounts['update'] ?? 0) }}</td>
                                    <td class="text-end {{ ($outcomeCounts['review'] ?? 0) > 0 ? 'text-warning-emphasis fw-medium' : '' }}">
                                        {{ number_format($outcomeCounts['review'] ?? 0) }}
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($batch->beneficiaryBatch)
                                            <a href="{{ route('beneficiary-batches.show', $batch->beneficiaryBatch) }}">{{ $batch->beneficiaryBatch->name }}</a>
                                        @else
                                            <span class="text-muted">Unassigned</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">{{ $batch->user?->name }}</td>
                                    <td class="text-muted small text-nowrap">{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="text-end">
                                        @if (Route::has('imports.show'))
                                            <a class="btn btn-sm btn-link text-decoration-none" href="{{ route('imports.show', $batch) }}">View details</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <div class="empty-state-icon">
                                                <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                    <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/>
                                                    <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708z"/>
                                                </svg>
                                            </div>
                                            <div class="empty-state-title">No imports yet</div>
                                            <div class="empty-state-text">Upload an Excel workbook to start importing beneficiaries.</div>
                                            @if (Route::has('imports.create'))
                                                <a class="btn btn-sm btn-primary mt-2" href="{{ route('imports.create') }}">Import Excel workbook</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Quick actions</h2>
                        <div class="panel-card-subtitle">Common workflows</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        @if (Route::has('beneficiaries.index'))
                            <a class="quick-action" href="{{ route('beneficiaries.index') }}">
                                <div class="quick-action-icon">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
                                    </svg>
                                </div>
                                <div class="quick-action-content">
                                    <div class="quick-action-title">Browse beneficiaries</div>
                                    <div class="quick-action-subtitle">Search, filter, and review records</div>
                                </div>
                            </a>
                        @endif
                        @if (Route::has('beneficiary-batches.index'))
                            <a class="quick-action {{ auth()->user()->can('create', \App\Models\BeneficiaryBatch::class) ? 'is-primary' : '' }}" href="{{ route('beneficiary-batches.index') }}">
                                <div class="quick-action-icon">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2"/>
                                        <path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1z"/>
                                    </svg>
                                </div>
                                <div class="quick-action-content">
                                    <div class="quick-action-title">{{ auth()->user()->can('create', \App\Models\BeneficiaryBatch::class) ? 'Create batch' : 'Manage batches' }}</div>
                                    <div class="quick-action-subtitle">Organize beneficiaries into release batches</div>
                                </div>
                            </a>
                        @endif
                        @if (Route::has('imports.create'))
                            <a class="quick-action" href="{{ route('imports.create') }}">
                                <div class="quick-action-icon">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/>
                                        <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708z"/>
                                    </svg>
                                </div>
                                <div class="quick-action-content">
                                    <div class="quick-action-title">Import Excel workbook</div>
                                    <div class="quick-action-subtitle">Preview, map, and apply imports</div>
                                </div>
                            </a>
                        @endif
                        @if (Route::has('reports.index'))
                            <a class="quick-action" href="{{ route('reports.index') }}">
                                <div class="quick-action-icon">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M4 11a1 1 0 1 1 2 0v1a1 1 0 1 1-2 0zm6-4a1 1 0 1 1 2 0v5a1 1 0 1 1-2 0zM7 9a1 1 0 1 1 2 0v3a1 1 0 1 1-2 0z"/>
                                        <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1z"/>
                                        <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0z"/>
                                    </svg>
                                </div>
                                <div class="quick-action-content">
                                    <div class="quick-action-title">View reports</div>
                                    <div class="quick-action-subtitle">Export beneficiary reports and summaries</div>
                                </div>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .dashboard-shell .dashboard-header-description {
        margin: .35rem 0 0;
        color: #64748b;
        font-size: .875rem;
        max-width: 60rem;
    }
    .dashboard-shell .cohort-card { border: 1px solid #e9ecef; }
    .dashboard-shell .cohort-icon {
        width: 34px; height: 34px; border-radius: 8px;
        display: inline-flex; align-items: center; justify-content: center;
        background: #eff6ff; color: #1E429B;
    }
    .dashboard-shell .cohort-icon-neutral { background: #f1f5f9; color: #64748b; }
    .dashboard-shell .cohort-count { font-size: 1.8rem; font-weight: 700; line-height: 1; letter-spacing: -.02em; }
    .dashboard-shell .cohort-label { margin-top: .65rem; font-weight: 600; font-size: .9rem; }
    .dashboard-shell .cohort-meta { color: #64748b; font-size: .78rem; margin-top: .15rem; }
    .dashboard-shell .cohort-link { margin-top: auto; padding-top: .75rem; font-size: .82rem; }

    .dashboard-shell .batch-list-item.is-open { border-left: 3px solid #0d6efd; }
    .dashboard-shell .batch-list-item { border-left: 3px solid transparent; }
    .dashboard-shell .batch-list-link { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .5rem .75rem; text-decoration: none; }
    .dashboard-shell .batch-list-main { display: flex; flex-direction: column; min-width: 0; }
    .dashboard-shell .batch-list-meta { color: #64748b; font-size: .78rem; }
    .dashboard-shell .donut-legend-more { display: block; padding: .35rem .75rem; font-size: .82rem; }

    .dashboard-shell .demo-segmented {
        display: flex; height: 10px; border-radius: 999px; overflow: hidden; background: #eef2f7; margin-bottom: .9rem;
    }
    .dashboard-shell .demo-segment { display: block; height: 100%; }
    .dashboard-shell .demo-legend { list-style: none; padding: 0; margin: 0; }
    .dashboard-shell .demo-legend-item + .demo-legend-item { margin-top: .2rem; }
    .dashboard-shell .demo-legend-link {
        display: flex; align-items: center; gap: .5rem; font-size: .82rem;
        color: #334155; text-decoration: none; padding: .2rem .25rem; border-radius: .35rem;
    }
    a.demo-legend-link:hover { background: #f1f5f9; }
    .dashboard-shell .demo-legend-link.is-static { cursor: default; }
    .dashboard-shell .demo-dot { width: .55rem; height: .55rem; border-radius: 50%; flex-shrink: 0; }
    .dashboard-shell .demo-legend-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashboard-shell .demo-legend-count { font-weight: 600; color: #0f172a; }
    .dashboard-shell .demo-legend-percent { color: #64748b; min-width: 3.2rem; text-align: right; }
    .dashboard-shell .demo-footnote { margin-top: .75rem; color: #64748b; font-size: .75rem; }

    .dashboard-shell .age-bars { list-style: none; padding: 0; margin: 0; }
    .dashboard-shell .age-bar-item + .age-bar-item { margin-top: .3rem; }
    .dashboard-shell .age-bar-link {
        display: grid; grid-template-columns: 8.5rem 1fr 3rem 3.4rem; align-items: center; gap: .6rem;
        font-size: .82rem; color: #334155; text-decoration: none; padding: .2rem .25rem; border-radius: .35rem;
    }
    .dashboard-shell a.age-bar-link:hover { background: #f1f5f9; }
    .dashboard-shell .age-bar-track { height: 8px; border-radius: 999px; background: #eef2f7; overflow: hidden; }
    .dashboard-shell .age-bar-fill { display: block; height: 100%; border-radius: 999px; background: #1E429B; }
    .dashboard-shell .age-bar-count { font-weight: 600; text-align: right; color: #0f172a; }
    .dashboard-shell .age-bar-percent { text-align: right; color: #64748b; }

    /* Beneficiaries by Year bar chart */
    .dashboard-shell .year-chart { padding: .5rem .25rem 0; }
    .dashboard-shell .year-chart-plot {
        position: relative;
        height: 240px;
        margin-left: 2.4rem;
        border-bottom: 1px solid #dbe2ea;
    }
    .dashboard-shell .year-gridline {
        position: absolute;
        left: 0; right: 0;
        border-top: 1px dashed #e9eef4;
        pointer-events: none;
    }
    .dashboard-shell .year-gridline:first-child { border-top-style: solid; }
    .dashboard-shell .year-grid-label {
        position: absolute;
        left: -2.4rem;
        top: -.55rem;
        width: 2rem;
        text-align: right;
        font-size: .7rem;
        color: #94a3b8;
    }
    .dashboard-shell .year-bars {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: flex-end;
        gap: .6rem;
        padding: 0 .75rem;
    }
    .dashboard-shell .year-bar {
        flex: 1 1 0;
        max-width: 6rem;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
        align-items: center;
        text-decoration: none;
        gap: .25rem;
        min-width: 1.6rem;
    }
    .dashboard-shell .year-bar-value { font-size: .72rem; font-weight: 600; color: #334155; }
    .dashboard-shell .year-bar-fill {
        display: block;
        width: 100%;
        border-radius: .35rem .35rem 0 0;
        background: #1E429B;
        transition: background-color .15s ease;
    }
    .dashboard-shell .year-bar:hover .year-bar-fill { background: #16357C; }
    .dashboard-shell .year-bar:focus-visible .year-bar-fill { outline: 2px solid #0d6efd; outline-offset: 2px; }
    .dashboard-shell .year-labels {
        display: flex;
        gap: .6rem;
        margin-left: 2.4rem;
        padding: .35rem .75rem 0;
    }
    .dashboard-shell .year-label {
        flex: 1 1 0;
        max-width: 6rem;
        min-width: 1.6rem;
        text-align: center;
        font-size: .75rem;
        color: #475569;
        font-variant-numeric: tabular-nums;
    }
    .dashboard-shell .year-chart-footer {
        display: flex; flex-wrap: wrap; gap: .4rem; align-items: baseline;
        padding: .75rem .25rem 0;
        color: #64748b; font-size: .75rem;
    }

    .dashboard-shell .batch-dist-year {
        font-size: .68rem;
        color: #94a3b8;
        flex-shrink: 0;
    }
    /* Batch distribution (by program year) */
    .dashboard-shell .batch-dist { list-style: none; padding: 0; margin: 0; }
    .dashboard-shell .batch-dist > li + li { margin-top: .2rem; }
    .dashboard-shell .batch-dist-row {
        display: grid;
        grid-template-columns: .6rem minmax(8rem, 1.1fr) minmax(4rem, 1.6fr) 3.2rem 3.4rem;
        align-items: center;
        gap: .6rem;
        padding: .35rem .3rem;
        border-radius: .35rem;
        text-decoration: none;
        color: #334155;
        font-size: .82rem;
    }
    .dashboard-shell .batch-dist-row:hover { background: #f1f5f9; }
    .dashboard-shell .batch-dist-swatch { width: .55rem; height: .55rem; border-radius: 50%; }
    .dashboard-shell .batch-dist-main { display: flex; align-items: center; gap: .4rem; min-width: 0; }
    .dashboard-shell .batch-dist-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashboard-shell .batch-dist-track { height: 8px; border-radius: 999px; background: #eef2f7; overflow: hidden; }
    .dashboard-shell .batch-dist-fill { display: block; height: 100%; border-radius: 999px; }
    .dashboard-shell .batch-dist-count { font-weight: 600; text-align: right; color: #0f172a; }
    .dashboard-shell .batch-dist-percent { text-align: right; color: #64748b; }
    @media (max-width: 575.98px) {
        .dashboard-shell .batch-dist-row { grid-template-columns: .6rem 1fr 3rem; }
        .dashboard-shell .batch-dist-track,
        .dashboard-shell .batch-dist-percent { display: none; }
    }

    /* Beneficiary Registration Trend card */
    .dashboard-shell .trend-summary {
        display: flex;
        align-items: baseline;
        gap: .45rem;
        padding: .25rem .25rem .75rem;
    }
    .dashboard-shell .trend-summary-value {
        font-size: 2.1rem;
        font-weight: 700;
        line-height: 1;
        letter-spacing: -.02em;
        color: #0f172a;
    }
    .dashboard-shell .trend-summary-label { color: #64748b; font-size: .85rem; }
    .dashboard-shell .year-bars-months { gap: .35rem; }
    .dashboard-shell .year-bars-months .year-bar { max-width: none; min-width: 0; }
    .dashboard-shell .year-labels-months { gap: .35rem; padding-top: .4rem; }
    .dashboard-shell .year-labels-months .year-label {
        max-width: none; min-width: 0; font-size: .66rem; letter-spacing: -.01em;
    }
    .dashboard-shell .year-bars-months .year-bar-value { font-size: .62rem; }
    .dashboard-shell .year-bar-fill.is-zero { background: #e5e7eb; min-height: 2px; }

    .dashboard-shell .chart-canvas-wrap,
    .dashboard-shell .batch-chart-wrap { position: relative; height: 280px; }
    .dashboard-shell .batch-chart-empty {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        color: #64748b; font-size: .85rem; text-align: center; padding: 1rem;
    }
    @media (max-width: 767.98px) {
        .dashboard-shell .chart-canvas-wrap,
        .dashboard-shell .batch-chart-wrap { height: 240px; }
    }
</style>

@push('scripts')
<script src="{{ asset('assets/vendor/chart.js/chart.umd.min.js') }}"></script>
<script>
(function () {
    'use strict';

    var card = document.querySelector('.batch-distribution-card');
    var canvas = document.getElementById('batchDistributionChart');

    if (!card || !canvas || typeof Chart === 'undefined') {
        return;
    }

    var data = {};

    try {
        data = JSON.parse(card.dataset.batchData || '{}');
    } catch (e) {
        data = {};
    }

    var select = document.getElementById('dashboard-year');
    var countEl = card.querySelector('[data-batch-count]');
    var footnote = card.querySelector('[data-batch-footnote]');
    var emptyEl = card.querySelector('[data-batch-empty]');
    var beneficiariesUrl = '{{ route('beneficiaries.index') }}';
    var chart = null;

    function fmt(value) {
        return Number(value || 0).toLocaleString('en-US');
    }

    function isDark() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function render(key) {
        var group = key === 'all'
            ? data.all
            : ((data.by_year || {})[key] || { total: 0, batch_count: 0, batches: [], shown: 0 });

        var rows = (group && group.batches) ? group.batches : [];

        if (chart) {
            chart.destroy();
            chart = null;
        }

        if (rows.length === 0) {
            canvas.classList.add('d-none');
            if (emptyEl) {
                emptyEl.classList.remove('d-none');
            }
        } else {
            canvas.classList.remove('d-none');
            if (emptyEl) {
                emptyEl.classList.add('d-none');
            }

            chart = new Chart(canvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: rows.map(function (row) { return row.name; }),
                    datasets: [{
                        data: rows.map(function (row) { return Number(row.count || 0); }),
                        backgroundColor: rows.map(function (row) { return row.color || '#1E429B'; }),
                        borderWidth: 2,
                        borderColor: isDark() ? '#111c2e' : '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    onClick: function (event, elements) {
                        if (elements.length === 0) {
                            return;
                        }
                        var row = rows[elements[0].index];
                        if (row) {
                            window.location.href = beneficiariesUrl + '?batch=' + encodeURIComponent(row.id);
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                font: { size: 11 },
                                color: '#64748b',
                                padding: 10
                            }
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function (context) {
                                    var total = context.dataset.data.reduce(function (sum, value) { return sum + Number(value); }, 0);
                                    var value = Number(context.parsed);
                                    var share = total > 0 ? Math.round(value / total * 1000) / 10 : 0;
                                    return ' ' + fmt(value) + ' beneficiaries (' + share + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        if (countEl) {
            var batchCount = group ? group.batch_count : 0;
            countEl.textContent = fmt(batchCount) + ' ' + (batchCount === 1 ? 'batch' : 'batches');
        }

        var fallback = card.querySelector('[data-batch-fallback]');
        if (fallback) {
            fallback.innerHTML = '';
            rows.forEach(function (row) {
                var item = document.createElement('li');
                var link = document.createElement('a');
                link.href = beneficiariesUrl + '?batch=' + encodeURIComponent(row.id);
                link.textContent = row.name;
                item.appendChild(link);
                item.appendChild(document.createTextNode(' — ' + fmt(row.count) + ' beneficiaries'));
                fallback.appendChild(item);
            });
        }

        if (footnote) {
            var batchWord = (group && group.batch_count === 1) ? 'batch' : 'batches';
            var scope = key === 'all'
                ? ((group && group.shown < group.batch_count) ? ' (top ' + fmt(group.shown) + ' shown)' : '')
                : ' in ' + key;

            footnote.textContent = fmt(group ? group.total : 0) + ' active beneficiaries across '
                + fmt(group ? group.batch_count : 0) + ' ' + batchWord + scope + '.';
        }
    }

    if (select) {
        select.addEventListener('change', function () {
            render(select.value);
        });

        render(select.value);
    }

    if (window.MutationObserver) {
        new MutationObserver(function () {
            if (select) {
                render(select.value);
            }
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }
})();
</script>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    var card = document.querySelector('.registration-trend');
    var canvas = document.getElementById('registrationTrendChart');

    if (!card || !canvas || typeof Chart === 'undefined') {
        return;
    }

    var data = {};

    try {
        data = JSON.parse(card.dataset.registrationTrend || '{}');
    } catch (e) {
        data = {};
    }

    var select = document.getElementById('dashboard-year');

    var linkEl = card.querySelector('[data-trend-link]');
    var beneficiariesUrl = '{{ route('beneficiaries.index') }}';
    var monthShort = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var monthFull = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var chart = null;

    function fmt(value) {
        return Number(value || 0).toLocaleString('en-US');
    }

    function isDark() {
        return document.documentElement.getAttribute('data-bs-theme') === 'dark';
    }

    function draw(labels, counts, links, tooltips) {
        if (chart) {
            chart.destroy();
            chart = null;
        }

        chart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Beneficiaries registered',
                    data: counts,
                    borderColor: '#1E429B',
                    backgroundColor: 'rgba(30, 66, 155, 0.10)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#1E429B',
                    pointBorderColor: isDark() ? '#111c2e' : '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onClick: function (event, elements) {
                    if (elements.length === 0) {
                        return;
                    }
                    var link = links[elements[0].index];
                    if (link) {
                        window.location.href = link;
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function (items) {
                                return tooltips[items[0].dataIndex] || '';
                            },
                            label: function (context) {
                                return fmt(context.parsed.y) + ' registered';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#94a3b8', font: { size: 11 } },
                        grid: { borderDash: [5, 5], color: isDark() ? 'rgba(148, 163, 184, .16)' : '#e5e7eb' }
                    },
                    x: {
                        ticks: { color: '#94a3b8', font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    function renderYear(year) {
        var months = (data.months || {})[year] || {};
        var counts = [];
        var tooltips = [];

        for (var month = 1; month <= 12; month++) {
            var count = Number(months[month] || 0);
            counts.push(count);
            tooltips.push(monthFull[month - 1] + ' ' + year + ': ' + fmt(count) + ' beneficiary' + (count === 1 ? '' : 's'));
        }

        var total = counts.reduce(function (sum, value) { return sum + value; }, 0);

        draw(monthShort, counts, counts.map(function () { return null; }), tooltips);

        if (linkEl) {
            linkEl.hidden = false;
            linkEl.href = beneficiariesUrl + '?registered_year=' + encodeURIComponent(year);
            linkEl.textContent = 'View beneficiaries registered in ' + year;
        }
    }

    function renderAllYears() {
        var years = Object.keys((data.annual || {})).map(Number).sort(function (a, b) { return a - b; });
        var counts = years.map(function (year) { return Number((data.annual || {})[year] || 0); });
        var labels = years.map(String);
        var links = years.map(function (year) { return beneficiariesUrl + '?registered_year=' + encodeURIComponent(year); });
        var tooltips = years.map(function (year, index) {
            return year + ': ' + fmt(counts[index]) + ' beneficiar' + (counts[index] === 1 ? 'y' : 'ies');
        });

        draw(labels, counts, links, tooltips);

        if (linkEl) {
            linkEl.hidden = true;
        }
    }

    function render() {
        if (!select) {
            return;
        }

        if (select.value === 'all') {
            renderAllYears();
        } else {
            renderYear(select.value);
        }
    }

    if (select) {
        select.addEventListener('change', render);
        render();
    }

    if (window.MutationObserver) {
        new MutationObserver(render).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-bs-theme']
        });
    }
})();
</script>
@endpush
@endsection
