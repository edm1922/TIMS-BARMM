@extends('layouts.app')

@section('title', 'Sync Request Review')

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

@php
    $format = function ($value) {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        return is_scalar($value) && $value !== '' ? (string) $value : '—';
    };

    $itemBadge = fn (\App\Models\SyncRequestItem $item): string => match ($item->status) {
        \App\Models\SyncRequestItem::STATUS_APPLIED => 'text-bg-success',
        \App\Models\SyncRequestItem::STATUS_ACCEPTED => 'text-bg-success',
        \App\Models\SyncRequestItem::STATUS_AWAITING_REVIEW => 'text-bg-warning',
        \App\Models\SyncRequestItem::STATUS_REJECTED => 'text-bg-secondary',
        \App\Models\SyncRequestItem::STATUS_FAILED => 'text-bg-danger',
        default => 'text-bg-light',
    };
@endphp

<header class="sync-page-head">
        <div>
            <div class="text-muted small mb-2">
                <a href="{{ route('sync-requests.index') }}" class="text-decoration-none">Upload history</a>
                <span class="mx-1">/</span> {{ substr($syncRequest->request_uuid, 0, 8) }}&hellip;
            </div>
            <h1 class="sync-page-title">Sync request review</h1>
            <p class="sync-page-subtitle">
                Submitted by {{ $syncRequest->server?->name ?? 'Unknown server' }}
                @if ($syncRequest->submitter) ({{ $syncRequest->submitter->name }}) @endif
                {{ $syncRequest->submitted_at?->diffForHumans() }}. Each record keeps its own outcome;
                rejecting one never discards the rest. Pending decisions open in the Review inbox.
            </p>
            <div class="sync-meta">
                <span>{{ number_format($syncRequest->total_operations) }} records</span>
                <span>{{ number_format($syncRequest->pending_count) }} awaiting a decision</span>
                <span class="sync-pill {{ $syncRequest->needsReview() ? 'sync-pill--warning' : 'sync-pill--neutral' }}">{{ $syncRequest->displayStatus() }}</span>
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--primary" href="{{ route('review-inbox.index') }}">Review inbox</a>
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync-requests.index') }}">Back to requests</a>
        </div>
    </header>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-3 small">
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Status</div>
                <div class="fw-semibold">{{ $syncRequest->displayStatus() }}</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Submitted</div>
                <div>{{ $syncRequest->submitted_at?->toDateTimeString() ?? '—' }}</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Reviewed</div>
                <div>
                    @if ($syncRequest->reviewed_at)
                        {{ $syncRequest->reviewed_at->toDateTimeString() }}
                        @if ($syncRequest->reviewer) by {{ $syncRequest->reviewer->name }} @endif
                    @else
                        <span class="text-muted">Not decided yet</span>
                    @endif
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted">Records</div>
                <div>
                    {{ number_format($syncRequest->total_operations) }} submitted &middot;
                    <span class="text-success">{{ number_format($syncRequest->accepted_count) }} accepted</span> &middot;
                    <span class="text-secondary">{{ number_format($syncRequest->rejected_count) }} rejected</span> &middot;
                    <span class="text-warning">{{ number_format($syncRequest->pending_count) }} awaiting</span>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <span class="badge text-bg-light">
                Area:
                @if ($syncRequest->server && $syncRequest->server->scopes->isNotEmpty())
                    {{ $syncRequest->server->scopes->pluck('location_code')->join(', ') }}
                @else
                    &mdash;
                @endif
            </span>
            <span class="badge text-bg-light">Duplicates: {{ number_format($syncRequest->duplicate_count) }}</span>
            <span class="badge text-bg-light">Conflicts: {{ number_format($syncRequest->conflict_count) }}</span>
            <span class="badge text-bg-light">Validation errors: {{ number_format($syncRequest->validation_error_count) }}</span>
            <span class="badge text-bg-light">Applied on receipt: {{ number_format($syncRequest->applied_count) }}</span>
        </div>
    </div>
</div>

