@extends('layouts.app')

@section('title', 'Beneficiaries')

@section('content')
@php
    $canCreate = auth()->user()->can('create', \App\Models\BeneficiaryBatch::class);
    $colSpan = 8;

    $filterLabels = [];
    if ($filters['q'] !== '') {
        $filterLabels['Search'] = $filters['q'];
    }
    if ($filters['age_range'] !== '') {
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
    if ($filters['sex'] !== '') {
        $filterLabels['Sex'] = $filters['sex'];
    }
    if ($filters['civil_status'] !== '') {
        $filterLabels['Civil status'] = $filters['civil_status'];
    }
    if ($filters['batch'] !== '') {
        $filterLabels['Batch'] = $filters['batch'] === 'unassigned'
            ? 'Unassigned'
            : (collect($batches)->firstWhere('id', (int) $filters['batch'])?->name ?? $filters['batch']);
    }
    if ($filters['sort'] !== 'registered') {
        $filterLabels['Sort'] = [
            'newest' => 'Newest registered first',
            'name' => 'Name (A-Z)',
            'name_desc' => 'Name (Z-A)',
        ][$filters['sort']] ?? $filters['sort'];
    }
    $filterLabels['Records'] = match ($filters['status']) {
        'all' => 'All',
        'missing' => 'Missing fields',
        default => 'Active',
    };
    $hasFilters = $filters['q'] !== ''
        || $filters['age_range'] !== ''
        || $filters['age_status'] !== ''
        || $filters['registered_year'] !== ''
        || $filters['sex'] !== ''
        || $filters['civil_status'] !== ''
        || $filters['status'] !== 'active'
        || $filters['batch'] !== ''
        || $filters['sort'] !== 'registered';
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .beneficiary-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        @media (max-width: 991.98px) { .sync-console .beneficiary-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 420px) { .sync-console .beneficiary-summary { grid-template-columns: 1fr; } }
        .sync-console a.sync-stat { text-decoration: none; color: inherit; }
        .sync-console a.sync-stat:hover { border-color: #cbd5e1; box-shadow: 0 4px 10px rgba(15, 23, 42, .07); }
        .sync-console a.sync-stat:focus-visible { outline: 2px solid var(--sync-primary); outline-offset: 2px; }
        .sync-console .sync-stat-icon { width: 34px; height: 34px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; background: #f1f5f9; color: #475569; margin-bottom: 4px; }
        .sync-console .sync-stat-icon--ok { background: var(--sync-success-soft); color: var(--sync-success); }
        .sync-console .sync-stat-icon--warning { background: var(--sync-warning-soft); color: var(--sync-warning); }
        .sync-console .sync-stat-icon--info { background: var(--sync-info-soft); color: var(--sync-info); }
        .sync-console .sync-stat-icon--danger { background: var(--sync-danger-soft); color: var(--sync-danger); }

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
        .sync-console .report-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; border: 1px solid #e5e7eb; }
        .sync-console .report-id { display: block; max-width: 26ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; }
        .sync-console .report-name { font-weight: 600; font-size: 13px; color: var(--sync-primary); text-decoration: none; }
        .sync-console .report-name:hover { text-decoration: underline; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Beneficiaries</h1>
            <p class="sync-page-subtitle">Search and inspect beneficiary records, their batches and data-quality gaps.</p>
            <div class="sync-meta">
                <span><strong>{{ number_format($beneficiaries->total()) }}</strong> matching {{ Str::plural('record', $beneficiaries->total()) }}</span>
                @if ($beneficiaries->hasPages())
                    <span>Page {{ $beneficiaries->currentPage() }} of {{ $beneficiaries->lastPage() }}</span>
                @endif
            </div>
        </div>
        <div class="sync-page-actions">
            @if ($canCreate)
                <button class="sync-btn sync-btn--primary" type="button" data-bs-toggle="modal" data-bs-target="#batchModal">
                    Create Batch
                </button>
            @endif
            <a class="sync-btn sync-btn--secondary" href="{{ route('beneficiary-batches.index') }}">Batches</a>
        </div>
    </header>

    <section class="sync-summary beneficiary-summary" aria-label="Beneficiary overview">
        <a class="sync-card sync-stat sync-stat--ok" href="{{ route('beneficiaries.index') }}">
            <span class="sync-stat-icon sync-stat-icon--ok" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
            </span>
            <div class="sync-stat-label">Active records</div>
            <div class="sync-stat-value">{{ number_format($counts['records']) }}</div>
            <div class="sync-stat-note">Current beneficiary register.</div>
        </a>
        <a class="sync-card sync-stat" href="{{ route('beneficiaries.index', ['batch' => 'unassigned']) }}">
            <span class="sync-stat-icon sync-stat-icon--info" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>
            </span>
            <div class="sync-stat-label">Unassigned</div>
            <div class="sync-stat-value">{{ number_format($counts['unassigned']) }}</div>
            <div class="sync-stat-note">Not filed in a release batch.</div>
        </a>
        <a class="sync-card sync-stat sync-stat--warning" href="{{ route('beneficiaries.index', ['status' => 'missing']) }}">
            <span class="sync-stat-icon sync-stat-icon--warning" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </span>
            <div class="sync-stat-label">Missing fields</div>
            <div class="sync-stat-value">{{ number_format($counts['missing']) }}</div>
            <div class="sync-stat-note">Records with a deliberate or legacy gap.</div>
        </a>
        <a class="sync-card sync-stat" href="{{ route('beneficiary-batches.index') }}">
            <span class="sync-stat-icon" aria-hidden="true">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2v-1h1v1a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v1H1V2a2 2 0 0 1 2-2"/><path d="M1 5v-.5a.5.5 0 0 1 1 0V5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0V8h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1zm0 3v-.5a.5.5 0 0 1 1 0v.5h.5a.5.5 0 0 1 0 1h-2a.5.5 0 0 1 0-1z"/></svg>
            </span>
            <div class="sync-stat-label">Batches</div>
            <div class="sync-stat-value">{{ number_format($counts['batches']) }}</div>
            <div class="sync-stat-note">Release groups available for assignment.</div>
        </a>
    </section>

    <form method="GET" action="{{ route('beneficiaries.index') }}" class="sync-card mb-3">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title">Search &amp; filter beneficiaries</h2>
                <p class="sync-section-subtitle">Filters update automatically; the list keeps the current registration ordering toggles.</p>
            </div>
            @if ($hasFilters)
                <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('beneficiaries.index') }}">Reset</a>
            @endif
        </div>

        <div class="report-filter-grid">
            <div class="span-4">
                <label class="sync-kv-label d-block mb-1" for="q">Search</label>
                <div class="report-search-wrap">
                    <span class="report-search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="search" class="sync-input" id="q" name="q"
                           value="{{ $filters['q'] }}" placeholder="ID, name, ID No., barangay, occupation"
                           onsearch="if (this.value === '') this.form.submit()">
                </div>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="age_range">Age distribution</label>
                <select class="sync-input" id="age_range" name="age_range" onchange="this.form.submit()">
                    <option value="" @selected($filters['age_range'] === '')>All</option>
                    <option value="below18" @selected($filters['age_range'] === 'below18')>Below 18</option>
                    <option value="18-25" @selected($filters['age_range'] === '18-25')>18–25 years</option>
                    <option value="26-31" @selected($filters['age_range'] === '26-31')>26–31 years</option>
                    <option value="32-40" @selected($filters['age_range'] === '32-40')>32–40 years</option>
                    <option value="41-50" @selected($filters['age_range'] === '41-50')>41–50 years</option>
                    <option value="51-60" @selected($filters['age_range'] === '51-60')>51–60 years</option>
                    <option value="61plus" @selected($filters['age_range'] === '61plus')>61 years and above</option>
                    <option value="unclassified" @selected($filters['age_range'] === 'unclassified')>Unclassified</option>
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="sex">Sex</label>
                <select class="sync-input" id="sex" name="sex" onchange="this.form.submit()">
                    <option value="" @selected($filters['sex'] === '')>All</option>
                    <option value="MALE" @selected($filters['sex'] === 'MALE')>MALE</option>
                    <option value="FEMALE" @selected($filters['sex'] === 'FEMALE')>FEMALE</option>
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="civil_status">Civil status</label>
                <select class="sync-input" id="civil_status" name="civil_status" onchange="this.form.submit()">
                    <option value="" @selected($filters['civil_status'] === '')>All</option>
                    @foreach ($civilStatuses as $statusOption)
                        <option value="{{ $statusOption }}" @selected($filters['civil_status'] === $statusOption)>{{ $statusOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="sort">Sort</label>
                <select class="sync-input" id="sort" name="sort" onchange="this.form.submit()">
                    <option value="registered" @selected($filters['sort'] === 'registered')>Registration order</option>
                    <option value="newest" @selected($filters['sort'] === 'newest')>Newest registered first</option>
                    <option value="name" @selected($filters['sort'] === 'name')>Name (A-Z)</option>
                    <option value="name_desc" @selected($filters['sort'] === 'name_desc')>Name (Z-A)</option>
                </select>
            </div>

            <div class="span-3">
                <label class="sync-kv-label d-block mb-1" for="batch">Batch</label>
                <select class="sync-input" id="batch" name="batch" onchange="this.form.submit()">
                    <option value="" @selected($filters['batch'] === '')>All batches</option>
                    <option value="unassigned" @selected($filters['batch'] === 'unassigned')>Unassigned</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" @selected($filters['batch'] === (string) $batch->id)>
                            {{ $batch->name }} ({{ $batch->memberCount() }}){{ $batch->isOpen() ? '' : ' - closed' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="span-4">
                <span class="sync-kv-label d-block mb-1">Records</span>
                <div class="report-status" role="radiogroup" aria-label="Record status">
                    @foreach (['active' => 'Active', 'all' => 'All', 'missing' => 'Missing fields'] as $value => $label)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="status" id="status-{{ $value }}"
                                   value="{{ $value }}" onchange="this.form.submit()" @checked($filters['status'] === $value)>
                            <label class="form-check-label small" for="status-{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>
    </form>

    <section class="sync-card mb-3" aria-labelledby="beneficiary-table-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="beneficiary-table-title">Beneficiary records</h2>
                <p class="sync-section-subtitle">{{ number_format($beneficiaries->total()) }} matching {{ Str::plural('record', $beneficiaries->total()) }}</p>
            </div>
            <div class="report-chips">
                @foreach ($filterLabels as $label => $value)
                    <span class="report-chip">{{ $label }}: <strong>{{ $value }}</strong></span>
                @endforeach
            </div>
        </div>

        @if ($beneficiaries->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No beneficiaries match the current filters</div>
                <div class="sync-empty-note">Try a different search or <a href="{{ route('beneficiaries.index') }}">clear all filters</a>.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Beneficiary records matching the current filters</caption>
                    <thead>
                        <tr>
                            <th scope="col">Beneficiary ID</th>
                            <th scope="col">Name</th>
                            <th scope="col" class="text-end">Age</th>
                            <th scope="col">Sex</th>
                            <th scope="col">Barangay / City</th>
                            <th scope="col">Civil status</th>
                            <th scope="col">Batch</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($beneficiaries as $beneficiary)
                            <tr>
                                <td>
                                    <span class="report-id" title="{{ $beneficiary->beneficiary_code }}">{{ $beneficiary->beneficiary_code }}</span>
                                </td>
                                <td>
                                    <a class="report-name" href="{{ route('beneficiaries.show', $beneficiary) }}">{{ $beneficiary->full_name }}</a>
                                </td>
                                <td class="text-end sync-ops-num">{{ $beneficiary->computedAge() ?? '-' }}</td>
                                <td style="font-size: 12.5px;">{{ $beneficiary->sex ?? '-' }}</td>
                                <td style="font-size: 12.5px;">{{ trim(($beneficiary->barangay ?? '-').' / '.($beneficiary->city_municipality ?? '-')) }}</td>
                                <td style="font-size: 12.5px;">{{ $beneficiary->civil_status ?? '-' }}</td>
                                <td>@include('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])</td>
                                <td class="text-end text-nowrap">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('beneficiaries.show', $beneficiary) }}">View</a>
                                    <a class="sync-btn sync-btn--small" style="color: var(--sync-primary); background: #fff; border-color: #bfdbfe;" href="{{ route('beneficiaries.edit', $beneficiary) }}">Edit</a>
                                </td>
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
                    <form method="GET" action="{{ route('beneficiaries.index') }}" class="d-flex align-items-center gap-2">
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

    @if ($canCreate)
        @include('batches.partials.create-modal')
    @endif
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const q = document.getElementById('q');
    if (!q) return;

    let timer = null;
    q.addEventListener('input', function () {
        clearTimeout(timer);
        const val = this.value.trim();

        if (val.length === 0) {
            this.form.submit();
            return;
        }

        if (val.length >= 2) {
            timer = setTimeout(() => {
                this.form.submit();
            }, 600);
        }
    });

    q.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            clearTimeout(timer);
            this.form.submit();
        }
    });
});
</script>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-batch.js') }}"></script>
@endpush
