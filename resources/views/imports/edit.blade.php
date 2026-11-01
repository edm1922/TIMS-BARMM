@extends('layouts.app')

@section('title', 'Confirm mapping')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">Confirm worksheet &amp; mapping</h1>
        <div class="text-muted small">{{ $batch->original_filename }} — header row {{ $batch->header_row }}, data starts at row {{ $batch->mapping['data_start'] ?? '?' }}</div>
        @if ($batch->profiling_date !== null)
            <div class="text-muted small">
                Beneficiary profiling date: <strong>{{ $batch->profiling_date->format('F j, Y') }}</strong>
                — imported beneficiaries will be recorded under {{ $batch->profiling_date->format('F Y') }} in the dashboard trend.
            </div>
        @endif
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('imports.index') }}">Cancel batch from the list page</a>
</div>

<form method="POST" action="{{ route('imports.update-mapping', $batch) }}">
    @csrf
    @method('PUT')

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="worksheet">Worksheet</label>
                    <select class="form-select form-select-sm @error('worksheet') is-invalid @enderror" id="worksheet" name="worksheet">
                        @foreach ($sheets as $sheet)
                            <option value="{{ $sheet['name'] }}" @selected($sheet['name'] === $batch->worksheet)>
                                {{ $sheet['name'] }} ({{ $sheet['visible'] ? 'visible' : 'hidden' }}, {{ number_format($sheet['data_rows']) }} rows)
                            </option>
                        @endforeach
                    </select>
                    @error('worksheet')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="use_source_id_as_key" value="1" id="use_source_id_as_key"
                               @checked($batch->use_source_id_as_key)>
                        <label class="form-check-label small" for="use_source_id_as_key">
                            Match existing records by <code>ID NUMBER</code> (the DOLE key; disable to never auto-match for updates)
                        </label>
                    </div>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary btn-sm" type="submit">Confirm mapping &amp; run dry-run</button>
                </div>
            </div>
        </div>
    </div>

    @error('fields')
        <div class="alert alert-danger py-2">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="card-header bg-white">Column mapping (detected headers — adjust if wrong)</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Col</th>
                        <th>Group header</th>
                        <th>Sub header</th>
                        <th>Import as</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($columns as $column)
                        @php
                            $selectedField = collect($fields)->search($column['index']);
                            $selectedField = $selectedField === false ? '' : $selectedField;
                        @endphp
                        <tr class="{{ $column['default_field'] === '' ? 'table-warning' : '' }}">
                            <td class="font-monospace">{{ $column['letter'] }}</td>
                            <td class="small">{{ $column['group'] !== '' ? $column['group'] : '—' }}</td>
                            <td class="small">{{ $column['sub'] !== '' ? $column['sub'] : '—' }}</td>
                            <td style="min-width: 260px;">
                                <select class="form-select form-select-sm" name="fields[{{ $column['index'] }}]">
                                    @foreach ($options as $value => $label)
                                        <option value="{{ $value }}" @selected($selectedField === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-body small text-muted">
            Highlighted rows were not recognized automatically — set them to “ignore” or map them manually.
            First Name and Last Name are required.
        </div>
    </div>
</form>
@endsection
