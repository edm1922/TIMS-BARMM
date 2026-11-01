@extends('layouts.app')

@section('title', 'Import batch #'.$batch->id)

@section('content')
@php
    $outcomes = ['new', 'update', 'unchanged', 'duplicate', 'review', 'invalid'];

    $outcomeLabels = [
        'new' => 'New',
        'update' => 'Update',
        'unchanged' => 'Unchanged',
        'duplicate' => 'Duplicate',
        'review' => 'Needs review',
        'invalid' => 'Invalid',
    ];

    $statusMeta = [
        'uploaded' => ['label' => 'Uploaded — preview not run', 'badge' => 'text-bg-secondary'],
        'previewed' => ['label' => 'Dry-run complete — awaiting confirmation', 'badge' => 'text-bg-warning'],
        'applied' => ['label' => 'Applied', 'badge' => 'text-bg-success'],
        'cancelled' => ['label' => 'Cancelled', 'badge' => 'text-bg-secondary'],
        'failed' => ['label' => 'Failed', 'badge' => 'text-bg-danger'],
    ][$batch->status] ?? ['label' => ucfirst($batch->status), 'badge' => 'text-bg-secondary'];

    $hasSummary = in_array($batch->status, ['previewed', 'applied', 'failed'], true);
    $summaryCount = fn (string $outcome): int => (int) ($summary[$outcome] ?? 0);

    // Default-decisions projection of the apply label (server counts only).
    $projectedInserts = $summaryCount('new') + $summaryCount('duplicate') + $summaryCount('review');
    $projectedUpdates = $summaryCount('update');

    $applyCounts = [
        'new' => $summaryCount('new'),
        'update' => $summaryCount('update'),
        'duplicate' => $summaryCount('duplicate'),
        'review' => $summaryCount('review'),
    ];
@endphp

