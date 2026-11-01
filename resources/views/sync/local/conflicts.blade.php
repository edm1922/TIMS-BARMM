@extends('layouts.app')

@section('title', 'Local Sync Conflicts')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <div class="text-muted small mb-1">
            <a href="{{ route('sync.local.index') }}" class="text-decoration-none">Sync Setup</a>
            <span class="mx-1">/</span> Conflicts
        </div>
        <h1 class="h4 mb-0">Local Sync Conflicts</h1>
        <div class="text-muted small">Review and resolve conflicts between this offline server and the central server.</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('sync.local.index') }}">Back to Sync Setup</a>
</div>

@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

{{-- Open conflicts --}}
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Open conflicts</span>
        <span class="badge text-bg-danger">{{ $open->total() }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle table-hover">
            <thead class="table-light">
                <tr>
                    <th>Entity type</th>
                    <th>Entity UUID</th>
                    <th>Conflicting fields</th>
                    <th>Detected</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($open as $conflict)
                    @php
                        $diffFields = is_array($conflict->diff) ? $conflict->diff : [];
                    @endphp
                    <tr>
                        <td><span class="badge text-bg-secondary">{{ $conflict->entity_type }}</span></td>
                        <td><code class="small">{{ substr($conflict->entity_uuid, 0, 12) }}…</code></td>
                        <td class="small text-muted">
                            @if (count($diffFields) > 0)
                                {{ implode(', ', array_slice($diffFields, 0, 5)) }}
                                @if (count($diffFields) > 5)
                                    … +{{ count($diffFields) - 5 }} more
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="small text-muted text-nowrap">{{ $conflict->created_at?->diffForHumans() }}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#conflict-modal-{{ $conflict->id }}"
                                    type="button">
                                Resolve
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            ✓ No open conflicts. The local data is consistent with the central server.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($open->hasPages())
        <div class="card-footer bg-white">{{ $open->links() }}</div>
    @endif
</div>

{{-- Recently resolved --}}
@if ($resolved->isNotEmpty())
<div class="card">
    <div class="card-header bg-white fw-semibold">Recently resolved</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Entity type</th>
                    <th>Entity UUID</th>
                    <th>Resolution</th>
                    <th>Resolved</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resolved as $conflict)
                    <tr>
                        <td><span class="badge text-bg-secondary">{{ $conflict->entity_type }}</span></td>
                        <td><code class="small">{{ substr($conflict->entity_uuid, 0, 12) }}…</code></td>
                        <td class="small">
                            {{ str_replace('_', ' ', $conflict->resolution['action'] ?? '—') }}
                            @if (!empty($conflict->resolution['fields']))
                                <span class="text-muted">({{ implode(', ', $conflict->resolution['fields']) }})</span>
                            @endif
                        </td>
                        <td class="small text-muted text-nowrap">{{ $conflict->resolved_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Resolution modals (one per open conflict) --}}
