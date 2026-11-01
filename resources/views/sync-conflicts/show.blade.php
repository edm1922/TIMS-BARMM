@extends('layouts.app')

@section('title', 'Conflict - ' . substr($conflict->conflict_uuid, 0, 8))

@section('content')
<div class="sync-console">
    @include('partials.sync-console-styles')
    <style>
        .sync-console .card { border: 1px solid var(--sync-border); border-radius: 12px; box-shadow: var(--sync-shadow); }
        .sync-console .card-header { background: #fff; border-bottom: 1px solid var(--sync-border); font-weight: 600; }
        .sync-console .table thead th { background: #f8fafc; color: var(--sync-muted); font-size: 12px; font-weight: 600; border-bottom: 1px solid var(--sync-border); white-space: nowrap; }
        .sync-console .table tbody td { font-size: 12.5px; vertical-align: top; border-color: #f1f5f9; }
        .sync-console .form-label { font-size: 12.5px; font-weight: 600; }
    </style>

<header class="sync-page-head">
        <div>
            <div class="text-muted small mb-2">
                <a href="{{ route('sync-conflicts.index') }}" class="text-decoration-none">Conflict Resolution</a>
                <span class="mx-1">/</span> {{ substr($conflict->conflict_uuid, 0, 8) }}&hellip;
            </div>
            <h1 class="sync-page-title">Conflict Review</h1>
            <p class="sync-page-subtitle">Read-only conflict history: submitted and central values, the decision, and its audit trail.</p>
            <div class="sync-meta">
                <span class="sync-pill {{ $conflict->status === 'open' ? 'sync-pill--danger' : 'sync-pill--ok' }}">{{ $conflict->status === 'open' ? 'Open' : 'Resolved' }}</span>
                <span>{{ ucfirst(str_replace('_', ' ', $conflict->entity_type)) }}</span>
                <span>From <strong>{{ $conflict->offline_server_id ? ($conflict->server?->name ?? 'Offline server') : 'this installation' }}</strong></span>
                <span>Detected <strong>{{ $conflict->created_at?->format('M d, Y g:i A') }}</strong></span>
            </div>
        </div>
        <div class="sync-page-actions">
            @if ($recordLink)
                <a class="sync-btn sync-btn--secondary" href="{{ $recordLink['url'] }}">Open affected record</a>
            @endif
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-conflicts.index') }}">Back to conflicts</a>
        </div>
    </header>

@if ($errors->has('resolution'))
    <div class="alert alert-danger">{{ $errors->first('resolution') }}</div>
@endif

{{-- Meta row --}}
<div class="row g-2 mb-3">
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="small text-muted">Entity type</div>
            <div class="fw-semibold">{{ ucfirst(str_replace('_', ' ', $conflict->entity_type)) }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="small text-muted">Source</div>
            <div class="fw-semibold small">
                @if ($conflict->offline_server_id)
                    {{ $conflict->server?->name ?? 'Offline server' }}
                @else
                    This installation
                @endif
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="small text-muted">Status</div>
            @if ($conflict->status === 'open')
                <span class="badge text-bg-danger">Open</span>
            @else
                <span class="badge text-bg-success">Resolved</span>
            @endif
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="small text-muted">Detected</div>
            <div class="small fw-semibold">{{ $conflict->created_at?->format('M d, Y g:i A') }}</div>
        </div>
    </div>
</div>

@if ($conflict->status === 'resolved')
    <div class="alert alert-success">
        <div class="fw-semibold">Resolved {{ $conflict->resolved_at?->diffForHumans() }}</div>
        <div class="small">
            Action: <strong>{{ str_replace('_', ' ', $conflict->resolution['action'] ?? '&mdash;') }}</strong>
            @if (!empty($conflict->resolution['fields']))
                &mdash; Fields merged: <code>{{ implode(', ', $conflict->resolution['fields']) }}</code>
            @endif
            @if ($conflict->resolver)
                &mdash; By <strong>{{ $conflict->resolver->name }}</strong>
            @endif
        </div>
    </div>
@endif

{{-- Side-by-side diff --}}
@php
    $localPayload  = $conflict->local_payload  ?? [];
    $remotePayload = $conflict->remote_payload ?? [];
    $diff          = is_array($conflict->diff) ? $conflict->diff : array_unique(array_merge(array_keys($localPayload), array_keys($remotePayload)));
    $allFields     = array_unique(array_merge(array_keys($localPayload), array_keys($remotePayload)));
    sort($allFields);
@endphp

<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Side-by-side field comparison</span>
        @if (count($diff) > 0)
            <span class="badge text-bg-warning">{{ count($diff) }} conflicting field(s)</span>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:25%">Field</th>
                    <th style="width:37%">
                        Local (offline) value
                        <span class="badge text-bg-warning ms-1">incoming</span>
                    </th>
                    <th style="width:38%">
                        Central (remote) value
                        <span class="badge text-bg-primary ms-1">current</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($allFields as $field)
                    @php
                        $localVal  = $localPayload[$field]  ?? null;
                        $remoteVal = $remotePayload[$field] ?? null;
                        $differs   = in_array($field, $diff, true) || $localVal !== $remoteVal;
                        $rowClass  = $differs ? 'table-warning' : '';
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="small fw-medium text-nowrap">
                            {{ $field }}
                            @if ($differs)
                                <span class="text-danger ms-1" title="Values differ">&ne;</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($localVal === null)
                                <span class="text-muted fst-italic">&mdash; not present &mdash;</span>
                            @elseif ($localVal === '')
                                <span class="text-muted fst-italic">(empty)</span>
                            @else
                                <span class="{{ $differs ? 'text-warning-emphasis' : '' }}">{{ $localVal }}</span>
                            @endif
                        </td>
                        <td class="small">
                            @if ($remoteVal === null)
                                <span class="text-muted fst-italic">&mdash; not present &mdash;</span>
                            @elseif ($remoteVal === '')
                                <span class="text-muted fst-italic">(empty)</span>
                            @else
                                <span class="{{ $differs ? 'fw-semibold' : '' }}">{{ $remoteVal }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Decisions are recorded in the unified Review inbox. This page is the
     read-only conflict history: it keeps the comparison and the decision log. --}}
@if ($conflict->status === 'open')
    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-semibold">Decision required</div>
                <div class="small text-muted">
                    This decision is recorded in the Review inbox, together with every other pending decision.
                </div>
            </div>
            <a class="btn btn-primary"
               href="{{ route('review-inbox.show', 'conflict-'.$conflict->conflict_uuid) }}">
                Review this decision
            </a>
        </div>
    </div>
@endif

{{-- Immutable decision history: every resolution (and automatic closure)
     appends an entry that is never edited or removed. --}}
@if ($decisionHistory->isNotEmpty())
    <div class="card">
        <div class="card-header bg-white fw-semibold">Decision history</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>Decision</th>
                        <th>By</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($decisionHistory as $decision)
                        <tr>
                            <td class="text-muted text-nowrap">{{ $decision->decided_at?->format('Y-m-d H:i:s') }}</td>
                            <td><span class="badge text-bg-secondary">{{ Str::of($decision->decision)->replace('_', ' ') }}</span></td>
                            <td class="small">{{ $decision->resolver?->name ?? 'system' }}</td>
                            <td class="small text-muted">{{ $decision->reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const mergeSection = document.getElementById('merge-fields-section');
    const radios = document.querySelectorAll('input[name="action"]');

    function toggle() {
        const val = document.querySelector('input[name="action"]:checked')?.value;
        mergeSection?.classList.toggle('d-none', val !== 'merge');
        document.querySelectorAll('.resolution-option').forEach(opt => {
            opt.classList.remove('border-primary', 'bg-primary-subtle');
        });
        if (val) {
            const mapId = { keep_central: 'opt-keep-central', accept_local: 'opt-accept-local', merge: 'opt-merge' };
            const active = document.getElementById(mapId[val]);
            active?.classList.add('border-primary', 'bg-primary-subtle');
        }
    }

    radios.forEach(r => r.addEventListener('change', toggle));
    toggle();
})();
</script>
@endpush
