@extends('layouts.app')

@section('title', $beneficiary->full_name)

@section('content')
@php
    $ageStatus = $beneficiary->ageStatus();
    $identityFields = [
        'Beneficiary ID' => '<span class="font-monospace">' . e($beneficiary->beneficiary_code) . '</span>',
        'Age status' => view('partials.age-badge', ['status' => $ageStatus])->render(),
        'Birthdate' => $beneficiary->birthdate?->format('Y-m-d') . ($ageStatus === 'unknown' ? ' <span class="text-warning small">(missing or not yet valid)</span>' : ''),
        'Age (computed)' => $beneficiary->computedAge() ?? '—',
        'Sex' => e($beneficiary->sex ?? '—'),
        'Civil status' => e($beneficiary->civil_status ?? '—'),
    ];
    $addressFields = [
        'Address' => e($beneficiary->display_address ?: '—'),
        'Province / District' => e(trim(($beneficiary->province ?? '') . ' / ' . ($beneficiary->district ?? ''), ' /')),
    ];
    $contactFields = [
        'Government ID' => e(trim(($beneficiary->id_type_label ?? '—') . ' ' . ($beneficiary->source_id_no ?? ''))),
        'COS No.' => e($beneficiary->cos_no ?? '—'),
        'Contact no.' => e($beneficiary->contact_no ?? '—'),
    ];
    $employmentFields = [
        'Batch' => $beneficiary->beneficiaryBatch
            ? view('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])->render()
            : '<span class="text-muted">Unassigned</span>',
        'Beneficiary type' => e(\App\Support\BeneficiaryOptions::label('beneficiary_type', $beneficiary->beneficiary_type) ?? '—'),
        'Beneficiary signify' => e(\App\Support\BeneficiaryOptions::label('beneficiary_signify', $beneficiary->beneficiary_signify) ?? '—'),
        'Occupation' => e($beneficiary->occupation_label ?? '—'),
        'Mode of payment' => e(\App\Support\BeneficiaryOptions::label('mode_of_payment', $beneficiary->mode_of_payment) ?? '—'),
        'Avg. monthly income' => $beneficiary->avg_monthly_income !== null ? '₱ ' . number_format((float) $beneficiary->avg_monthly_income, 2) : '—',
        'Dependent name(s)' => e($beneficiary->dependent_name ?? '—'),
        'Dependent contact no.' => e($beneficiary->dependent_contact_no ?? '—'),
    ];
    $additionalFields = [
        'Employment interest' => e($beneficiary->employment_interest ?? '—'),
        'Skills / training needed' => e($beneficiary->skills_training_needed ?? '—'),
    ];
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .beneficiary-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        @media (max-width: 991.98px) { .sync-console .beneficiary-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575.98px) { .sync-console .beneficiary-summary { grid-template-columns: 1fr; } }
        .sync-console .beneficiary-summary .sync-stat { padding: 16px 18px; }
        .sync-console .beneficiary-summary .sync-stat-value { font-size: 22px; }
        .sync-console .benef-id { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
        .sync-console .benef-section-title { margin: 0; font-size: 15.5px; font-weight: 600; }
        .sync-console .benef-section-subtitle { margin: 2px 0 0; font-size: 12px; color: var(--sync-muted); }
        .sync-console .benef-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 24px; padding: 4px 20px 16px; }
        @media (max-width: 767.98px) { .sync-console .benef-detail-grid { grid-template-columns: 1fr; padding: 4px 16px 16px; } }
        .sync-console .benef-detail { padding: 10px 0; border-bottom: 1px solid #f1f5f9; min-width: 0; }
        .sync-console .benef-detail-label { font-size: 11.5px; font-weight: 500; color: var(--sync-muted); margin-bottom: 2px; }
        .sync-console .benef-detail-value { font-size: 13px; overflow-wrap: anywhere; }
        .sync-console .benef-duplicate-form .form-label { font-size: 12.5px; font-weight: 600; }
        .sync-console .benef-duplicate-form .form-control,
        .sync-console .benef-duplicate-form .form-select { min-height: 40px; font-size: 13.5px; border-radius: 8px; }
        .sync-console .benef-history-row { display: grid; grid-template-columns: minmax(120px, 40%) minmax(0, 1fr); gap: 12px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 12.5px; }
        .sync-console .benef-history-row:last-child { border-bottom: 0; }
        .sync-console .benef-history-label { color: var(--sync-muted); }
        .sync-console .benef-history-value { overflow-wrap: anywhere; }
    </style>

    <nav class="sync-breadcrumb mb-2" aria-label="Breadcrumb" style="font-size: 12.5px; color: var(--sync-muted);">
        <a href="{{ route('beneficiaries.index') }}" style="color: var(--sync-muted); text-decoration: none;">Beneficiaries</a>
        <span class="mx-1">/</span> {{ $beneficiary->full_name }}
    </nav>

    <header class="sync-page-head">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="sync-page-title">{{ $beneficiary->full_name }}</h1>
                @if ($beneficiary->archived_at)
                    <span class="sync-pill sync-pill--neutral">Archived {{ $beneficiary->archived_at->format('Y-m-d') }}</span>
                @endif
            </div>
            <div class="sync-meta">
                <span class="benef-id">{{ $beneficiary->beneficiary_code }}</span>
                @include('partials.duplicate-badge', ['status' => $beneficiary->duplicate_status, 'rule' => $beneficiary->duplicate_rule])
                @include('partials.batch-badge', ['batch' => $beneficiary->beneficiaryBatch])
                @if ($beneficiary->missing_fields)
                    <span class="sync-pill sync-pill--warning">Missing {{ implode(', ', $beneficiary->missingFieldLabels()) }}</span>
                @endif
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--secondary" href="{{ route('beneficiaries.index') }}">List</a>
            <a class="sync-btn sync-btn--primary" href="{{ route('beneficiaries.edit', $beneficiary) }}">Edit</a>
            @if ($beneficiary->archived_at)
                @can('unarchive', $beneficiary)
                    <form method="POST" action="{{ route('beneficiaries.unarchive', $beneficiary) }}">
                        @csrf
                        <button class="sync-btn sync-btn--secondary" type="submit"
                                onclick="return confirm('Restore this beneficiary?')">Restore</button>
                    </form>
                @endcan
            @else
                @can('archive', $beneficiary)
                    <form method="POST" action="{{ route('beneficiaries.archive', $beneficiary) }}">
                        @csrf
                        <button class="sync-btn sync-btn--secondary" type="submit"
                                onclick="return confirm('Archive this beneficiary? Archived records are hidden by default but kept with full history.')">Archive</button>
                    </form>
                @endcan
            @endif
            @can('delete', $beneficiary)
                <button class="sync-btn sync-btn--primary" type="button"
                        style="background: var(--sync-danger); border-color: var(--sync-danger);"
                        data-bs-toggle="modal" data-bs-target="#deleteBeneficiaryModal">
                    Delete
                </button>
            @endcan
        </div>
    </header>

    <section class="sync-summary beneficiary-summary" aria-label="Beneficiary overview">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Age (computed)</div>
            <div class="sync-stat-value">{{ $beneficiary->computedAge() ?? '-' }}</div>
            <div class="sync-stat-note">Derived from the birthdate.</div>
        </article>
        <article class="sync-card sync-stat {{ $ageStatus === 'adult' ? 'sync-stat--ok' : 'sync-stat--warning' }}">
            <div class="sync-stat-label">Age status</div>
            <div class="sync-stat-value" style="font-size: 18px;">@include('partials.age-badge', ['status' => $ageStatus])</div>
            <div class="sync-stat-note">Adult / Underage / Unknown classification.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Sex</div>
            <div class="sync-stat-value" style="font-size: 18px;">{{ $beneficiary->sex ?? '-' }}</div>
            <div class="sync-stat-note">Recorded demographic value.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Batch</div>
            <div class="sync-stat-value" style="font-size: 15px;">
                @if ($beneficiary->beneficiaryBatch)
                    <a class="text-decoration-none" href="{{ route('beneficiary-batches.show', $beneficiary->beneficiaryBatch) }}">{{ $beneficiary->beneficiaryBatch->name }}</a>
                @else
                    <span class="text-muted" style="font-size: 14px;">Unassigned</span>
                @endif
            </div>
            <div class="sync-stat-note">Release group for payout/verification.</div>
        </article>
    </section>

    @can('delete', $beneficiary)
        <div class="modal fade" id="deleteBeneficiaryModal" tabindex="-1" aria-labelledby="deleteBeneficiaryModalLabel"
             aria-describedby="deleteBeneficiaryModalDescription" aria-hidden="true">
            <div class="modal-dialog tims-modal--sm modal-dialog-centered">
                <form method="POST" action="{{ route('beneficiaries.destroy', $beneficiary) }}" class="modal-content">
                    @csrf
                    @method('DELETE')
                    <div class="modal-header">
                        <span class="tims-modal-icon tims-modal-icon--danger" aria-hidden="true">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
                            </svg>
                        </span>
                        <div>
                            <h2 class="modal-title" id="deleteBeneficiaryModalLabel">Delete beneficiary permanently</h2>
                            <p class="tims-modal-subtitle" id="deleteBeneficiaryModalDescription">
                                This removes the record from this installation and, after synchronization, from the Central Server.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tims-note tims-note--danger" role="alert">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                                <path d="M12 9v4"/><path d="M12 17h.01"/>
                            </svg>
                            <div>
                                This permanently removes <strong>{{ $beneficiary->full_name }}</strong>
                                ({{ $beneficiary->beneficiary_code }}). This cannot be undone. The deletion
                                is recorded in the audit trail with your account, the beneficiary ID, and the time.
                            </div>
                        </div>

                        @if ($errors->has('delete') || $errors->has('confirm_code'))
                            <div class="alert alert-danger py-2 small mt-3 mb-0" role="alert">
                                {{ $errors->first('delete') ?: $errors->first('confirm_code') }}
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete permanently</button>
                    </div>
                </form>
            </div>
        </div>
    @endcan

    @if (session('duplicate_flags'))
        <div class="sync-alerts">
            <div class="alert alert-warning mb-0" role="alert">
                <strong>Saved with duplicate warning:</strong>
                {{ \App\Services\DuplicateDetector::ruleLabel(session('duplicate_flags.rule')) }}
                matches
                @foreach (session('duplicate_flags.codes') as $code)
                    <span class="font-monospace">{{ $code }}</span>{{ !$loop->last ? ',' : '' }}
                @endforeach.
                An admin can confirm or clear the flag below.
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="d-grid gap-3">
                <section class="sync-card" aria-labelledby="benef-identity-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-identity-title">Identity</h2>
                            <p class="benef-section-subtitle">Name, birthdate and demographic details.</p>
                        </div>
                    </div>
                    <div class="benef-detail-grid">
                        @foreach ($identityFields as $label => $value)
                            <div class="benef-detail">
                                <div class="benef-detail-label">{{ $label }}</div>
                                <div class="benef-detail-value">{!! $value !== '' ? $value : '—' !!}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="sync-card" aria-labelledby="benef-address-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-address-title">Address</h2>
                            <p class="benef-section-subtitle">Registered residence and location reference.</p>
                        </div>
                    </div>
                    <div class="benef-detail-grid">
                        @foreach ($addressFields as $label => $value)
                            <div class="benef-detail">
                                <div class="benef-detail-label">{{ $label }}</div>
                                <div class="benef-detail-value">{!! $value !== '' ? $value : '—' !!}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="sync-card" aria-labelledby="benef-contact-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-contact-title">Identification &amp; contact</h2>
                            <p class="benef-section-subtitle">Government-issued identity and reachable contact.</p>
                        </div>
                    </div>
                    <div class="benef-detail-grid">
                        @foreach ($contactFields as $label => $value)
                            <div class="benef-detail">
                                <div class="benef-detail-label">{{ $label }}</div>
                                <div class="benef-detail-value">{!! $value !== '' ? $value : '—' !!}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="sync-card" aria-labelledby="benef-employment-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-employment-title">Employment &amp; assistance</h2>
                            <p class="benef-section-subtitle">TUPAD classification, occupation, payment and dependents.</p>
                        </div>
                    </div>
                    <div class="benef-detail-grid">
                        @foreach ($employmentFields as $label => $value)
                            <div class="benef-detail">
                                <div class="benef-detail-label">{{ $label }}</div>
                                <div class="benef-detail-value">{!! $value !== '' ? $value : '—' !!}</div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="sync-card" aria-labelledby="benef-additional-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-additional-title">Additional information</h2>
                            <p class="benef-section-subtitle">Planning notes captured during registration.</p>
                        </div>
                    </div>
                    <div class="benef-detail-grid">
                        @foreach ($additionalFields as $label => $value)
                            <div class="benef-detail">
                                <div class="benef-detail-label">{{ $label }}</div>
                                <div class="benef-detail-value">{!! $value !== '' ? $value : '—' !!}</div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="d-grid gap-3">
                <section class="sync-card" aria-labelledby="benef-duplicate-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-duplicate-title">Duplicate review</h2>
                            <p class="benef-section-subtitle">Potential matches and operator decision.</p>
                        </div>
                        @include('partials.duplicate-badge', ['status' => $beneficiary->duplicate_status, 'rule' => $beneficiary->duplicate_rule])
                    </div>
                    <div class="sync-body">
                        @php $matches = $candidates['matches'] ?? collect(); @endphp
                        @if ($matches->isEmpty())
                            <div class="sync-help mb-0">No matching records found for this beneficiary.</div>
                        @else
                            <div class="small mb-3">
                                <strong>{{ \App\Services\DuplicateDetector::ruleLabel($candidates['rule']) }}:</strong>
                                <ul class="mb-0 mt-1">
                                    @foreach ($matches as $match)
                                        <li>
                                            <a class="font-monospace" href="{{ route('beneficiaries.show', $match) }}">{{ $match->full_name }}</a>
                                            — {{ $match->full_name }}{{ $match->birthdate ? ', b. '.$match->birthdate->format('Y-m-d') : '' }}
                                            {{ $match->archived_at ? '(archived)' : '' }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @can('resolveDuplicate', $beneficiary)
                            <hr>
                            <form method="POST" action="{{ route('beneficiaries.duplicate-resolve', $beneficiary) }}" class="benef-duplicate-form">
                                @csrf
                                <div class="tims-field mb-2">
                                    <label class="form-label" for="decision">Operator decision</label>
                                    <select class="form-select" id="decision" name="decision" required>
                                        <option value="cleared" @selected($beneficiary->duplicate_status === 'cleared')>Not a duplicate — clear flag</option>
                                        <option value="confirmed_duplicate" @selected($beneficiary->duplicate_status === 'confirmed_duplicate')>Confirmed duplicate — flag only (never merged)</option>
                                    </select>
                                </div>
                                <div class="tims-field mb-2">
                                    <label class="form-label" for="note">Note (why) <span class="required">*</span></label>
                                    <textarea class="form-control" id="note" name="note" rows="2"
                                              maxlength="500" required>{{ old('note', $beneficiary->duplicate_note) }}</textarea>
                                </div>
                                <button class="sync-btn sync-btn--primary sync-btn--small" type="submit">Record decision</button>
                            </form>
                            @if ($beneficiary->duplicate_note)
                                <div class="sync-help mt-2 mb-0">Last note: {{ $beneficiary->duplicate_note }}</div>
                            @endif
                        @else
                            <div class="sync-help mb-0">Duplicate decisions are recorded by administrators.</div>
                        @endcan
                    </div>
                </section>

                <section class="sync-card" aria-labelledby="benef-history-title">
                    <div class="sync-section-head">
                        <div>
                            <h2 class="benef-section-title" id="benef-history-title">History</h2>
                            <p class="benef-section-subtitle">Record provenance and last update.</p>
                        </div>
                    </div>
                    <div class="sync-body">
                        <div class="benef-history-row">
                            <span class="benef-history-label">Created</span>
                            <span class="benef-history-value">{{ $beneficiary->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="benef-history-row">
                            <span class="benef-history-label">Updated</span>
                            <span class="benef-history-value">{{ $beneficiary->updated_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="benef-history-row">
                            <span class="benef-history-label">Import batch</span>
                            <span class="benef-history-value">{{ $beneficiary->import_batch_id ? '#'.$beneficiary->import_batch_id : 'manual entry' }}</span>
                        </div>
                        <div class="benef-history-row">
                            <span class="benef-history-label">Release batch</span>
                            <span class="benef-history-value">
                                @if ($beneficiary->beneficiaryBatch)
                                    <a href="{{ route('beneficiary-batches.show', $beneficiary->beneficiaryBatch) }}">{{ $beneficiary->beneficiaryBatch->name }}</a>
                                @else
                                    Unassigned
                                @endif
                            </span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
@endsection
