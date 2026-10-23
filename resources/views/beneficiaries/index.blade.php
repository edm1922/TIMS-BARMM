@extends('layouts.app')

@section('title', 'Beneficiaries')

@section('content')
@php
    $isAdmin = auth()->user()->isAdmin();
    $colSpan = 9;
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Beneficiaries</h1>
        <div class="text-muted small">{{ number_format($beneficiaries->total()) }} matching records</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if ($isAdmin)
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#batchModal">
                Create Batch
            </button>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('beneficiary-batches.index') }}">Batches</a>
    </div>
</div>

<form method="GET" action="{{ route('beneficiaries.index') }}" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-0" for="q">Search</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] }}" placeholder="ID, name, ID No., barangay, occupation"
                       onsearch="if (this.value === '') this.form.submit()">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="age_status">Age status</label>
                <select class="form-select form-select-sm" id="age_status" name="age_status" onchange="this.form.submit()">
                    <option value="" @selected($filters['age_status'] === '')>All</option>
                    <option value="adult" @selected($filters['age_status'] === 'adult')>Adult</option>
                    <option value="underage" @selected($filters['age_status'] === 'underage')>Underage</option>
                    <option value="unknown" @selected($filters['age_status'] === 'unknown')>Unknown</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="sex">Sex</label>
                <select class="form-select form-select-sm" id="sex" name="sex" onchange="this.form.submit()">
                    <option value="" @selected($filters['sex'] === '')>All</option>
                    <option value="MALE" @selected($filters['sex'] === 'MALE')>MALE</option>
                    <option value="FEMALE" @selected($filters['sex'] === 'FEMALE')>FEMALE</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="civil_status">Civil status</label>
                <select class="form-select form-select-sm" id="civil_status" name="civil_status" onchange="this.form.submit()">
                    <option value="" @selected($filters['civil_status'] === '')>All</option>
                    @foreach ($civilStatuses as $statusOption)
                        <option value="{{ $statusOption }}" @selected($filters['civil_status'] === $statusOption)>
                            {{ $statusOption }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="duplicate_status">Duplicates</label>
                <select class="form-select form-select-sm" id="duplicate_status" name="duplicate_status" onchange="this.form.submit()">
                    <option value="" @selected($filters['duplicate_status'] === '')>All</option>
                    <option value="none" @selected($filters['duplicate_status'] === 'none')>None</option>
                    <option value="candidate" @selected($filters['duplicate_status'] === 'candidate')>Candidate</option>
                    <option value="confirmed_duplicate" @selected($filters['duplicate_status'] === 'confirmed_duplicate')>Confirmed</option>
                    <option value="cleared" @selected($filters['duplicate_status'] === 'cleared')>Cleared</option>
                </select>
            </div>
            <button type="submit" class="d-none" aria-hidden="true"></button>
            <div class="col-md-1 d-grid">
                @if ($filters['q'] !== '' || $filters['age_status'] !== '' || $filters['sex'] !== '' || $filters['civil_status'] !== '' || $filters['duplicate_status'] !== '' || $filters['status'] !== 'active' || $filters['batch'] !== '')
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('beneficiaries.index') }}" title="Clear filters">Reset</a>
                @endif
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-0" for="batch">Batch</label>
                <select class="form-select form-select-sm" id="batch" name="batch" onchange="this.form.submit()">
                    <option value="" @selected($filters['batch'] === '')>All batches</option>
                    <option value="unassigned" @selected($filters['batch'] === 'unassigned')>Unassigned</option>
                    @foreach ($batches as $batch)
                        <option value="{{ $batch->id }}" @selected($filters['batch'] === (string) $batch->id)>
                            {{ $batch->name }} ({{ $batch->memberCount() }}){{ $batch->isOpen() ? '' : ' — closed' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label small mb-0 d-inline-block me-3">Records:</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="status" id="status-active"
                           value="active" onchange="this.form.submit()" @checked($filters['status'] === 'active')>
                    <label class="form-check-label small" for="status-active">Active</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="status" id="status-archived"
                           value="archived" onchange="this.form.submit()" @checked($filters['status'] === 'archived')>
                    <label class="form-check-label small" for="status-archived">Archived</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="status" id="status-all"
                           value="all" onchange="this.form.submit()" @checked($filters['status'] === 'all')>
                    <label class="form-check-label small" for="status-all">All</label>
                </div>
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
                    <th>Sex</th>
                    <th>Barangay / City</th>
                    <th>Civil status</th>
                    <th>Duplicate</th>
                    <th>Batch</th>
                    <th class="text-end">Actions</th>
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
                        <td class="small">
                            {{ $beneficiary->computedAge() ?? '—' }}
                            @include('partials.age-badge', ['status' => $beneficiary->ageStatus()])
                        </td>
                        <td class="small">{{ $beneficiary->sex ?? '—' }}</td>
                        <td class="small">{{ trim(($beneficiary->barangay ?? '—').' / '.($beneficiary->city_municipality ?? '—')) }}</td>
                        <td class="small">{{ $beneficiary->civil_status ?? '—' }}</td>
                        <td>@include('partials.duplicate-badge', ['status' => $beneficiary->duplicate_status])</td>
                        <td class="small">@include('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('beneficiaries.show', $beneficiary) }}">View</a>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('beneficiaries.edit', $beneficiary) }}">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colSpan }}" class="text-center text-muted py-4">No beneficiaries match the current filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3 d-flex justify-content-center">
    {{ $beneficiaries->links() }}
</div>

@if ($isAdmin)
    @include('batches.partials.create-modal')
@endif

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
