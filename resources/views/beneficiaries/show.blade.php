@extends('layouts.app')

@section('title', $beneficiary->full_name)

@section('content')
@php
    $ageStatus = $beneficiary->ageStatus();
    $fields = [
        'Beneficiary ID' => '<span class="font-monospace">' . e($beneficiary->beneficiary_code) . '</span>',
        'Age status' => view('partials.age-badge', ['status' => $ageStatus])->render(),
        'Birthdate' => $beneficiary->birthdate?->format('Y-m-d') . ($ageStatus === 'unknown' ? ' <span class="text-warning small">(missing or not yet valid)</span>' : ''),
        'Age (computed)' => $beneficiary->computedAge() ?? '—',
        'Age (source)' => $beneficiary->age_source ?? '—',
        'Sex' => $beneficiary->sex ?? '—',
        'Civil status' => $beneficiary->civil_status ?? '—',
        'Address' => $beneficiary->display_address ?: '—',
        'Province / District' => trim(($beneficiary->province ?? '') . ' / ' . ($beneficiary->district ?? ''), ' /'),
        'Government ID' => trim(($beneficiary->id_type_label ?? '—') . ' ' . ($beneficiary->source_id_no ?? '')),
        'COS No.' => $beneficiary->cos_no ?? '—',
        'DOLE ID NUMBER (source)' => $beneficiary->source_id_number ?? '—',
        'Batch' => $beneficiary->beneficiaryBatch
            ? view('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])->render()
            : '<span class="text-muted">Unassigned</span>',
        'Contact no.' => $beneficiary->contact_no ?? '—',
        'Beneficiary type' => \App\Support\BeneficiaryOptions::label('beneficiary_type', $beneficiary->beneficiary_type) ?? '—',
        'Beneficiary signify' => \App\Support\BeneficiaryOptions::label('beneficiary_signify', $beneficiary->beneficiary_signify) ?? '—',
        'Occupation' => $beneficiary->occupation_label ?? '—',
        'Mode of payment' => \App\Support\BeneficiaryOptions::label('mode_of_payment', $beneficiary->mode_of_payment) ?? '—',
        'Avg. monthly income' => $beneficiary->avg_monthly_income !== null ? '₱ ' . number_format((float) $beneficiary->avg_monthly_income, 2) : '—',
        'Dependent name(s)' => $beneficiary->dependent_name ?? '—',
        'Dependent contact no.' => $beneficiary->dependent_contact_no ?? '—',
        'Employment interest' => $beneficiary->employment_interest ?? '—',
        'Skills / training needed' => $beneficiary->skills_training_needed ?? '—',
        'Notes' => $beneficiary->notes ?? '—',
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-0">{{ $beneficiary->full_name }}</h1>
        <div class="small text-muted">
            <span class="font-monospace">{{ $beneficiary->beneficiary_code }}</span>
            @include('partials.duplicate-badge', ['status' => $beneficiary->duplicate_status])
            @include('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])
            @if ($beneficiary->archived_at)
                <span class="badge text-bg-dark">Archived {{ $beneficiary->archived_at->format('Y-m-d') }}</span>
            @endif
        </div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="{{ route('beneficiaries.index') }}">List</a>
        <a class="btn btn-outline-secondary" href="{{ route('beneficiaries.card', $beneficiary) }}">Print ID card</a>
        <a class="btn btn-primary" href="{{ route('beneficiaries.edit', $beneficiary) }}">Edit</a>
        @if ($beneficiary->archived_at)
            @can('unarchive', $beneficiary)
                <form method="POST" action="{{ route('beneficiaries.unarchive', $beneficiary) }}">
                    @csrf
                    <button class="btn btn-outline-success" type="submit"
                            onclick="return confirm('Restore this beneficiary?')">Restore</button>
                </form>
            @endcan
        @else
            @can('archive', $beneficiary)
                <form method="POST" action="{{ route('beneficiaries.archive', $beneficiary) }}">
                    @csrf
                    <button class="btn btn-outline-danger" type="submit"
                            onclick="return confirm('Archive this beneficiary? Archived records are hidden by default but kept with full history.')">Archive</button>
                </form>
            @endcan
        @endif
    </div>
</div>

@if (session('duplicate_flags'))
    <div class="alert alert-warning py-2 small" role="alert">
        <strong>Saved with duplicate warning:</strong>
        {{ \App\Services\DuplicateDetector::ruleLabel(session('duplicate_flags.rule')) }}
        matches
        @foreach (session('duplicate_flags.codes') as $code)
            <span class="font-monospace">{{ $code }}</span>{{ !$loop->last ? ',' : '' }}
        @endforeach.
        An admin can confirm or clear the flag below.
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white">Record details</div>
            <div class="card-body">
                <div class="row row-cols-1 row-cols-md-2 g-3">
                    @foreach ($fields as $label => $value)
                        <div>
                            <div class="text-muted small">{{ $label }}</div>
                            <div>{!! $value !== '' ? $value : '—' !!}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-white">Duplicate review</div>
            <div class="card-body">
                @php $matches = $candidates['matches'] ?? collect(); @endphp
                @if ($matches->isEmpty())
                    <div class="text-muted small">No matching records found for this beneficiary.</div>
                @else
                    <div class="small mb-2">
                        <strong>{{ \App\Services\DuplicateDetector::ruleLabel($candidates['rule']) }}:</strong>
                        <ul class="mb-0">
                            @foreach ($matches as $match)
                                <li>
                                    <a class="font-monospace" href="{{ route('beneficiaries.show', $match) }}">{{ $match->beneficiary_code }}</a>
                                    — {{ $match->full_name }}{{ $match->birthdate ? ', b. '.$match->birthdate->format('Y-m-d') : '' }}
                                    {{ $match->archived_at ? '(archived)' : '' }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @can('resolveDuplicate', $beneficiary)
                    <hr>
                    <form method="POST" action="{{ route('beneficiaries.duplicate-resolve', $beneficiary) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="decision">Operator decision</label>
                            <select class="form-select form-select-sm" id="decision" name="decision" required>
                                <option value="cleared" @selected($beneficiary->duplicate_status === 'cleared')>Not a duplicate — clear flag</option>
                                <option value="confirmed_duplicate" @selected($beneficiary->duplicate_status === 'confirmed_duplicate')>Confirmed duplicate — flag only (never merged)</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1" for="note">Note (why) <span class="text-danger">*</span></label>
                            <textarea class="form-control form-control-sm" id="note" name="note" rows="2"
                                      maxlength="500" required>{{ old('note', $beneficiary->duplicate_note) }}</textarea>
                        </div>
                        <button class="btn btn-sm btn-primary" type="submit">Record decision</button>
                    </form>
                    @if ($beneficiary->duplicate_note)
                        <div class="text-muted small mt-2">Last note: {{ $beneficiary->duplicate_note }}</div>
                    @endif
                @else
                    <div class="text-muted small">Duplicate decisions are recorded by administrators.</div>
                @endcan
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">History</div>
            <div class="card-body small">
                <div>Created: {{ $beneficiary->created_at?->format('Y-m-d H:i') }}</div>
                <div>Updated: {{ $beneficiary->updated_at?->format('Y-m-d H:i') }}</div>
                <div>Import batch: {{ $beneficiary->import_batch_id ? '#'.$beneficiary->import_batch_id : 'manual entry' }}</div>
                <div>Release batch:
                    @if ($beneficiary->beneficiaryBatch)
                        <a href="{{ route('beneficiary-batches.show', $beneficiary->beneficiaryBatch) }}">
                            {{ $beneficiary->beneficiaryBatch->name }}
                        </a>
                    @else
                        Unassigned
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
