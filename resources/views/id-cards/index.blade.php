@extends('layouts.app')

@section('title', 'ID Cards')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">ID card printing</h1>
        <div class="text-muted small">Select beneficiaries, then print front / back page pairs at actual size (A4, margins off).</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Dashboard</a>
    </div>
</div>

<form method="GET" action="{{ route('id-cards.index') }}" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-0" for="q">Search</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] }}" placeholder="ID, name, ID No., barangay">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0">Records</label>
                <div class="pt-1">
                    @foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $value => $label)
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="status" id="status-{{ $value }}"
                                   value="{{ $value }}" @checked($filters['status'] === $value)>
                            <label class="form-check-label small" for="status-{{ $value }}">{{ $label }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
            </div>
        </div>
    </div>
</form>

<form method="GET" action="{{ route('id-cards.print') }}" id="print-form">
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small"><strong id="selected-count">0</strong> selected</span>
            <button class="btn btn-sm btn-primary" type="submit">Print selected cards</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th style="width:2rem">
                        <input class="form-check-input" type="checkbox" id="select-all" title="Select all">
                    </th>
                    <th>Name</th>
                    <th>Beneficiary ID</th>
                    <th>Barangay</th>
                    <th>Age status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($beneficiaries as $beneficiary)
                    <tr>
                        <td>
                            <input class="form-check-input row-select" type="checkbox" name="ids[]"
                                   value="{{ $beneficiary->id }}" id="sel-{{ $beneficiary->id }}">
                        </td>
                        <td>
                            <a href="{{ route('beneficiaries.show', $beneficiary) }}">{{ $beneficiary->full_name }}</a>
                            @if ($beneficiary->archived_at)
                                <span class="badge text-bg-dark">Archived</span>
                            @endif
                        </td>
                        <td class="font-monospace small">{{ $beneficiary->beneficiary_code }}</td>
                        <td>{{ $beneficiary->barangay ?? '—' }}</td>
                        <td>@include('partials.age-badge', ['status' => $beneficiary->ageStatus()])</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No beneficiaries match this filter.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $beneficiaries->links() }}
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    (function () {
        const selectAll = document.getElementById('select-all');
        const rows = Array.from(document.querySelectorAll('.row-select'));
        const count = document.getElementById('selected-count');

        function refresh() {
            count.textContent = String(rows.filter((cb) => cb.checked).length);
        }

        selectAll.addEventListener('change', function () {
            rows.forEach((cb) => { cb.checked = selectAll.checked; });
            refresh();
        });
        rows.forEach((cb) => cb.addEventListener('change', refresh));
        refresh();
    })();
</script>
@endpush
