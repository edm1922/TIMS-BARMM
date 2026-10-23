@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Reports</h1>
        <div class="text-muted small">
            {{ number_format($beneficiaries->total()) }} record(s) match the current filters.
        </div>
    </div>
    @can('export-data')
        <div class="d-flex gap-2">
            <a class="btn btn-outline-success"
               href="{{ route('reports.export', array_merge($filters, ['format' => 'csv'])) }}">
                Export CSV
            </a>
            <a class="btn btn-outline-success"
               href="{{ route('reports.export', array_merge($filters, ['format' => 'xlsx'])) }}">
                Export XLSX
            </a>
        </div>
    @endcan
</div>

<div class="row g-2 mb-3">
    @php
        $cards = [
            ['label' => 'Total records', 'value' => $summary['total'], 'link' => ['status' => 'all']],
            ['label' => 'Active', 'value' => $summary['active'], 'link' => ['status' => 'active']],
            ['label' => 'Archived', 'value' => $summary['archived'], 'link' => ['status' => 'archived']],
            ['label' => 'Underage', 'value' => $summary['underage'], 'link' => ['status' => 'active', 'age_status' => 'underage']],
            ['label' => 'Needs review (age)', 'value' => $summary['unknown_age'], 'link' => ['status' => 'active', 'age_status' => 'unknown']],
            ['label' => 'Duplicate candidates', 'value' => $summary['duplicate_candidates'], 'link' => ['status' => 'all', 'duplicate_status' => 'candidate']],
        ];
    @endphp
    @foreach ($cards as $card)
        <div class="col-6 col-md-4 col-lg-2">
            <a class="card h-100 text-decoration-none text-reset" href="{{ route('reports.index', $card['link']) }}">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small">{{ $card['label'] }}</div>
                    <div class="h5 mb-0">{{ number_format($card['value']) }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<form method="GET" action="{{ route('reports.index') }}" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-0" for="q">Search</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] }}" placeholder="ID, name, ID No., barangay, occupation">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="age_status">Age status</label>
                <select class="form-select form-select-sm" id="age_status" name="age_status">
                    <option value="">All</option>
                    @foreach (['adult' => 'Adult', 'underage' => 'Underage', 'unknown' => 'Needs review'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['age_status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="duplicate_status">Duplicates</label>
                <select class="form-select form-select-sm" id="duplicate_status" name="duplicate_status">
                    <option value="">All</option>
                    @foreach (['none' => 'None', 'candidate' => 'Candidate', 'confirmed_duplicate' => 'Confirmed', 'cleared' => 'Cleared'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['duplicate_status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="created_from">Created from</label>
                <input type="date" class="form-control form-control-sm" id="created_from" name="created_from"
                       value="{{ $filters['created_from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="created_to">Created to</label>
                <input type="date" class="form-control form-control-sm" id="created_to" name="created_to"
                       value="{{ $filters['created_to'] }}">
            </div>
            <div class="col-md-1 d-grid">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
            </div>
            <div class="col-md-12">
                <label class="form-label small mb-0 d-inline-block me-3">Records:</label>
                @foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $value => $label)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="status" id="report-status-{{ $value }}"
                               value="{{ $value }}" @checked($filters['status'] === $value)>
                        <label class="form-check-label small" for="report-status-{{ $value }}">{{ $label }}</label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
            <tr>
                <th>Beneficiary ID</th>
                <th>Name</th>
                <th>Age</th>
                <th>Age status</th>
                <th>Sex</th>
                <th>Barangay / City</th>
                <th>Duplicate</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($beneficiaries as $beneficiary)
                <tr class="{{ $beneficiary->archived_at ? 'table-secondary' : '' }}">
                    <td class="font-monospace small">
                        {{ $beneficiary->beneficiary_code }}
                        @if ($beneficiary->archived_at)
                            <span class="badge text-bg-dark">Archived</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('beneficiaries.show', $beneficiary) }}" class="text-decoration-none">
                            {{ $beneficiary->full_name }}
                        </a>
                    </td>
                    <td>{{ $beneficiary->computedAge() ?? '—' }}</td>
                    <td>@include('partials.age-badge', ['status' => $beneficiary->ageStatus()])</td>
                    <td class="small">{{ $beneficiary->sex ?? '—' }}</td>
                    <td class="small">{{ trim(($beneficiary->barangay ?? '—').' / '.($beneficiary->city_municipality ?? '—')) }}</td>
                    <td>@include('partials.duplicate-badge', ['status' => $beneficiary->duplicate_status])</td>
                    <td class="small">{{ $beneficiary->created_at?->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No records match these filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $beneficiaries->links() }}</div>
</div>
@endsection
