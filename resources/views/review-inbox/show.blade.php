@extends('layouts.app')

@section('title', 'Review decision')

@section('content')
@php
    $type = $entry['type'];
    $typePill = match ($type) {
        \App\Services\Sync\ReviewQueueService::TYPE_DELETION => 'sync-pill--danger',
        \App\Services\Sync\ReviewQueueService::TYPE_MAPPING => 'sync-pill--info',
        \App\Services\Sync\ReviewQueueService::TYPE_CONFLICT => 'sync-pill--warning',
        \App\Services\Sync\ReviewQueueService::TYPE_DUPLICATE => 'sync-pill--warning',
        default => 'sync-pill--neutral',
    };
    $conflict = $context['conflict'] ?? null;
    $diff = $context['diff'] ?? [];
    $payload = $context['payload'] ?? [];
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .review-compare { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        @media (max-width: 767.98px) { .sync-console .review-compare { grid-template-columns: 1fr; } }
        .sync-console .review-panel { border: 1px solid var(--sync-border); border-radius: 10px; padding: 14px 16px; background: #fff; }
        .sync-console .review-panel-title { font-size: 12px; font-weight: 600; color: var(--sync-muted); margin-bottom: 8px; }
        .sync-console .review-field { display: grid; grid-template-columns: minmax(120px, 38%) minmax(0, 1fr); gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .sync-console .review-field:last-child { border-bottom: 0; }
        .sync-console .review-field dt { color: var(--sync-muted); font-weight: 500; }
        .sync-console .review-field dd { margin: 0; overflow-wrap: anywhere; }
        .sync-console .review-option { border: 1px solid var(--sync-border); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
        .sync-console .review-option:last-child { margin-bottom: 0; }
        .sync-console .review-option .form-check-label { font-size: 13.5px; }
        .sync-console .review-option .sync-help { margin: 4px 0 0; }
    </style>

    <nav class="sync-breadcrumb mb-2" aria-label="Breadcrumb">
        <a href="{{ route('review-inbox.index') }}">Review inbox</a>
        <span>/</span> {{ $entry['label'] }}
    </nav>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">{{ $entry['label'] }}</h1>
            <p class="sync-page-subtitle">{{ $entry['summary'] }}</p>
            <div class="sync-meta">
                <span class="sync-pill {{ $typePill }}">{{ $entry['label'] }}</span>
                <span>{{ $entry['entity'] }} - <strong>{{ $entry['detail'] }}</strong></span>
                <span>From <strong>{{ $entry['server'] }}</strong></span>
                <span>Detected <strong>{{ $entry['created']?->diffForHumans() ?? '-' }}</strong></span>
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--secondary" href="{{ route('review-inbox.index') }}">Back to inbox</a>
        </div>
    </header>

    <div class="sync-grid sync-grid--main">
        <div class="sync-grid" style="margin-bottom: 0;">
            <section class="sync-card">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title">The decision</h2>
                        <p class="sync-section-subtitle">Compare the submitted values with the central record, then choose an outcome.</p>
                    </div>
                </div>

                <div class="sync-body">
                    @if ($type === \App\Services\Sync\ReviewQueueService::TYPE_DELETION)
                        <div class="review-compare">
                            <div class="review-panel">
                                <div class="review-panel-title">Central record (will be kept or archived)</div>
                                <dl class="mb-0">
                                    @foreach (['beneficiary_code' => 'Code', 'first_name' => 'First name', 'last_name' => 'Last name', 'source_id_no' => 'Government ID', 'barangay' => 'Barangay', 'city_municipality' => 'City / municipality'] as $key => $label)
                                        <div class="review-field">
                                            <dt>{{ $label }}</dt>
                                            <dd>{{ data_get($context['local'] ?? [], $key) ?: '-' }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                            <div class="review-panel">
                                <div class="review-panel-title">Removal request</div>
                                <dl class="mb-0">
                                    <div class="review-field"><dt>Requested by</dt><dd>{{ data_get($context['remote'] ?? [], 'requested_by_server_name') ?: $entry['server'] }}</dd></div>
                                    <div class="review-field"><dt>Reason</dt><dd>{{ data_get($context['remote'] ?? [], 'delete_reason') ?: 'No reason supplied' }}</dd></div>
                                    <div class="review-field"><dt>Operation</dt><dd class="font-monospace" style="font-size: 12px;">{{ data_get($context['remote'] ?? [], 'operation_uuid') ?: '-' }}</dd></div>
                                </dl>
                            </div>
                        </div>
                        <p class="sync-help mt-3 mb-0">
                            Approving archives the central record as a reversible tombstone. Keeping it restores the
                            requesting installation's copy through the normal delta sync.
                        </p>

                    @elseif ($type === \App\Services\Sync\ReviewQueueService::TYPE_MAPPING)
                        <div class="alert alert-warning small">
                            <strong>{{ number_format($context['held_records'] ?? 0) }}</strong>
                            submitted {{ Str::plural('record', $context['held_records'] ?? 0) }} belong to a batch the central
                            server has never seen
                            ({{ $context['roster_name'] ? '"'.$context['roster_name'].'"' : 'batch UUID '.$conflict?->entity_uuid }}).
                            They are preserved on the requesting installation until this decision is made.
                        </div>

                    @elseif ($type === \App\Services\Sync\ReviewQueueService::TYPE_CONFLICT)
                        <div class="review-compare">
                            <div class="review-panel">
                                <div class="review-panel-title">Submitted by the offline server</div>
                                <dl class="mb-0">
                                    @forelse ($diff as $field)
                                        <div class="review-field">
                                            <dt>{{ str_replace('_', ' ', $field) }}</dt>
                                            <dd>{{ data_get($context['local'] ?? [], $field) ?: '-' }}</dd>
                                        </div>
                                    @empty
                                        <div class="text-muted small">The submitted payload is not available.</div>
                                    @endforelse
                                </dl>
                            </div>
                            <div class="review-panel">
                                <div class="review-panel-title">Current central record</div>
                                <dl class="mb-0">
                                    @forelse ($diff as $field)
                                        <div class="review-field">
                                            <dt>{{ str_replace('_', ' ', $field) }}</dt>
                                            <dd>{{ data_get($context['remote'] ?? [], $field) ?: '-' }}</dd>
                                        </div>
                                    @empty
                                        <div class="text-muted small">The central payload is not available.</div>
                                    @endforelse
                                </dl>
                            </div>
                        </div>

                    @elseif ($type === \App\Services\Sync\ReviewQueueService::TYPE_DUPLICATE)
                        <div class="review-compare">
                            <div class="review-panel">
                                <div class="review-panel-title">Submitted record</div>
                                <dl class="mb-0">
                                    @foreach (['beneficiary_code' => 'Code', 'first_name' => 'First name', 'last_name' => 'Last name', 'source_id_no' => 'Government ID', 'birthdate' => 'Birthdate', 'barangay' => 'Barangay'] as $key => $label)
                                        <div class="review-field">
                                            <dt>{{ $label }}</dt>
                                            <dd>{{ data_get($payload, $key) ?: '-' }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                            <div class="review-panel">
                                <div class="review-panel-title">Matched central {{ Str::plural('record', max(1, count($context['duplicate_matches'] ?? []))) }}</div>
                                @if ($context['matched'])
                                    <dl class="mb-0">
                                        <div class="review-field"><dt>Code</dt><dd>{{ $context['matched']->beneficiary_code }}</dd></div>
                                        <div class="review-field"><dt>Name</dt><dd>{{ $context['matched']->full_name }}</dd></div>
                                        <div class="review-field"><dt>Government ID</dt><dd>{{ $context['matched']->source_id_no ?: '-' }}</dd></div>
                                        <div class="review-field"><dt>Batch</dt><dd>{{ $context['matched']->beneficiaryBatch?->name ?? 'Unassigned' }}</dd></div>
                                    </dl>
                                @else
                                    <div class="text-muted small">
                                        Matched by: <strong>{{ \App\Services\DuplicateDetector::ruleLabel($entry['item']?->duplicate_rule ?? '') }}</strong>.
                                    </div>
                                @endif
                                @if (! empty($context['duplicate_matches']))
                                    <div class="small text-muted mt-2">
                                        Matching codes: {{ implode(', ', $context['duplicate_matches']) }}
                                    </div>
                                @endif
                            </div>
                        </div>

                    @else
                        <div class="review-panel">
                            <div class="review-panel-title">Held record</div>
                            <dl class="mb-0">
                                @foreach (['beneficiary_code' => 'Code', 'first_name' => 'First name', 'last_name' => 'Last name', 'source_id_no' => 'Government ID', 'birthdate' => 'Birthdate', 'barangay' => 'Barangay', 'city_municipality' => 'City / municipality'] as $key => $label)
                                    <div class="review-field">
                                        <dt>{{ $label }}</dt>
                                        <dd>{{ data_get($payload, $key) ?: '-' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            @if ($entry['item']?->failure_code)
                                <div class="alert alert-danger small mt-3 mb-0">
                                    <strong>Cannot be applied:</strong>
                                    {{ $entry['item']->decision_reason ?: $entry['item']->failure_code }}
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            <section class="sync-card">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title">Record decision</h2>
                        <p class="sync-section-subtitle">The decision is written to the audit history and sent to the offline installation.</p>
                    </div>
                </div>
                <div class="sync-body">
                    <form method="POST" action="{{ route('review-inbox.decide', $entry['key']) }}">
                        @csrf

                        @if ($type === \App\Services\Sync\ReviewQueueService::TYPE_DELETION)
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-keep_record" value="keep_record" required>
                                <label class="form-check-label" for="action-keep_record">
                                    <strong>Keep the beneficiary</strong>
                                    <span class="sync-help d-block">Reject the deletion and restore the record on the requesting installation.</span>
                                </label>
                            </div>
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-approve_deletion" value="approve_deletion">
                                <label class="form-check-label" for="action-approve_deletion">
                                    <strong>Approve the deletion</strong>
                                    <span class="sync-help d-block">Archive the central record as a reversible tombstone and notify the installation.</span>
                                </label>
                            </div>

                        @elseif ($type === \App\Services\Sync\ReviewQueueService::TYPE_MAPPING)
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-map_batch" value="map_batch" required>
                                <label class="form-check-label" for="action-map_batch">
                                    <strong>Map the batch and apply the held records</strong>
                                    <span class="sync-help d-block">Choose an existing central batch, or leave the selection empty and enter a name to create it under the installation's batch identity.</span>
                                </label>
                            </div>
                            <div class="row g-2 ms-4 mb-3" style="max-width: 560px;">
                                <div class="col-md-6">
                                    <label class="sync-kv-label d-block mb-1" for="target_batch_id">Existing central batch</label>
                                    <select class="sync-input" id="target_batch_id" name="target_batch_id">
                                        <option value="">- none -</option>
                                        @foreach ($context['batch_options'] ?? [] as $batch)
                                            <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="sync-kv-label d-block mb-1" for="new_batch_name">Or create a new batch</label>
                                    <input type="text" class="sync-input" id="new_batch_name" name="new_batch_name"
                                           maxlength="100" value="{{ $context['roster_name'] ?? '' }}" placeholder="Batch name">
                                </div>
                            </div>
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-discard_batch" value="discard_batch">
                                <label class="form-check-label" for="action-discard_batch">
                                    <strong>Discard these records</strong>
                                    <span class="sync-help d-block">Reject the batch; the held records stay on the requesting installation.</span>
                                </label>
                            </div>

                        @elseif ($type === \App\Services\Sync\ReviewQueueService::TYPE_CONFLICT)
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-accept_local" value="accept_local" required>
                                <label class="form-check-label" for="action-accept_local">
                                    <strong>Apply the submitted values</strong>
                                    <span class="sync-help d-block">Overwrite the central record with the values submitted by the installation.</span>
                                </label>
                            </div>
                            <div class="review-option">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="action" id="action-merge" value="merge">
                                    <label class="form-check-label" for="action-merge">
                                        <strong>Merge only selected fields</strong>
                                    </label>
                                </div>
                                <div class="mt-2 ms-4">
                                    @foreach ($diff as $field)
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="fields[]"
                                                   id="field-{{ $loop->index }}" value="{{ $field }}">
                                            <label class="form-check-label small" for="field-{{ $loop->index }}">
                                                {{ str_replace('_', ' ', $field) }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-keep_central" value="keep_central">
                                <label class="form-check-label" for="action-keep_central">
                                    <strong>Keep the central record</strong>
                                    <span class="sync-help d-block">Reject the submission; the offline installation keeps its local copy.</span>
                                </label>
                            </div>

                        @elseif ($type === \App\Services\Sync\ReviewQueueService::TYPE_DUPLICATE)
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-link" value="link" required>
                                <label class="form-check-label" for="action-link">
                                    <strong>Same person - keep the existing record</strong>
                                    <span class="sync-help d-block">Confirms the match; nothing new is inserted.</span>
                                </label>
                            </div>
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-update" value="update">
                                <label class="form-check-label" for="action-update">
                                    <strong>Apply the submitted values onto the existing record</strong>
                                </label>
                            </div>
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-reject" value="reject">
                                <label class="form-check-label" for="action-reject">
                                    <strong>Reject the submission</strong>
                                    <span class="sync-help d-block">The offline installation keeps its local record.</span>
                                </label>
                            </div>

                        @else
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-accept" value="accept" required>
                                <label class="form-check-label" for="action-accept">
                                    <strong>Accept and apply the held record</strong>
                                </label>
                            </div>
                            <div class="review-option form-check">
                                <input class="form-check-input" type="radio" name="action" id="action-reject" value="reject">
                                <label class="form-check-label" for="action-reject">
                                    <strong>Reject the held record</strong>
                                </label>
                            </div>
                        @endif

                        <div class="mb-3" style="max-width: 640px;">
                            <label class="sync-kv-label d-block mb-1" for="review-reason">Note / reason (optional unless rejecting)</label>
                            <input type="text" class="sync-input" id="review-reason" name="reason" maxlength="1000"
                                   placeholder="Shown to the offline installation and stored in the decision history">
                        </div>

                        <div class="d-flex gap-2">
                            <button class="sync-btn sync-btn--primary" type="submit"
                                    onclick="return confirm('Record this decision? It cannot be undone.');">
                                Record decision
                            </button>
                            <a class="sync-btn sync-btn--secondary" href="{{ route('review-inbox.index') }}">Cancel</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>

        <div class="sync-grid" style="margin-bottom: 0;">
            <section class="sync-card">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title">Context</h2>
                        <p class="sync-section-subtitle">Where this decision came from.</p>
                    </div>
                </div>
                <div class="sync-body">
                    <dl class="sync-kv">
                        <div class="sync-kv-row"><dt class="sync-kv-label">Decision type</dt><dd class="sync-kv-value">{{ $entry['label'] }}</dd></div>
                        <div class="sync-kv-row"><dt class="sync-kv-label">Record</dt><dd class="sync-kv-value">{{ $entry['entity'] }} - {{ $entry['detail'] }}</dd></div>
                        <div class="sync-kv-row"><dt class="sync-kv-label">Installation</dt><dd class="sync-kv-value">{{ $entry['server'] }}</dd></div>
                        <div class="sync-kv-row"><dt class="sync-kv-label">Detected</dt><dd class="sync-kv-value">{{ $entry['created']?->toDayDateTimeString() ?? '-' }}</dd></div>
                        @if ($conflict)
                            <div class="sync-kv-row"><dt class="sync-kv-label">Reference</dt><dd class="sync-kv-value font-monospace" style="font-size: 12px;">{{ $conflict->conflict_uuid }}</dd></div>
                        @endif
                        @if ($entry['item'])
                            <div class="sync-kv-row"><dt class="sync-kv-label">Reference</dt><dd class="sync-kv-value font-monospace" style="font-size: 12px;">{{ $entry['item']->operation_uuid }}</dd></div>
                        @endif
                    </dl>
                    @if ($conflict)
                        <p class="sync-help mt-3 mb-0">
                            Full conflict history and the raw payload comparison remain available in
                            <a href="{{ route('sync-conflicts.show', $conflict) }}">Conflicts &amp; mappings</a>.
                        </p>
                    @elseif ($entry['item']?->request)
                        <p class="sync-help mt-3 mb-0">
                            The complete upload batch is available in
                            <a href="{{ route('sync-requests.show', $entry['item']->request) }}">Upload history</a>.
                        </p>
                    @endif
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