{{-- Review summary: what the reviewer is about to decide --}}
@if ($summary['total'] > 0)
    <div class="card mb-3">
        <div class="card-header bg-white fw-semibold">Review summary</div>
        <div class="card-body">
            <div class="row g-2 text-center">
                <div class="col-6 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="h5 mb-0">{{ number_format($summary['new_records']) }}</div>
                        <div class="text-muted small">New records</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="h5 mb-0">{{ number_format($summary['updates']) }}</div>
                        <div class="text-muted small">Updates to existing</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="border rounded p-2 h-100 {{ $summary['exact_matches'] > 0 ? 'border-warning' : '' }}">
                        <div class="h5 mb-0 text-warning">{{ number_format($summary['exact_matches']) }}</div>
                        <div class="text-muted small">Exact identifier matches</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="border rounded p-2 h-100">
                        <div class="h5 mb-0">{{ number_format($summary['possible_duplicates']) }}</div>
                        <div class="text-muted small">Possible duplicates (name)</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="border rounded p-2 h-100 {{ $summary['invalid'] > 0 ? 'border-danger' : '' }}">
                        <div class="h5 mb-0 text-danger">{{ number_format($summary['invalid']) }}</div>
                        <div class="text-muted small">Invalid records</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="border rounded p-2 h-100 {{ $summary['conflicts'] > 0 ? 'border-danger' : '' }}">
                        <div class="h5 mb-0 text-danger">{{ number_format($summary['conflicts']) }}</div>
                        <div class="text-muted small">Conflicting updates</div>
                    </div>
                </div>
            </div>
            <p class="small text-muted mb-0 mt-2">
                {{ number_format($summary['awaiting']) }} of {{ number_format($summary['total']) }} record(s) still need a
                decision; {{ number_format($summary['accepted']) }} accepted and {{ number_format($summary['rejected']) }} rejected.
                Exact identifier matches and conflicting updates are held until you decide; nothing there is applied automatically.
            </p>
        </div>
    </div>
@endif