@foreach ($open as $conflict)
    @php
        $diffFields    = is_array($conflict->diff) ? $conflict->diff : [];
        $localPayload  = $conflict->local_payload  ?? [];
        $remotePayload = $conflict->remote_payload ?? [];
        $allFields     = array_unique(array_merge(array_keys($localPayload), array_keys($remotePayload)));
        sort($allFields);
    @endphp
    <div class="modal fade" id="conflict-modal-{{ $conflict->id }}" tabindex="-1"
         aria-labelledby="conflict-modal-label-{{ $conflict->id }}" aria-hidden="true">
        <div class="modal-dialog tims-modal--xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="tims-modal-icon tims-modal-icon--warning" aria-hidden="true">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                            <path d="M12 9v4"/><path d="M12 17h.01"/>
                        </svg>
                    </span>
                    <div>
                        <h2 class="modal-title" id="conflict-modal-label-{{ $conflict->id }}">
                            Resolve conflict <code class="small">{{ substr($conflict->entity_uuid, 0, 8) }}…</code>
                        </h2>
                        <p class="tims-modal-subtitle">Compare the incoming offline values with the central record before choosing a resolution.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {{-- Mini side-by-side table --}}
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Field</th>
                                    <th>Local (incoming) <span class="badge text-bg-warning">offline</span></th>
                                    <th>Central (current) <span class="badge text-bg-primary">online</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($allFields as $field)
                                    @php
                                        $lv      = $localPayload[$field]  ?? null;
                                        $rv      = $remotePayload[$field] ?? null;
                                        $differs = in_array($field, $diffFields, true) || $lv !== $rv;
                                    @endphp
                                    <tr class="{{ $differs ? 'table-warning' : '' }}">
                                        <td class="small fw-medium">{{ $field }}</td>
                                        <td class="small">{{ $lv ?? '—' }}</td>
                                        <td class="small">{{ $rv ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Resolution form --}}
                    <form method="POST"
                          action="{{ route('sync.local.conflicts.resolve', $conflict) }}"
                          id="resolve-form-{{ $conflict->id }}">
                        @csrf
                        <div class="mb-3">
                            <div class="form-label mb-2">Resolution strategy</div>
                            <div class="d-flex flex-column gap-2">
                                <div class="form-check tims-option">
                                    <input class="form-check-input conflict-action-radio"
                                           type="radio" name="action"
                                           id="kc-{{ $conflict->id }}" value="keep_central"
                                           data-target="merge-fields-{{ $conflict->id }}" required>
                                    <label class="form-check-label" for="kc-{{ $conflict->id }}">
                                        <span class="fw-semibold">Keep central</span>
                                        <span class="text-muted small ms-1">— discard incoming offline changes.</span>
                                    </label>
                                </div>
                                <div class="form-check tims-option">
                                    <input class="form-check-input conflict-action-radio"
                                           type="radio" name="action"
                                           id="al-{{ $conflict->id }}" value="accept_local"
                                           data-target="merge-fields-{{ $conflict->id }}">
                                    <label class="form-check-label" for="al-{{ $conflict->id }}">
                                        <span class="fw-semibold">Accept local</span>
                                        <span class="text-muted small ms-1">— overwrite central with all offline changes.</span>
                                    </label>
                                </div>
                                <div class="form-check tims-option">
                                    <input class="form-check-input conflict-action-radio"
                                           type="radio" name="action"
                                           id="mg-{{ $conflict->id }}" value="merge"
                                           data-target="merge-fields-{{ $conflict->id }}">
                                    <label class="form-check-label" for="mg-{{ $conflict->id }}">
                                        <span class="fw-semibold">Merge selected fields</span>
                                        <span class="text-muted small ms-1">— pick specific fields from offline version.</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        {{-- Field checkboxes for merge (hidden until "merge" is chosen) --}}
                        <div class="d-none mb-3" id="merge-fields-{{ $conflict->id }}">
                            <div class="form-label mb-2">Fields to take from local (offline) version</div>
                            <div class="row g-2">
                                @foreach (count($diffFields) > 0 ? $diffFields : array_keys($localPayload) as $field)
                                    <div class="col-sm-4 col-md-3">
                                        <div class="form-check tims-option">
                                            <input class="form-check-input" type="checkbox"
                                                   name="fields[]" value="{{ $field }}"
                                                   id="mf-{{ $conflict->id }}-{{ $field }}">
                                            <label class="form-check-label small" for="mf-{{ $conflict->id }}-{{ $field }}">
                                                <code>{{ $field }}</code>
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="resolve-form-{{ $conflict->id }}" class="btn btn-danger"
                            onclick="return confirm('Resolve this conflict? This cannot be undone.');">
                        Confirm resolution
                    </button>
                </div>
            </div>
        </div>
    </div>
@endforeach
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.conflict-action-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const targetId = this.getAttribute('data-target');
            const section  = document.getElementById(targetId);
            if (section) {
                section.classList.toggle('d-none', this.value !== 'merge');
            }
        });
    });
})();
</script>
@endpush
