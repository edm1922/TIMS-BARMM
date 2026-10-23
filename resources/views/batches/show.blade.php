@extends('layouts.app')

@section('title', $batch->name)

@section('content')
@php
    $isAdmin = auth()->user()->isAdmin();
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="{{ route('beneficiary-batches.index') }}">Batches</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $batch->name }}</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">
            {{ $batch->name }}
            @if ($batch->isOpen())
                <span class="badge text-bg-success">Open</span>
            @else
                <span class="badge text-bg-secondary">Closed</span>
            @endif
        </h1>
        <div class="text-muted small">
            {{ number_format($batch->memberCount()) }} {{ Str::plural('beneficiary', $batch->memberCount()) }}
            · created {{ $batch->created_at?->format('Y-m-d H:i') }}
            @if ($batch->creator)
                by {{ $batch->creator->name }}
            @endif
        </div>
        @if ($batch->description)
            <div class="small mt-1">{{ $batch->description }}</div>
        @endif
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if ($batch->isOpen())
            <a class="btn btn-primary" href="{{ route('beneficiary-batches.beneficiaries.create', $batch) }}">
                Add Beneficiary
            </a>
        @endif
        @if ($isAdmin)
            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
                    data-bs-target="#batchSettings" aria-expanded="false" aria-controls="batchSettings">
                Settings
            </button>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('beneficiaries.index') }}">All beneficiaries</a>
    </div>
</div>

@if ($isAdmin)
    <div class="collapse mb-3" id="batchSettings">
        <div class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <form method="POST" action="{{ route('beneficiary-batches.update', $batch) }}" class="row g-2">
                            @csrf
                            @method('PUT')
                            <div class="col-md-6">
                                <label class="form-label small mb-1" for="batch-edit-name">Batch name</label>
                                <input type="text" class="form-control form-control-sm" id="batch-edit-name" name="name"
                                       maxlength="100" required value="{{ old('name', $batch->name) }}">
                                @error('name')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1" for="batch-edit-status">Status</label>
                                <select class="form-select form-select-sm" id="batch-edit-status" name="status">
                                    <option value="open" @selected(old('status', $batch->status) === 'open')>Open</option>
                                    <option value="closed" @selected(old('status', $batch->status) === 'closed')>Closed</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1" for="batch-edit-description">Description</label>
                                <input type="text" class="form-control form-control-sm" id="batch-edit-description"
                                       name="description" maxlength="255"
                                       value="{{ old('description', $batch->description) }}">
                                @error('description')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-primary">Save changes</button>
                            </div>
                        </form>
                    </div>
                    <div class="col-lg-5">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold small mb-1">Delete batch</div>
                            <p class="small text-muted">
                                A batch can only be deleted when it has no members. Deleting a batch never
                                deletes beneficiary records.
                            </p>
                            <form method="POST" action="{{ route('beneficiary-batches.destroy', $batch) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Delete the batch {{ $batch->name }}?')">
                                    Delete batch
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

<div class="card">
    <div class="card-header py-2 bg-white">
        <strong class="small">Members</strong>
        <span class="small text-muted">— {{ number_format($members->total()) }} in this batch</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Beneficiary ID</th>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Sex</th>
                    <th>Barangay / City</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $member)
                    <tr class="batch-member-row">
                        <td class="font-monospace small">
                            {{ $member->beneficiary_code }}
                            @if ($member->archived_at)
                                <span class="badge text-bg-dark">Archived</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('beneficiaries.show', $member) }}" class="text-decoration-none">
                                {{ $member->full_name }}
                            </a>
                        </td>
                        <td class="small">{{ $member->computedAge() ?? '—' }}</td>
                        <td class="small">{{ $member->sex ?? '—' }}</td>
                        <td class="small">{{ trim(($member->barangay ?? '—').' / '.($member->city_municipality ?? '—')) }}</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('beneficiaries.show', $member) }}">View</a>
                            @if ($isAdmin)
                                <form method="POST" class="d-inline"
                                      action="{{ route('beneficiary-batches.members.destroy', $batch) }}"
                                      onsubmit="return confirm('Remove {{ $member->full_name }} from this batch?')">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="ids[]" value="{{ $member->id }}">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            This batch has no beneficiaries yet.
                            @if ($batch->isOpen())
                                Use <strong>Add Beneficiary</strong> to register the first one in this batch.
                            @else
                                Reopen the batch to add beneficiaries.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3 d-flex justify-content-center">
    {{ $members->links() }}
</div>

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-batch.js') }}"></script>
@endpush
@endsection
