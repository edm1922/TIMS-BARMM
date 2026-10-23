@extends('layouts.app')

@section('title', 'Excel imports')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Excel import batches</h1>
        <div class="text-muted small">Every upload is dry-run first; beneficiaries are only written after explicit apply.</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="{{ route('imports.create') }}">Upload workbook</a>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Dashboard</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>File</th>
                    <th>Worksheet</th>
                    <th>Status</th>
                    <th class="text-end">Read</th>
                    <th class="text-end">Inserted</th>
                    <th class="text-end">Updated</th>
                    <th>Uploaded by</th>
                    <th>When</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($batches as $batch)
                    <tr>
                        <td>{{ $batch->id }}</td>
                        <td class="text-truncate" style="max-width: 220px;">{{ $batch->original_filename }}</td>
                        <td>{{ $batch->worksheet }}</td>
                        <td>
                            @php
                                $statusClass = match ($batch->status) {
                                    'applied' => 'text-bg-success',
                                    'failed' => 'text-bg-danger',
                                    'previewed' => 'text-bg-warning',
                                    'cancelled' => 'text-bg-dark',
                                    default => 'text-bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $statusClass }}">{{ $batch->status }}</span>
                        </td>
                        <td class="text-end">{{ number_format($batch->rows_read) }}</td>
                        <td class="text-end">{{ number_format($batch->inserted) }}</td>
                        <td class="text-end">{{ number_format($batch->updated) }}</td>
                        <td>{{ $batch->user?->name }}</td>
                        <td class="text-muted small">{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route($batch->status === 'uploaded' ? 'imports.edit' : 'imports.show', $batch) }}">
                                {{ $batch->status === 'uploaded' ? 'Continue' : 'View' }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No imports yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3 d-flex justify-content-center">
    {{ $batches->links() }}
</div>
@endsection
