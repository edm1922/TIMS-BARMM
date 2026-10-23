@extends('layouts.app')

@section('title', 'Batches')

@section('content')
@php
    $isAdmin = auth()->user()->isAdmin();
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Batches</h1>
        <div class="text-muted small">Operational release groups for a round of payout or verification work.</div>
    </div>
    <div class="d-flex gap-2">
        @if ($isAdmin)
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#batchModal">
                Create Batch
            </button>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('beneficiaries.index') }}">Beneficiaries</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Batch</th>
                    <th>Status</th>
                    <th class="text-end">Members</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>
                            <a href="{{ route('beneficiary-batches.show', $batch) }}" class="text-decoration-none">
                                <strong>{{ $batch->name }}</strong>
                            </a>
                            @if ($batch->description)
                                <div class="small text-muted">{{ $batch->description }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($batch->isOpen())
                                <span class="badge text-bg-success">Open</span>
                            @else
                                <span class="badge text-bg-secondary">Closed</span>
                            @endif
                        </td>
                        <td class="text-end">{{ number_format($batch->memberCount()) }}</td>
                        <td class="small text-muted">
                            {{ $batch->created_at?->format('Y-m-d H:i') }}
                            @if ($batch->creator)
                                <div class="text-muted">by {{ $batch->creator->name }}</div>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('beneficiary-batches.show', $batch) }}">Manage</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            No batches yet.
                            @if ($isAdmin)
                                Create one to group beneficiaries for a payout round.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3 d-flex justify-content-center">
    {{ $batches->links() }}
</div>

@if ($isAdmin)
    @include('batches.partials.create-modal')
@endif

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-batch.js') }}"></script>
@endpush
@endsection
