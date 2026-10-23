@extends('layouts.app')

@section('title', 'Import batch #'.$batch->id)

@section('content')
@php
    $outcomes = ['new', 'update', 'unchanged', 'duplicate', 'review', 'invalid'];
    $tileClass = [
        'new' => 'text-bg-primary',
        'update' => 'text-bg-warning',
        'unchanged' => 'text-bg-secondary',
        'duplicate' => 'text-bg-danger',
        'review' => 'text-bg-info',
        'invalid' => 'text-bg-dark',
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Import batch #{{ $batch->id }}</h1>
        <div class="text-muted small">
            {{ $batch->original_filename }} �?" worksheet <code>{{ $batch->worksheet }}</code>,
            header row {{ $batch->header_row }},
            uploaded by {{ $batch->user?->name }} {{ $batch->created_at?->diffForHumans() }}
            @if ($batch->beneficiaryBatch)
                · Target batch <a href="{{ route('beneficiary-batches.show', $batch->beneficiaryBatch) }}">{{ $batch->beneficiaryBatch->name }}</a>
            @else
                · Target batch <span class="text-muted">Unassigned</span>
            @endif
            <span class="badge text-bg-secondary">{{ $batch->status }}</span>
        </div>
    </div>

    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="{{ route('imports.index') }}">Batches</a>
        @if (in_array($batch->status, ['previewed', 'applied', 'failed'], true))
            <a class="btn btn-outline-primary" href="{{ route('imports.report', $batch) }}">Download row report (CSV)</a>
        @endif
        @if ($batch->status === 'uploaded')
            <a class="btn btn-primary" href="{{ route('imports.edit', $batch) }}">Continue to mapping</a>
        @endif
    </div>
</div>

@if (in_array($batch->status, ['previewed', 'applied', 'failed'], true))
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-2">
            <div class="card"><div class="card-body py-2">
                <div class="text-muted small">Rows read</div>
                <div class="fs-5">{{ number_format($batch->rows_read) }}</div>
            </div></div>
        </div>
        @foreach ($outcomes as $outcome)
            <div class="col-6 col-md-2">
                <div class="card"><div class="card-body py-2">
                    <div class="text-muted small text-capitalize">{{ $outcome }}</div>
                    <div class="fs-5">{{ number_format($summary[$outcome] ?? 0) }}</div>
                </div></div>
            </div>
        @endforeach
    </div>
@endif

@if ($batch->status === 'failed')
    <div class="alert alert-danger">
        <strong>Apply failed and the whole batch was rolled back.</strong>
        Nothing was written. Check the row report for the failing Excel row, fix the workbook, and upload again.
    </div>
@endif

@if ($batch->status === 'applied')
    <div class="alert alert-success">
        <strong>Applied.</strong>
        {{ number_format($batch->inserted) }} inserted, {{ number_format($batch->updated) }} updated,
        {{ number_format($batch->skipped) }} skipped, {{ number_format($batch->flagged) }} created records
        were flagged as duplicate candidates.
        <a href="{{ route('beneficiaries.index') }}">Browse beneficiaries</a>
    </div>
@endif

@if ($batch->status === 'cancelled')
    <div class="alert alert-secondary">This batch was cancelled; the uploaded file was deleted.</div>
@endif

@if ($batch->status === 'previewed')
    @if ($missingFromImport !== null && $missingFromImport > 0)
        <div class="alert alert-warning py-2 small" role="alert">
            {{ number_format($missingFromImport) }} active {{ Str::plural('record', $missingFromImport) }} in the system
            (with an <code>ID NUMBER</code>) {{ $missingFromImport === 1 ? 'is' : 'are' }} not present in this file.
            They are never deleted or archived automatically — follow up manually if that is unexpected.
        </div>
    @endif

    <form method="POST" action="{{ route('imports.apply', $batch) }}">
        @csrf
        <div class="card mb-3">
            <div class="card-header bg-white">Confirm decisions, then apply</div>
            <div class="card-body py-2">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="d-new">New rows</label>
                        <select class="form-select form-select-sm" id="d-new" name="decisions[new]">
                            <option value="insert">Insert</option>
                            <option value="skip">Skip</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="d-update">Update rows</label>
                        <select class="form-select form-select-sm" id="d-update" name="decisions[update]">
                            <option value="update">Update matched</option>
                            <option value="skip">Skip</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="d-duplicate">Duplicate candidates</label>
                        <select class="form-select form-select-sm" id="d-duplicate" name="decisions[duplicate]">
                            <option value="skip">Skip (leave for manual review)</option>
                            <option value="insert">Import as separate person</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="d-review">Needs-review rows</label>
                        <select class="form-select form-select-sm" id="d-review" name="decisions[review]">
                            <option value="skip">Skip</option>
                            <option value="insert">Import anyway (acknowledged)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" required>
                            <label class="form-check-label small" for="confirm">I reviewed the summary</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2 align-items-center">
                        @can('apply', $batch)
                            <button class="btn btn-primary" type="submit"
                                    onclick="return confirm('Apply this batch? This writes to the beneficiaries table in one transaction.')">
                                Apply confirmed changes
                            </button>
                        @else
                            <button class="btn btn-primary" type="button" disabled>Apply (administrators only)</button>
                        @endcan
                        @can('cancel', $batch)
                            <span>
                                <button class="btn btn-outline-danger" type="submit" form="cancel-batch"
                                        onclick="return confirm('Cancel this batch and delete the uploaded file?')">Cancel batch</button>
                            </span>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </form>

    @can('cancel', $batch)
        <form method="POST" action="{{ route('imports.cancel', $batch) }}" id="cancel-batch" class="d-none">@csrf</form>
    @endcan
    <div class="card mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span>Preview rows</span>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary {{ $outcomeFilter === '' ? 'active' : '' }}"
                   href="{{ route('imports.show', $batch) }}">All</a>
                @foreach ($outcomes as $outcome)
                    <a class="btn btn-outline-secondary {{ $outcomeFilter === $outcome ? 'active' : '' }}"
                       href="{{ route('imports.show', $batch) }}?outcome={{ $outcome }}">{{ $outcome }}</a>
                @endforeach
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Excel row</th>
                        <th>Name</th>
                        <th>Birthdate</th>
                        <th>Age status</th>
                        <th>ID NUMBER</th>
                        <th>Outcome</th>
                        <th>Messages</th>
                        <th>Matched / candidates</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $payload = $row->payload ?? [];
                            $bd = $payload['birthdate'] ?? null;
                            $ageStatus = null;
                            if ($bd !== null) {
                                try {
                                    $ageStatus = \App\Services\AgeService::status(\Illuminate\Support\Carbon::parse($bd));
                                } catch (\Throwable) {
                                    $ageStatus = 'unknown';
                                }
                            }
                        @endphp
                        <tr>
                            <td class="font-monospace small">{{ $row->excel_row }}</td>
                            <td class="small">
                                {{ trim(($payload['first_name'] ?? '').' '.($payload['middle_name'] ?? '').' '.($payload['last_name'] ?? '')) }}
                            </td>
                            <td class="small">{{ $bd ?? (($payload['birthdate_raw'] ?? null) ? 'raw: '.$payload['birthdate_raw'] : '—') }}</td>
                            <td>@include('partials.age-badge', ['status' => $ageStatus])</td>
                            <td class="small font-monospace">{{ $payload['source_id_number'] ?? '—' }}</td>
                            <td><span class="badge {{ $tileClass[$row->outcome] ?? 'text-bg-secondary' }}">{{ $row->outcome }}</span></td>
                            <td class="small" style="max-width: 320px;">
                                @forelse ($row->messages ?? [] as $message)
                                    <div class="{{ ($message['severity'] ?? '') === 'error' ? 'text-danger' : 'text-warning' }}">{{ $message['text'] }}</div>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="small">
                                @if ($row->matched_beneficiary_id)
                                    <a class="font-monospace" href="{{ route('beneficiaries.show', $row->matched_beneficiary_id) }}">#{{ $row->matched_beneficiary_id }}</a>
                                @endif
                                @foreach ($row->candidate_ids ?? [] as $id)
                                    <a class="font-monospace" href="{{ route('beneficiaries.show', $id) }}">{{ $codeLookup[$id] ?? '#'.$id }}</a>{{ !$loop->last ? ', ' : '' }}
                                @endforeach
                                @if (! $row->matched_beneficiary_id && empty($row->candidate_ids))
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No rows.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-center">
        {{ $rows->links() }}
    </div>
@endif
@endsection