@forelse ($review as $entry)
    @php
        /** @var \App\Models\SyncRequestItem $item */
        $item = $entry['item'];
        $central = $entry['central'];
        $fields = $item->conflict?->diff ?? [];
    @endphp
    <div class="card mb-3" id="item-{{ $item->id }}">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge text-bg-secondary">{{ $item->entity_type === 'beneficiary_batch' ? 'Batch' : 'Beneficiary' }}</span>
                <span class="badge text-bg-light">{{ ucfirst($item->operation) }}</span>
                <code class="small">{{ substr($item->entity_uuid, 0, 8) }}&hellip;</code>
                @if ($item->duplicate_rule)
                    <span class="badge text-bg-warning">Held: {{ \App\Services\DuplicateDetector::ruleLabel($item->duplicate_rule) }}</span>
                @endif
                @if ($item->conflict_uuid)
                    <span class="badge text-bg-danger">Revision conflict</span>
                @endif
            </div>
            <span class="badge {{ $itemBadge($item) }}">{{ $item->displayStatus() }}</span>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="text-muted small mb-1">Submitted by the offline server</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 small">
                            <tbody>
                                @forelse ($entry['incoming'] as $key => $value)
                                    <tr>
                                        <th class="w-50 bg-light">{{ ucfirst(str_replace('_', ' ', $key)) }}</th>
                                        <td>{{ $format($value) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="text-muted">No comparable fields submitted.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="text-muted small mb-1">Central server record</div>
                    @if ($central)
                        <div class="mb-1 small">
                            @if ($central['url'])
                                <a href="{{ $central['url'] }}" class="text-decoration-none">{{ $central['label'] }}</a>
                            @else
                                {{ $central['label'] }}
                            @endif
                            @if ($central['deleted'])
                                <span class="badge text-bg-secondary">Deleted</span>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 small">
                                <tbody>
                                    @foreach ($central['values'] as $key => $value)
                                        <tr class="{{ in_array($key, $fields, true) ? 'table-warning' : '' }}">
                                            <th class="w-50 bg-light">{{ ucfirst(str_replace('_', ' ', $key)) }}</th>
                                            <td>{{ $format($value) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="alert alert-light border small mb-0">
                            No central record exists under this identifier yet — this submitted record would create it.
                        </div>
                    @endif

                    @if ($entry['matches']->isNotEmpty())
                        <div class="mt-2 small">
                            <div class="text-muted mb-1">Existing records sharing this identity:</div>
                            <ul class="mb-0 ps-3">
                                @foreach ($entry['matches'] as $match)
                                    <li>
                                        <a href="{{ route('beneficiaries.show', $match) }}" class="text-decoration-none">
                                            {{ $match->beneficiary_code ?: $match->full_name }}
                                        </a>
                                        <span class="text-muted">{{ $match->full_name }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($item->conflict_uuid && $entry['conflict'])
                        <div class="mt-2 small">
                            <a href="{{ route('sync-conflicts.show', $entry['conflict']) }}" class="text-decoration-none">
                                Open the full conflict comparison &rarr;
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            @if ($entry['failure_label'])
                <div class="alert alert-danger small mt-2 mb-0">
                    <strong>Cannot be applied:</strong> {{ $entry['failure_label'] }}
                    @if ($item->result['message'] ?? null)
                        <span class="text-muted">({{ $item->result['message'] }})</span>
                    @endif
                </div>
            @endif

            @if ($entry['advisory'])
                <div class="alert alert-warning small mt-2 mb-0">
                    <strong>{{ $entry['advisory']['label'] }}:</strong>
                    @foreach ($entry['advisory']['matches'] as $advisoryMatch)
                        <a href="{{ route('beneficiaries.show', $advisoryMatch) }}" class="text-decoration-none">{{ $advisoryMatch->beneficiary_code ?: $advisoryMatch->full_name }}</a>@if (! $loop->last), @endif
                    @endforeach
                    — a weak match only; it is never merged automatically.
                </div>
            @endif

            @if ($item->result && ! $item->isAwaitingReview())
                <div class="mt-2 small text-muted">
                    Outcome: {{ $item->result['message'] ?? $item->result['status'] ?? '—' }}
                    @if ($item->decision_reason)
                        &middot; Reason: {{ $item->decision_reason }}
                    @endif
                </div>
            @endif

            @if ($item->isAwaitingReview())
                <hr>
                @if ($entry['guidance'])
                    <p class="small text-muted mb-2">{{ $entry['guidance'] }}</p>
                @endif
                <div class="alert alert-info small d-flex justify-content-between align-items-center flex-wrap gap-2 mb-0">
                    <span>This record is waiting for an administrator decision.</span>
                    <a class="alert-link" href="{{ route('review-inbox.show', 'item-'.$item->id) }}">
                        Review this record in the Review inbox
                    </a>
                </div>
            @endif
        </div>
    </div>
@empty
    <div class="alert alert-light border">This request has no submitted records.</div>
@endforelse

{{-- Immutable decision history --}}
@if ($decisions->isNotEmpty() || $priorDecisions->isNotEmpty())
    <div class="card mt-3">
        <div class="card-header bg-white fw-semibold">Decision history</div>
        <div class="card-body">
            @if ($decisions->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>When</th>
                                <th>Record</th>
                                <th>Decision</th>
                                <th>Action</th>
                                <th>Reviewer</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($decisions as $decision)
                                <tr>
                                    <td class="text-muted text-nowrap">{{ $decision->decided_at?->toDateTimeString() }}</td>
                                    <td>
                                        <code class="small">{{ substr($decision->entity_uuid, 0, 8) }}&hellip;</code>
                                    </td>
                                    <td>
                                        <span class="badge {{ $decision->decision === \App\Models\SyncRequestDecision::DECISION_ACCEPTED ? 'text-bg-success' : 'text-bg-secondary' }}">
                                            {{ $decision->displayDecision() }}
                                        </span>
                                    </td>
                                    <td>{{ str_replace('_', ' ', (string) $decision->action) }}</td>
                                    <td>{{ $decision->decider?->name ?? '—' }}</td>
                                    <td>{{ $decision->reason ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="small text-muted mb-0">No decision has been recorded for this request yet.</p>
            @endif

            @if ($priorDecisions->isNotEmpty())
                <hr>
                <div class="fw-semibold small mb-2">Earlier decisions for the same records</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>When</th>
                                <th>Request</th>
                                <th>Record</th>
                                <th>Decision</th>
                                <th>Reviewer</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($priorDecisions as $decision)
                                <tr>
                                    <td class="text-muted text-nowrap">{{ $decision->decided_at?->toDateTimeString() }}</td>
                                    <td>
                                        <a href="{{ route('sync-requests.show', $decision->request) }}" class="text-decoration-none font-monospace">
                                            {{ substr($decision->request_uuid, 0, 8) }}&hellip;
                                        </a>
                                    </td>
                                    <td><code class="small">{{ substr($decision->entity_uuid, 0, 8) }}&hellip;</code></td>
                                    <td>
                                        <span class="badge {{ $decision->decision === \App\Models\SyncRequestDecision::DECISION_ACCEPTED ? 'text-bg-success' : 'text-bg-secondary' }}">
                                            {{ $decision->displayDecision() }}
                                        </span>
                                    </td>
                                    <td>{{ $decision->decider?->name ?? '—' }}</td>
                                    <td>{{ $decision->reason ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mb-0 mt-2">
                    Earlier decisions are kept even when the record is resubmitted; a new submission adds history, it never rewrites it.
                </p>
            @endif
        </div>
    </div>
@endif
</div>
@endsection
