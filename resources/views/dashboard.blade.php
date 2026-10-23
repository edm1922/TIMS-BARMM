@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    use Illuminate\Support\Str;

    $isAdmin = auth()->user()->isAdmin();
    $total = $assignment['total'];
    $assigned = $assignment['assigned'];
    $unassigned = $assignment['unassigned'];

    // Donut geometry: r = 48 inside a 120 x 120 viewBox.
    $circumference = round(2 * M_PI * 48, 2);

    $formatPercent = fn (?float $percent): string => $percent === null
        ? '—'
        : rtrim(rtrim(number_format($percent, 1), '0'), '.') . '%';
@endphp

<div class="dashboard-shell">
    <div class="dashboard-header">
        <h1 class="dashboard-header-title">Dashboard</h1>
        <div class="dashboard-header-subtitle">TUPAD INFORMATION MANAGEMENT SYSTEM OF BARMM</div>
    </div>

    {{-- Level 1: primary KPI --}}
    <div class="card kpi-hero">
        <div class="kpi-hero-body">
            <span class="kpi-hero-icon">
                <svg width="24" height="24" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/>
                </svg>
            </span>
            <div class="kpi-hero-text">
                <div class="kpi-hero-label">Total beneficiaries</div>
                <div class="kpi-hero-meta">Active records currently on the register</div>
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

    {{-- Level 2 + 3: batch distribution chart and batch overview --}}
    <div class="row g-3 align-items-stretch">
        <div class="col-12 col-xl-7">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Active batch distribution</h2>
                        <div class="panel-card-subtitle">Beneficiaries grouped per active release batch</div>
                    </div>
                    @if (Route::has('beneficiary-batches.index'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('beneficiary-batches.index') }}">All batches</a>
                    @endif
                </div>
                <div class="panel-card-body donut-panel">
                    <div class="donut-figure">
                        <div class="donut-wrap">
                            <svg class="donut" viewBox="0 0 120 120" role="img"
                                 aria-label="Active batch distribution chart showing {{ number_format($batchDistribution['total']) }} beneficiaries across {{ count($batchDistribution['batches']) }} active batches.">
                                <g transform="rotate(-90 60 60)">
                                    <circle class="donut-track" cx="60" cy="60" r="48" />
                                    @foreach ($batchDistribution['batches'] as $item)
                                        @if ($item['length'] > 0)
                                            <circle class="donut-segment donut-segment-batch" cx="60" cy="60" r="48"
                                                    style="stroke: {{ $item['color'] }};"
                                                    stroke-dasharray="{{ $item['length'] }} {{ $circumference }}"
                                                    stroke-dashoffset="-{{ $item['offset'] }}" />
                                        @endif
                                    @endforeach
                                </g>
                            </svg>
                            <div class="donut-center">
                                <div class="donut-center-value">{{ number_format($batchDistribution['total']) }}</div>
                                <div class="donut-center-label">In batches</div>
                            </div>
                        </div>
                        <p class="donut-caption">
                            @if ($batchDistribution['total'] > 0)
                                {{ number_format($batchDistribution['total']) }} {{ Str::plural('beneficiary', $batchDistribution['total']) }} across {{ count($batchDistribution['batches']) }} active {{ Str::plural('batch', count($batchDistribution['batches'])) }}.
                            @else
                                No beneficiaries assigned to active batches yet.
                            @endif
                        </p>
                    </div>
                    <div class="donut-legend">
                        @forelse ($batchDistribution['batches'] as $item)
                            <a class="donut-legend-item" href="{{ route('beneficiaries.index', ['batch' => $item['id']]) }}" title="View beneficiaries in {{ $item['name'] }}">
                                <span class="donut-swatch" style="background-color: {{ $item['color'] }};" aria-hidden="true"></span>
                                <span class="donut-legend-text">
                                    <span class="donut-legend-label">{{ $item['name'] }}</span>
                                    <span class="donut-legend-hint">Active release batch</span>
                                </span>
                                <span class="donut-legend-value">{{ number_format($item['count']) }}</span>
                                <span class="donut-legend-percent">{{ $formatPercent($item['percent']) }}</span>
                            </a>
                        @empty
                            <div class="p-3 text-center text-muted small">
                                No active batches found.
                                @if ($isAdmin && Route::has('beneficiary-batches.index'))
                                    <div class="mt-1">
                                        <a href="{{ route('beneficiary-batches.index') }}">Create a batch</a> to start grouping beneficiaries.
                                    </div>
                                @endif
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card panel-card h-100">
                <div class="panel-card-header">
                    <div>
                        <h2 class="panel-card-title h6 mb-0">Batch overview</h2>
                        <div class="panel-card-subtitle">Release batches open for payout</div>
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
                        @forelse ($openBatches as $batch)
                            <li class="batch-list-item">
                                <a class="batch-list-link" href="{{ route('beneficiary-batches.show', $batch) }}">
                                    <span class="batch-list-name">{{ $batch->name }}</span>
                                    <span class="batch-list-count">{{ number_format($batch->memberCount()) }} {{ Str::plural('member', $batch->memberCount()) }}</span>
                                </a>
                            </li>
                        @empty
                            <li class="batch-list-empty">
                                No open batches yet.
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

    {{-- Level 4: data quality --}}
    <div class="card panel-card">
        <div class="panel-card-header">
            <div>
                <h2 class="panel-card-title h6 mb-0">Data quality</h2>
                <div class="panel-card-subtitle">Active records that need review before payout</div>
            </div>
        </div>
        <div class="panel-card-body">
            <div class="dq-grid">
                <a class="dq-item {{ $counts['underage'] > 0 ? 'is-warning' : '' }}" href="{{ route('beneficiaries.index', ['age_status' => 'underage']) }}">
                    <span class="dq-icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                            <path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0M7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0z"/>
                        </svg>
                    </span>
                    <span class="dq-text">
                        <span class="dq-label">Underage</span>
                        <span class="dq-value">{{ number_format($counts['underage']) }}</span>
                        <span class="dq-meta">Younger than 18 years old</span>
                    </span>
                </a>
                <a class="dq-item {{ $counts['unknown_age'] > 0 ? 'is-warning' : '' }}" href="{{ route('beneficiaries.index', ['age_status' => 'unknown']) }}">
                    <span class="dq-icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path d="M8 3.5a.5.5 0 0 1 .5.5v4a.5.5 0 0 1-.5.5H4a.5.5 0 0 1 0-1h3.5V4a.5.5 0 0 1 .5-.5"/>
                            <path d="M8 16A8 8 0 1 1 8 0a8 8 0 0 1 0 16m0-1A7 7 0 1 0 8 1a7 7 0 0 0 0 14"/>
                        </svg>
                    </span>
                    <span class="dq-text">
                        <span class="dq-label">Missing/invalid birthdate</span>
                        <span class="dq-value">{{ number_format($counts['unknown_age']) }}</span>
                        <span class="dq-meta">Age cannot be verified</span>
                    </span>
                </a>
                <div class="dq-item {{ $counts['duplicate_candidates'] > 0 ? 'is-warning' : '' }}">
                    <span class="dq-icon">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                            <path d="M0 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2h2a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/>
                        </svg>
                    </span>
                    <span class="dq-text">
                        <span class="dq-label">Duplicate candidates</span>
                        <span class="dq-value">{{ number_format($counts['duplicate_candidates']) }}</span>
                        <span class="dq-meta">Candidates or confirmed duplicates</span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Level 5: recent imports and quick actions --}}
    <div class="row g-3 align-items-stretch">
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
                                <th class="text-end">Read / Inserted / Updated</th>
                                <th>Target batch</th>
                                <th>Uploaded by</th>
                                <th>When</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentBatches as $batch)
                                <tr>
                                    <td>#{{ $batch->id }}</td>
                                    <td class="text-truncate" style="max-width: 200px;">{{ $batch->original_filename }}</td>
                                    <td>
                                        @php
                                            $statusClass = match($batch->status) {
                                                'applied' => 'status-badge-success',
                                                'previewed' => 'status-badge-info',
                                                'uploaded' => 'status-badge-neutral',
                                                'failed' => 'status-badge-danger',
                                                'cancelled' => 'status-badge-warning',
                                                default => 'status-badge-neutral',
                                            };
                                        @endphp
                                        <span class="status-badge {{ $statusClass }}">{{ str_replace('_', ' ', $batch->status) }}</span>
                                    </td>
                                    <td class="text-end">
                                        <div class="import-tally">
                                            <span class="import-tally-item">
                                                <span class="import-tally-value">{{ number_format($batch->rows_read) }}</span>
                                                <span class="import-tally-label">read</span>
                                            </span>
                                            <span class="import-tally-item">
                                                <span class="import-tally-value">{{ number_format($batch->inserted) }}</span>
                                                <span class="import-tally-label">new</span>
                                            </span>
                                            <span class="import-tally-item">
                                                <span class="import-tally-value">{{ number_format($batch->updated) }}</span>
                                                <span class="import-tally-label">updated</span>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($batch->beneficiaryBatch)
                                            <a href="{{ route('beneficiary-batches.show', $batch->beneficiaryBatch) }}">{{ $batch->beneficiaryBatch->name }}</a>
                                        @else
                                            <span class="text-muted">Unassigned</span>
                                        @endif
                                    </td>
                                    <td>{{ $batch->user?->name }}</td>
                                    <td class="text-muted small">{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="text-end">
                                        @if (Route::has('imports.show'))
                                            <a class="btn btn-sm btn-link text-decoration-none" href="{{ route('imports.show', $batch) }}">View details</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8">
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
                            <a class="quick-action {{ $isAdmin ? 'is-primary' : '' }}" href="{{ route('beneficiary-batches.index') }}">
                                <div class="quick-action-icon">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2"/>
                                        <path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1z"/>
                                    </svg>
                                </div>
                                <div class="quick-action-content">
                                    <div class="quick-action-title">{{ $isAdmin ? 'Create batch' : 'Manage batches' }}</div>
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
                        @if (Route::has('id-cards.index'))
                            <a class="quick-action" href="{{ route('id-cards.index') }}">
                                <div class="quick-action-icon">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                        <path d="M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1z"/>
                                        <path d="M2 5h12v2H2z"/>
                                    </svg>
                                </div>
                                <div class="quick-action-content">
                                    <div class="quick-action-title">Generate ID cards</div>
                                    <div class="quick-action-subtitle">Print or prepare beneficiary ID cards</div>
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
@endsection