<div class="import-preview">
    {{-- A. Import header --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h1 class="h4 mb-0">Import batch #{{ $batch->id }}</h1>
                        <span class="badge rounded-pill {{ $statusMeta['badge'] }}">{{ $statusMeta['label'] }}</span>
                    </div>
                    <div class="text-muted small">Excel import into the beneficiary register.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('imports.index') }}">Batches</a>
                    @if ($hasSummary)
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('imports.report', $batch) }}">Download row report (CSV)</a>
                    @endif
                    @if ($batch->status === 'uploaded')
                        <a class="btn btn-primary btn-sm" href="{{ route('imports.edit', $batch) }}">Continue to mapping</a>
                    @endif
                </div>
            </div>

            <dl class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-0 imp-meta mb-0 mt-3">
                <div class="col">
                    <dt>Excel file</dt>
                    <dd class="text-truncate" title="{{ $batch->original_filename }}">{{ $batch->original_filename }}</dd>
                </div>
                <div class="col">
                    <dt>Worksheet</dt>
                    <dd><code>{{ $batch->worksheet }}</code></dd>
                </div>
                <div class="col">
                    <dt>Header row</dt>
                    <dd>{{ $batch->header_row }}</dd>
                </div>
                <div class="col">
                    <dt>Beneficiary profiling date</dt>
                    <dd>
                        @if ($batch->profiling_date !== null)
                            <strong>{{ $batch->profiling_date->format('F j, Y') }}</strong>
                            <span class="text-muted small">({{ $batch->profiling_date->format('F Y') }})</span>
                        @else
                            <span class="text-muted">Not set — uses the registration timestamp</span>
                        @endif
                    </dd>
                </div>
                <div class="col">
                    <dt>Uploaded by</dt>
                    <dd>{{ $batch->user?->name ?: '—' }}</dd>
                </div>
                <div class="col">
                    <dt>Uploaded</dt>
                    <dd title="{{ $batch->created_at }}">{{ $batch->created_at?->diffForHumans() }}</dd>
                </div>
                <div class="col">
                    <dt>Target batch</dt>
                    <dd>
                        @if ($batch->beneficiaryBatch)
                            <a href="{{ route('beneficiary-batches.show', $batch->beneficiaryBatch) }}">{{ $batch->beneficiaryBatch->name }}</a>
                        @else
                            <span class="text-muted">Unassigned</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- B. Status banner --}}
    @if ($batch->status === 'previewed')
        <div class="alert alert-success d-flex gap-3 align-items-start" role="status">
            <svg class="flex-shrink-0 mt-1" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
            <div>
                <strong>Dry-run completed successfully. {{ number_format($batch->rows_read) }} rows analyzed.</strong>
                <div class="small mb-0">
                    No changes have been written to the beneficiaries table. Every row is waiting for your decisions below;
                    the import is ready for administrator confirmation.
                    @if ($batch->profiling_date !== null)
                        <div class="mt-1">
                            Imported beneficiaries will be recorded under
                            <strong>{{ $batch->profiling_date->format('F Y') }}</strong>
                            (profiling date {{ $batch->profiling_date->format('F j, Y') }}) in the registration trend.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($batch->status === 'applied')
        <div class="alert alert-success d-flex gap-3 align-items-start" role="status">
            <svg class="flex-shrink-0 mt-1" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
            <div>
                <strong>Applied.</strong>
                <div class="small mb-0">
                    {{ number_format($batch->inserted) }} inserted, {{ number_format($batch->updated) }} updated,
                    {{ number_format($batch->skipped) }} skipped, {{ number_format($batch->flagged) }} created records
                    were flagged as duplicate candidates.
                    <a href="{{ route('beneficiaries.index') }}">Browse beneficiaries</a>
                </div>
            </div>
        </div>
    @elseif ($batch->status === 'failed')
        <div class="alert alert-danger d-flex gap-3 align-items-start" role="alert">
            <svg class="flex-shrink-0 mt-1" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
            <div>
                <strong>Apply failed and the whole batch was rolled back.</strong>
                <div class="small mb-0">Nothing was written. Check the row report for the failing Excel row, fix the workbook, and upload again.</div>
            </div>
        </div>
    @elseif ($batch->status === 'cancelled')
        <div class="alert alert-secondary" role="status">
            This batch was cancelled; the uploaded file was deleted. Nothing was written to the beneficiaries table.
        </div>
    @elseif ($batch->status === 'uploaded')
        <div class="alert alert-info" role="status">
            This file is uploaded but no dry-run has run yet. Continue to mapping to inspect the worksheet and preview the rows.
        </div>
    @endif

    @if (($failedRows ?? collect())->isNotEmpty())
        <div class="alert alert-danger" role="alert">
            <strong>{{ $failedRows->count() }} row(s) could not be applied and were left out of the import.</strong>
            <div class="small mt-1">These rows were kept for follow-up; no partial changes were made for them.</div>
            <ul class="small mb-0 mt-2">
                @foreach ($failedRows as $failed)
                    <li class="mb-2">
                        <div>
                            <strong>Excel row {{ $failed['row']->excel_row }}</strong> —
                            {{ $failed['row']->apply_error }}
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                            @if ($failed['name'] !== '')
                                <span class="text-muted">{{ $failed['name'] }}</span>
                            @endif
                            @if ($failed['gov_id'] !== '')
                                <span class="badge text-bg-light border">ID: {{ $failed['gov_id'] }}</span>
                            @endif
                            @if ($failed['birthdate'])
                                <span class="text-muted">Birthdate: {{ $failed['birthdate'] }}</span>
                            @endif
                            @if ($failed['view_duplicate_url'])
                                <a class="btn btn-sm btn-link p-0" href="{{ $failed['view_duplicate_url'] }}">View Duplicate</a>
                            @endif
                            @if ($failed['edit_url'])
                                <a class="btn btn-sm btn-link p-0" href="{{ $failed['edit_url'] }}">Edit</a>
                            @endif
                            @if ($failed['bypass_url'])
                                <form method="POST" action="{{ $failed['bypass_url'] }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-link p-0 text-danger"
                                            onclick="return confirm('Import Excel row {{ $failed['row']->excel_row }} with this value left blank? The record will be flagged as missing the field and can be completed later.')">
                                        Bypass leave blank
                                    </button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($batch->status === 'previewed' && $missingFromImport !== null && $missingFromImport > 0)
        <div class="alert alert-warning d-flex gap-3 align-items-start" role="alert">
            <svg class="flex-shrink-0 mt-1" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            <div class="small mb-0">
                {{ number_format($missingFromImport) }} active {{ Str::plural('record', $missingFromImport) }} in the system
                (with an <code>ID NUMBER</code>) {{ $missingFromImport === 1 ? 'is' : 'are' }} not present in this file.
                They are never deleted or archived automatically — follow up manually if that is unexpected.
            </div>
        </div>
    @endif

    @if ($hasSummary)
        {{-- C. Import overview --}}
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
                <div class="card imp-primary h-100">
                    <div class="card-body">
                        <div class="text-muted small">Rows read</div>
                        <div class="imp-primary-number">{{ number_format($batch->rows_read) }}</div>
                        <div class="text-muted small">Rows analyzed in this workbook</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card imp-primary h-100">
                    <div class="card-body">
                        <div class="text-muted small">New records</div>
                        <div class="imp-primary-number imp-accent-new">{{ number_format($summaryCount('new')) }}</div>
                        <div class="text-muted small">Ready to insert as beneficiaries</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card imp-primary h-100">
                    <div class="card-body">
                        <div class="text-muted small">Needs review</div>
                        <div class="imp-primary-number imp-accent-review">{{ number_format($summaryCount('review')) }}</div>
                        <div class="text-muted small">Held until you decide below</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card imp-secondary mb-3">
            <div class="card-body d-flex flex-wrap gap-3 gap-md-4 align-items-center">
                <span class="imp-secondary-item"><span class="imp-dot imp-accent-update"></span>Updates <strong>{{ number_format($summaryCount('update')) }}</strong></span>
                <span class="imp-secondary-item"><span class="imp-dot imp-accent-unchanged"></span>Unchanged <strong>{{ number_format($summaryCount('unchanged')) }}</strong></span>
                <span class="imp-secondary-item"><span class="imp-dot imp-accent-duplicate"></span>Duplicate candidates <strong>{{ number_format($summaryCount('duplicate')) }}</strong></span>
                <span class="imp-secondary-item"><span class="imp-dot imp-accent-invalid"></span>Invalid rows <strong>{{ number_format($summaryCount('invalid')) }}</strong></span>
            </div>
        </div>

        @if ($warningGroups->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small">Recurring validation notes in this file</span>
                    <span class="text-muted small">informational</span>
                </div>
                <div class="card-body py-2">
                    @foreach ($warningGroups as $group)
                        <div class="d-flex gap-2 small {{ ! $loop->last ? 'mb-1' : '' }}">
                            <span class="badge {{ $group['severity'] === 'error' ? 'text-bg-danger' : 'text-bg-warning' }} flex-shrink-0">
                                {{ $group['severity'] === 'error' ? 'error' : 'note' }}
                            </span>
                            <span class="text-truncate" title="{{ $group['text'] }}">{{ \App\Services\Import\ImportMessageText::friendly(['severity' => $group['severity'], 'text' => $group['text']]) }}</span>
                            <span class="text-muted ms-auto flex-shrink-0">{{ number_format($group['count']) }}×</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (! empty($invalidOptions))
            {{-- Unrecognized option values from the dry-run --}}
            <div class="card mb-3 imp-invalid-options">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold">Unrecognized option values</span>
                    <span class="text-muted small">Nothing changes until you resolve these values.</span>
                </div>
                <div class="card-body">
                    @foreach ($invalidOptions as $option)
                        <div class="imp-invalid-row">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                <div>
                                    <span class="badge text-bg-warning me-1">{{ $option['field_label'] }}</span>
                                    <strong>{{ $option['value'] }}</strong>
                                    <span class="text-muted small">— {{ number_format($option['count']) }} affected {{ Str::plural('row', $option['count']) }}</span>
                                    @if ($option['applied'])
                                        <span class="badge text-bg-secondary ms-1">already applied</span>
                                    @endif
                                    <div class="text-muted small mt-1">
                                        This value is not part of the standard {{ $option['field_label'] }} options and is kept exactly as written until resolved.
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-md-5">
                                    <form method="POST" action="{{ route('imports.invalid-options.resolve', $batch) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="field" value="{{ $option['field'] }}">
                                        <input type="hidden" name="value" value="{{ $option['value'] }}">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            Add “{{ $option['value'] }}” as a new option
                                        </button>
                                    </form>
                                </div>
                                <div class="col-md-7">
                                    <form method="POST" action="{{ route('imports.invalid-options.resolve', $batch) }}" class="d-flex gap-2 flex-wrap">
                                        @csrf
                                        <input type="hidden" name="action" value="replace">
                                        <input type="hidden" name="field" value="{{ $option['field'] }}">
                                        <input type="hidden" name="value" value="{{ $option['value'] }}">
                                        <select class="form-select form-select-sm flex-grow-1" name="replacement" required aria-label="Replacement option for {{ $option['value'] }}">
                                            <option value="">Replace with…</option>
                                            @foreach (\App\Support\BeneficiaryOptions::values($option['field']) as $optionValue)
                                                <option value="{{ $optionValue }}">{{ \App\Support\BeneficiaryOptions::label($option['field'], $optionValue) ?? $optionValue }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">Apply replacement</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if (($duplicateSummary['potential_duplicates'] ?? 0) > 0)
            {{-- Duplicate review: existing record vs imported row --}}
            <div class="card mb-3 imp-duplicates" id="duplicates">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold">Potential duplicate registrations</span>
                    <span class="text-muted small">
                        {{ number_format($duplicateSummary['pending']) }} awaiting review
                        · unresolved government-ID duplicates are skipped on apply
                    </span>
                </div>
                <div class="card-body">
                    <div class="imp-dup-summary">
                        <span class="imp-dup-chip"><strong>{{ number_format($duplicateSummary['total']) }}</strong> rows processed</span>
                        <span class="imp-dup-chip"><strong>{{ number_format($duplicateSummary['ready']) }}</strong> ready to import</span>
                        <span class="imp-dup-chip imp-dup-chip-warning"><strong>{{ number_format($duplicateSummary['potential_duplicates']) }}</strong> potential duplicates</span>
                        <span class="imp-dup-chip"><strong>{{ number_format($duplicateSummary['pending']) }}</strong> pending review</span>
                        <span class="imp-dup-chip"><strong>{{ number_format($duplicateSummary['approved']) }}</strong> approved</span>
                        <span class="imp-dup-chip"><strong>{{ number_format($duplicateSummary['skipped']) }}</strong> skipped</span>
                        <span class="imp-dup-chip"><strong>{{ number_format($duplicateSummary['imported']) }}</strong> imported</span>
                        @if ($duplicateSummary['failed'] > 0)
                            <span class="imp-dup-chip imp-dup-chip-danger"><strong>{{ number_format($duplicateSummary['failed']) }}</strong> failed</span>
                        @endif
                    </div>

                    @foreach ($duplicates as $duplicate)
                        @php
                            $row = $duplicate['row'];
                            $matched = $duplicate['matched'];
                            $imported = $duplicate['imported'];
                            $statusBadges = [
                                'pending' => ['Pending review', 'text-bg-warning'],
                                'approved_new' => ['Approved — new registration', 'text-bg-success'],
                                'approved_update' => ['Approved — update existing', 'text-bg-success'],
                                'approved_skip' => ['Approved — already registered', 'text-bg-secondary'],
                                'imported' => ['Imported', 'text-bg-success'],
                                'skipped' => ['Skipped — already registered', 'text-bg-secondary'],
                                'failed' => ['Not applied — follow up', 'text-bg-danger'],
                            ][$duplicate['status']] ?? ['Pending review', 'text-bg-warning'];

                            $importedName = trim(($imported['last_name'] ?? '').', '.($imported['first_name'] ?? ''));
                            $importedName = rtrim($importedName, ', ');
                            $existingName = $matched ? trim($matched->last_name.', '.$matched->first_name) : '—';
                            $fieldsDiffer = fn ($a, $b): bool => (string) ($a ?? '') !== (string) ($b ?? '');
                            $canDecide = $batch->status === 'previewed' && ! $row->applied;
                        @endphp
                        <div class="imp-dup-row" id="duplicate-row-{{ $row->id }}">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <span class="badge text-bg-warning me-1">
                                        {{ $row->duplicate_kind === 'government_id' ? 'Government ID match' : ($row->duplicate_kind === 'name_birthdate' ? 'Name + birthdate match' : 'Name match') }}
                                    </span>
                                    <strong>Excel row {{ $row->excel_row }}</strong>
                                    <span class="text-muted small">· {{ $importedName !== '' ? $importedName : 'Unnamed row' }}</span>
                                </div>
                                <span class="badge {{ $statusBadges[1] }}">{{ $statusBadges[0] }}</span>
                            </div>

                            @if ($row->apply_error !== null)
                                <div class="alert alert-danger py-2 small mb-2">{{ $row->apply_error }}</div>
                            @endif

                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="imp-dup-panel">
                                        <div class="imp-dup-title">Existing registered beneficiary</div>
                                        @if ($matched)
                                            <dl class="imp-dup-list mb-0">
                                                <div><dt>Name</dt><dd>{{ $existingName }}</dd></div>
                                                <div><dt>Code</dt><dd class="font-monospace">{{ $matched->beneficiary_code }}</dd></div>
                                                <div><dt>Government ID</dt><dd class="{{ $fieldsDiffer($matched->source_id_no, $imported['source_id_no']) ? 'text-danger fw-semibold' : '' }}">{{ $matched->source_id_no ?: '—' }}</dd></div>
                                                <div><dt>Birthdate</dt><dd class="{{ $fieldsDiffer($matched->birthdate?->toDateString(), $imported['birthdate']) ? 'text-danger fw-semibold' : '' }}">{{ $matched->birthdate?->toDateString() ?? '—' }}</dd></div>
                                                <div><dt>Batch</dt><dd>{{ $matched->beneficiaryBatch?->name ?? 'Unassigned' }}</dd></div>
                                                <div><dt>Status</dt><dd>{{ $matched->archived_at !== null ? 'Archived' : 'Active' }}</dd></div>
                                            </dl>
                                        @else
                                            <div class="text-muted small">Existing record details are not available.</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="imp-dup-panel">
                                        <div class="imp-dup-title">Imported row (from Excel)</div>
                                        <dl class="imp-dup-list mb-0">
                                            <div><dt>Name</dt><dd>{{ $importedName !== '' ? $importedName : '—' }}</dd></div>
                                            <div><dt>Excel row</dt><dd>{{ $row->excel_row }}</dd></div>
                                            <div><dt>Government ID</dt><dd class="{{ $matched && $fieldsDiffer($matched->source_id_no, $imported['source_id_no']) ? 'text-danger fw-semibold' : '' }}">{{ $imported['source_id_no'] ?: '—' }}</dd></div>
                                            <div><dt>Birthdate</dt><dd class="{{ $matched && $fieldsDiffer($matched->birthdate?->toDateString(), $imported['birthdate']) ? 'text-danger fw-semibold' : '' }}">{{ $imported['birthdate'] ?: '—' }}</dd></div>
                                            <div><dt>Location</dt><dd>{{ trim(implode(', ', array_filter([$imported['barangay'], $imported['city_municipality'], $imported['province']]))) ?: '—' }}</dd></div>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            @if ($canDecide)
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <form method="POST" action="{{ route('imports.duplicates.resolve', ['importBatch' => $batch, 'importRow' => $row->id]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="skip">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                onclick="return confirm('Mark Excel row {{ $row->excel_row }} as already registered? It will be skipped when you apply.')">
                                            Mark as already registered (skip)
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('imports.duplicates.resolve', ['importBatch' => $batch, 'importRow' => $row->id]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="update_existing">
                                        <button type="submit" class="btn btn-sm btn-outline-primary"
                                                @disabled($matched === null)
                                                onclick="return confirm('Update the existing beneficiary record with the imported values from Excel row {{ $row->excel_row }}?')">
                                            Update existing record
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('imports.duplicates.resolve', ['importBatch' => $batch, 'importRow' => $row->id]) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="approve_new">
                                        <button type="submit" class="btn btn-sm btn-outline-success"
                                                @disabled($duplicate['hard_blocked'])
                                                onclick="return confirm('Approve Excel row {{ $row->excel_row }} as a separate new registration? Only do this if the existing record is a different person.')">
                                            Approve as new registration
                                        </button>
                                    </form>
                                </div>
                                @if ($duplicate['hard_blocked'])
                                    <div class="text-muted small mt-1">
                                        @if (($duplicate['in_file_row'] ?? null) !== null)
                                            This government ID also appears in Excel row {{ $duplicate['in_file_row'] }} of the same
                                            workbook, so it cannot be imported twice. Mark this row as already registered.
                                        @else
                                            This government ID is unique on this server, so a separate new record cannot be added.
                                            Choose <em>Update existing record</em> or <em>Mark as already registered</em>.
                                        @endif
                                    </div>
                                @endif
                            @elseif ($row->reviewed_at !== null)
                                <div class="text-muted small mt-2">
                                    Reviewed {{ $row->reviewed_at->diffForHumans() }}@if ($row->reviewer?->name) by {{ $row->reviewer?->name }}@endif.
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($batch->status === 'previewed')
            {{-- D/E. Decisions and confirmation --}}
            <form method="POST" action="{{ route('imports.apply', $batch) }}" id="apply-form"
                  data-counts="{{ json_encode($applyCounts) }}">
                @csrf
                <div class="card imp-decisions mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="fw-semibold">Decisions</span>
                        <span class="text-muted small">
                            @if ($batch->profiling_date !== null)
                                Profiling date: <strong>{{ $batch->profiling_date->format('F j, Y') }}</strong>
                                <span aria-hidden="true">·</span>
                            @endif
                            Nothing is written until you apply.
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="imp-decision">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0" for="d-new">New records</label>
                                        <span class="badge text-bg-light border">{{ number_format($summaryCount('new')) }}</span>
                                    </div>
                                    <select class="form-select form-select-sm" id="d-new" name="decisions[new]" data-imp-select>
                                        <option value="insert">Insert as new beneficiaries</option>
                                        <option value="skip">Skip these rows</option>
                                    </select>
                                    <div class="form-text" data-imp-hint="new">Inserts each new row as a beneficiary record.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="imp-decision">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0" for="d-update">Update existing records</label>
                                        <span class="badge text-bg-light border">{{ number_format($summaryCount('update')) }}</span>
                                    </div>
                                    <select class="form-select form-select-sm" id="d-update" name="decisions[update]" data-imp-select>
                                        <option value="update">Update the matched records</option>
                                        <option value="skip">Skip these rows</option>
                                    </select>
                                    <div class="form-text" data-imp-hint="update">Applies the file values to the records already matched by ID number.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="imp-decision">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0" for="d-duplicate">Duplicate candidates</label>
                                        <span class="badge text-bg-light border">{{ number_format($summaryCount('duplicate')) }}</span>
                                    </div>
                                    <select class="form-select form-select-sm" id="d-duplicate" name="decisions[duplicate]" data-imp-select>
                                        <option value="skip">Skip (leave for manual review)</option>
                                        <option value="insert">Import as separate person</option>
                                    </select>
                                    <div class="form-text" data-imp-hint="duplicate">Keeps these rows for manual review instead of creating records.</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="imp-decision">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="form-label fw-semibold mb-0" for="d-review">Needs-review rows</label>
                                        <span class="badge text-bg-light border">{{ number_format($summaryCount('review')) }}</span>
                                    </div>
                                    <select class="form-select form-select-sm" id="d-review" name="decisions[review]" data-imp-select>
                                        <option value="skip">Skip</option>
                                        <option value="insert">Import anyway (acknowledged)</option>
                                    </select>
                                    <div class="form-text" data-imp-hint="review">Skips rows flagged for review until they are corrected.</div>
                                </div>
                            </div>
                        </div>

                        <hr class="my-3">

                        <div class="form-check imp-confirm mb-0">
                            <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" required>
                            <label class="form-check-label" for="confirm">
                                I reviewed the import summary and the decisions above, and confirm applying this import.
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card imp-actions mb-3">
                    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="text-muted small" data-imp-gate>
                            Confirm the summary above to unlock applying.
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            @can('apply', $batch)
                                <button class="btn btn-primary" type="submit" id="apply-button" disabled>
                                    <span data-imp-apply-label>Apply confirmed changes</span>
                                </button>
                            @else
                                <button class="btn btn-primary" type="button" disabled>Apply (administrators only)</button>
                            @endcan
                            @can('cancel', $batch)
                                <button class="btn btn-outline-danger" type="submit" form="cancel-batch"
                                        onclick="return confirm('Cancel this batch and delete the uploaded file?')">Cancel batch</button>
                            @endcan
                        </div>
                    </div>
                </div>
            </form>

            @can('cancel', $batch)
                <form method="POST" action="{{ route('imports.cancel', $batch) }}" id="cancel-batch" class="d-none">@csrf</form>
            @endcan
        @endif

        {{-- F. Preview records --}}
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">Preview records</span>
                <div class="d-flex gap-1 flex-wrap">
                    <a class="btn btn-sm {{ $outcomeFilter === '' ? 'btn-secondary' : 'btn-outline-secondary' }}"
                       href="{{ route('imports.show', $batch) }}">All</a>
                    @foreach ($outcomes as $outcome)
                        <a class="btn btn-sm {{ $outcomeFilter === $outcome ? 'btn-secondary' : 'btn-outline-secondary' }}"
                           href="{{ route('imports.show', $batch) }}?outcome={{ $outcome }}">{{ $outcomeLabels[$outcome] }}</a>
                    @endforeach
                </div>
            </div>
            <div class="table-responsive imp-table-wrap">
                <table class="table table-sm mb-0 align-middle imp-table">
                    <thead>
                        <tr>
                            <th class="text-nowrap">Excel row</th>
                            <th>Beneficiary name</th>
                            <th class="text-nowrap">Birthdate</th>
                            <th class="text-nowrap">Age status</th>
                            <th class="text-nowrap">ID Number</th>
                            <th class="text-nowrap">Import outcome</th>
                            <th>Validation / warnings</th>
                            <th class="text-nowrap">Matched / candidates</th>
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
                                $messages = $row->messages ?? [];
                                $warningCount = count(array_filter($messages, fn ($m) => ($m['severity'] ?? '') !== 'error'));
                                $errorCount = count(array_filter($messages, fn ($m) => ($m['severity'] ?? '') === 'error'));
                                $name = trim(($payload['last_name'] ?? '').', '.($payload['first_name'] ?? '').' '.($payload['middle_name'] ?? ''));
                                $name = rtrim($name, ', ');
                            @endphp
                            <tr id="{{ 'import-row-'.$row->id }}">
                                <td class="font-monospace small text-nowrap">{{ $row->excel_row }}</td>
                                <td class="small imp-name">
                                    @if ($name !== '')
                                        <strong>{{ $name }}</strong>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small text-nowrap">{{ $bd ?? (($payload['birthdate_raw'] ?? null) ? 'raw: '.$payload['birthdate_raw'] : '—') }}</td>
                                <td class="text-nowrap">@include('partials.age-badge', ['status' => $ageStatus])</td>
                                <td class="small font-monospace text-nowrap">{{ $payload['source_id_number'] ?? '—' }}</td>
                                <td class="text-nowrap">
                                    <span class="badge {{ ['new' => 'text-bg-success', 'update' => 'text-bg-primary', 'unchanged' => 'text-bg-secondary', 'duplicate' => 'text-bg-warning', 'review' => 'text-bg-info', 'invalid' => 'text-bg-danger'][$row->outcome] ?? 'text-bg-secondary' }}">
                                        {{ $outcomeLabels[$row->outcome] ?? $row->outcome }}
                                    </span>
                                </td>
                                <td class="small imp-warnings">
                                    @if ($messages === [])
                                        <span class="text-muted">—</span>
                                    @else
                                        <details class="imp-details">
                                            <summary class="text-decoration-none">
                                                @if ($errorCount > 0)
                                                    <span class="badge text-bg-danger">{{ $errorCount }} error{{ $errorCount === 1 ? '' : 's' }}</span>
                                                @endif
                                                @if ($warningCount > 0)
                                                    <span class="badge text-bg-warning">{{ $warningCount }} note{{ $warningCount === 1 ? '' : 's' }}</span>
                                                @endif
                                            </summary>
                                            <div class="mt-2">
                                                @foreach ($messages as $message)
                                                    <div class="mb-1 {{ ($message['severity'] ?? '') === 'error' ? 'text-danger' : 'text-warning-emphasis' }}" title="{{ $message['text'] ?? '' }}">
                                                        {{ \App\Services\Import\ImportMessageText::friendly($message) }}
                                                    </div>
                                                @endforeach
                                                <div class="text-muted small mt-1">
                                                    Original row data is preserved; nothing is normalized or discarded silently.
                                                </div>
                                            </div>
                                        </details>
                                    @endif
                                </td>
                                <td class="small">
                                    @if ($row->matched_beneficiary_id)
                                        <a class="font-monospace" href="{{ route('beneficiaries.show', $row->matched_beneficiary_id) }}">#{{ $row->matched_beneficiary_id }}</a>
                                    @endif
                                    @foreach ($row->candidate_ids ?? [] as $id)
                                        <a class="font-monospace" href="{{ route('beneficiaries.show', $id) }}">{{ $codeLookup[$id] ?? '#'.$id }}</a>{{ ! $loop->last ? ', ' : '' }}
                                    @endforeach
                                    @if (! $row->matched_beneficiary_id && empty($row->candidate_ids))
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No rows{{ $outcomeFilter !== '' ? ' with this outcome' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-center mt-3">
            {{ $rows->links() }}
        </div>
    @endif
</div>

<style>
    .import-preview .imp-meta dt {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #6c757d;
        margin-bottom: .15rem;
    }
    .import-preview .imp-meta dd {
        font-size: .875rem;
        margin-bottom: .75rem;
        padding-right: 1rem;
    }
    .import-preview .imp-primary {
        border: 1px solid #e9ecef;
        border-left: 3px solid #dee2e6;
    }
    .import-preview .imp-primary-number {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.1;
        letter-spacing: -.02em;
    }
    .import-preview .imp-accent-new { color: #198754; }
    .import-preview .imp-accent-review { color: #b45309; }
    .import-preview .imp-secondary { border: 1px solid #e9ecef; }
    .import-preview .imp-secondary-item { font-size: .85rem; color: #495057; }
    .import-preview .imp-secondary-item strong { color: #212529; }
    .import-preview .imp-dot {
        display: inline-block;
        width: .55rem;
        height: .55rem;
        border-radius: 50%;
        margin-right: .35rem;
        vertical-align: baseline;
    }
    .import-preview .imp-accent-update { background: #0d6efd; }
    .import-preview .imp-accent-unchanged { background: #adb5bd; }
    .import-preview .imp-accent-duplicate { background: #fd7e14; }
    .import-preview .imp-accent-invalid { background: #dc3545; }

    .import-preview .imp-decisions { border: 1px solid #dbe2ea; box-shadow: 0 0 .5rem rgba(13, 110, 253, .06); }
    .import-preview .imp-decisions .card-header { border-bottom: 1px solid #eef2f6; }
    .import-preview .imp-decision {
        border: 1px solid #eef2f6;
        border-radius: .5rem;
        padding: .75rem;
        background: #fcfdff;
        height: 100%;
    }
    .import-preview .imp-actions { border: 1px solid #dbe2ea; background: #fbfcfe; }
    .import-preview .imp-confirm .form-check-label { font-size: .9rem; }

    .import-preview .imp-duplicates { border: 1px solid #f3d9b1; }
    .import-preview .imp-dup-summary { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .75rem; }
    .import-preview .imp-dup-chip {
        font-size: .75rem; color: #475569; background: #f6f7fb;
        border: 1px solid #e5e7eb; border-radius: 999px; padding: .2rem .6rem;
    }
    .import-preview .imp-dup-chip-warning { background: #fffbeb; border-color: #fde68a; }
    .import-preview .imp-dup-chip-danger { background: #fef2f2; border-color: #fecaca; }
    .import-preview .imp-dup-row {
        border: 1px solid #eef2f6; border-radius: .5rem; padding: .75rem; background: #fffdf9;
    }
    .import-preview .imp-dup-row + .imp-dup-row { margin-top: .6rem; }
    .import-preview .imp-dup-panel { border: 1px solid #eef2f6; border-radius: .4rem; padding: .5rem .65rem; height: 100%; background: #fff; }
    .import-preview .imp-dup-title { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-bottom: .35rem; }
    .import-preview .imp-dup-list { font-size: .82rem; }
    .import-preview .imp-dup-list > div { display: flex; gap: .5rem; padding: .1rem 0; }
    .import-preview .imp-dup-list dt { width: 7.5rem; flex-shrink: 0; font-weight: 500; color: #64748b; }
    .import-preview .imp-dup-list dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }

    .import-preview .imp-invalid-options { border: 1px solid #f0e0b8; }
    .import-preview .imp-invalid-row {
        border: 1px solid #eef2f6;
        border-radius: .5rem;
        padding: .75rem;
        background: #fffdf7;
    }
    .import-preview .imp-invalid-row + .imp-invalid-row { margin-top: .5rem; }

    .import-preview .imp-table-wrap { max-height: 70vh; overflow: auto; }
    .import-preview .imp-table {
        min-width: 1100px;
    }
    .import-preview .imp-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8f9fa;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #6c757d;
    }
    .import-preview .imp-table tbody td { vertical-align: middle; }
    .import-preview .imp-name { min-width: 190px; max-width: 240px; }
    .import-preview .imp-warnings { min-width: 220px; max-width: 330px; }
    .import-preview .imp-warnings .imp-details summary { cursor: pointer; }
    .import-preview .imp-warnings .imp-details > div { max-width: 32rem; white-space: normal; }
</style>

@push('scripts')
<script>
(function () {
    'use strict';

    var form = document.getElementById('apply-form');

    if (!form) {
        return;
    }

    var confirmBox = document.getElementById('confirm');
    var applyButton = document.getElementById('apply-button');
    var applyLabel = form.querySelector('[data-imp-apply-label]');
    var gate = form.querySelector('[data-imp-gate]');
    var counts = {};

    try {
        counts = JSON.parse(form.dataset.counts || '{}');
    } catch (e) {
        counts = {};
    }

    var selects = form.querySelectorAll('[data-imp-select]');
    var hints = {
        new: { insert: 'Inserts each new row as a beneficiary record.', skip: 'Leaves these rows out of the import.' },
        update: { update: 'Applies the file values to the records already matched by ID number.', skip: 'Leaves the matched records unchanged.' },
        duplicate: { skip: 'Keeps these rows for manual review instead of creating records.', insert: 'Creates these rows as separate people.' },
        review: { skip: 'Skips rows flagged for review until they are corrected.', insert: 'Imports these rows even though they need review.' }
    };

    function setSelectHint(select) {
        var field = select.name.replace('decisions[', '').replace(']', '');
        var hint = form.querySelector('[data-imp-hint="' + field + '"]');

        if (hint && hints[field] && hints[field][select.value]) {
            hint.textContent = hints[field][select.value];
        }
    }

    function projectedLabel() {
        var inserts = 0;
        var updates = 0;

        selects.forEach(function (select) {
            var field = select.name.replace('decisions[', '').replace(']', '');

            if (field === 'new' && select.value === 'insert') {
                inserts += Number(counts.new || 0);
            } else if (field === 'update' && select.value === 'update') {
                updates += Number(counts.update || 0);
            } else if ((field === 'duplicate' || field === 'review') && select.value === 'insert') {
                inserts += Number(counts[field] || 0);
            }
        });

        var parts = [];
        if (inserts > 0) {
            parts.push('Apply ' + inserts + ' new record' + (inserts === 1 ? '' : 's'));
        }
        if (updates > 0) {
            parts.push((parts.length ? 'and ' : 'Apply ') + updates + ' update' + (updates === 1 ? '' : 's'));
        }

        applyLabel.textContent = parts.length ? parts.join(' ') : 'Apply confirmed changes';
    }

    function refreshGate() {
        var confirmed = confirmBox ? confirmBox.checked : false;

        if (applyButton) {
            applyButton.disabled = !confirmed;
        }

        if (gate) {
            gate.textContent = confirmed
                ? 'Ready to apply. This writes to the beneficiaries table in one transaction.'
                : 'Confirm the summary above to unlock applying.';
        }
    }

    selects.forEach(function (select) {
        setSelectHint(select);
        select.addEventListener('change', function () {
            setSelectHint(select);
            projectedLabel();
        });
    });

    if (confirmBox) {
        confirmBox.addEventListener('change', refreshGate);
    }

    form.addEventListener('submit', function () {
        // Prevent accidental duplicate submissions.
        if (applyButton) {
            applyButton.disabled = true;
            applyLabel.textContent = 'Applying…';
        }
    });

    projectedLabel();
    refreshGate();
})();
</script>
@endpush
@endsection
