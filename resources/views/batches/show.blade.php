@extends('layouts.app')

@section('title', $batch->name)

@section('content')
@php
    $isAdmin = auth()->user()->isAdmin();
    $canManage = auth()->user()->can('update', $batch);
    $canAddBeneficiary = ! auth()->user()->isStaff()
        && auth()->user()->can('addBeneficiary', $batch);

    $memberCount = $batch->memberCount();
    $limit = $batch->capacityLimit();
    $remaining = $batch->remainingCapacity();
    $capacityPercent = $limit === null ? null : min(100, (int) round($memberCount / max(1, $limit) * 100));
    $capacityTone = $limit === null
        ? 'neutral'
        : ($memberCount >= $limit ? 'danger' : ($capacityPercent >= 90 ? 'warning' : 'ok'));
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .batch-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        @media (max-width: 991.98px) { .sync-console .batch-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575.98px) { .sync-console .batch-summary { grid-template-columns: 1fr; } }
        .sync-console .batch-summary .sync-stat { padding: 16px 18px; }
        .sync-console .batch-summary .sync-stat-value { font-size: 24px; }
        .sync-console .batch-meta-line { margin-top: 4px; }
        .sync-console .batch-capacity-bar { height: 8px; border-radius: 999px; overflow: hidden; background: #f1f5f9; }
        .sync-console .batch-capacity-bar span { display: block; height: 100%; border-radius: 999px; background: var(--sync-primary); transition: width .3s ease; }
        .sync-console .batch-capacity-bar span.is-warning { background: #d97706; }
        .sync-console .batch-capacity-bar span.is-danger { background: var(--sync-danger); }
        .sync-console .batch-assign-select { min-height: 190px; font-size: 13px; }
        .sync-console .batch-table-name { font-weight: 600; color: var(--sync-primary); text-decoration: none; font-size: 13px; }
        .sync-console .batch-table-name:hover { text-decoration: underline; }
        .sync-console .batch-id { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; }
        .sync-console .batch-settings-card .form-label { font-size: 12.5px; font-weight: 600; }
        .sync-console .batch-settings-card .form-control,
        .sync-console .batch-settings-card .form-select { min-height: 40px; border-radius: 8px; font-size: 13.5px; }
        .sync-console .batch-settings-card .form-text { font-size: 11.5px; }
        .sync-console .batch-actions-cell { white-space: nowrap; }
        .sync-console .batch-actions-cell .sync-btn { min-height: 32px; padding: 0 11px; font-size: 12.5px; }
    </style>

    <nav class="sync-breadcrumb mb-2" aria-label="Breadcrumb" style="font-size: 12.5px; color: var(--sync-muted);">
        <a href="{{ route('beneficiary-batches.index') }}" style="color: var(--sync-muted); text-decoration: none;">Batches</a>
        <span class="mx-1">/</span> {{ $batch->name }}
    </nav>

    <header class="sync-page-head">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="sync-page-title">{{ $batch->name }}</h1>
                @if ($batch->isOpen())
                    <span class="sync-pill sync-pill--ok">Open</span>
                @else
                    <span class="sync-pill sync-pill--neutral">Closed</span>
                @endif
                @if ($batch->isFull())
                    <span class="sync-pill sync-pill--warning">Limit reached</span>
                @endif
            </div>
            <p class="sync-page-subtitle batch-meta-line">
                {{ $batch->capacityLabel() }} {{ Str::plural('beneficiary', $memberCount) }}
                &middot; created {{ $batch->created_at?->format('M d, Y') }}
                @if ($batch->creator)
                    by {{ $batch->creator->name }}
                @endif
                &middot; manager {{ $batch->managerDisplayName() ?? 'Unassigned' }}
            </p>
            <div class="sync-meta">
                @if ($batch->beneficiary_code_series)
                    <span>Next beneficiary ID: <span class="font-monospace">{{ $batch->beneficiary_code_series }}</span></span>
                @endif
                @if ($batch->description)
                    <span>{{ $batch->description }}</span>
                @endif
            </div>
        </div>
        <div class="sync-page-actions">
            @if ($batch->isOpen() && $canAddBeneficiary)
                @if ($batch->isFull())
                    <button class="sync-btn sync-btn--primary" type="button" disabled
                            title="The batch has reached its beneficiary limit ({{ number_format((int) $batch->capacityLimit()) }}). Raise the limit in Settings to add more.">
                        Batch full
                    </button>
                @else
                    <a class="sync-btn sync-btn--primary" href="{{ route('beneficiary-batches.beneficiaries.create', $batch) }}">
                        Add Beneficiary
                    </a>
                @endif
            @endif
            @if ($canManage)
                <button class="sync-btn sync-btn--secondary" type="button" data-bs-toggle="collapse"
                        data-bs-target="#batchSettings" aria-expanded="false" aria-controls="batchSettings">
                    Settings
                </button>
            @endif
            <a class="sync-btn sync-btn--secondary" href="{{ route('beneficiaries.index') }}">All beneficiaries</a>
        </div>
    </header>

    <section class="sync-summary batch-summary" aria-label="Batch overview">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Total members</div>
            <div class="sync-stat-value">{{ number_format($memberCount) }}</div>
            <div class="sync-stat-note">Records filed in this batch.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Beneficiary limit</div>
            <div class="sync-stat-value">{{ $limit === null ? 'Unlimited' : number_format($limit) }}</div>
            <div class="sync-stat-note">{{ $limit === null ? 'No cap set for this batch.' : 'Maximum members allowed.' }}</div>
        </article>
        <article class="sync-card sync-stat {{ $capacityTone === 'warning' ? 'sync-stat--warning' : '' }}">
            <div class="sync-stat-label">Available slots</div>
            <div class="sync-stat-value">{{ $remaining === null ? 'Unlimited' : number_format(max(0, $remaining)) }}</div>
            <div class="sync-stat-note">{{ $remaining === null ? 'Capacity is not capped.' : ($remaining === 0 ? 'The batch is full.' : 'Slots left before the limit.') }}</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Batch status</div>
            <div class="sync-stat-value" style="font-size: 20px;">
                <span class="sync-pill {{ $batch->isOpen() ? 'sync-pill--ok' : 'sync-pill--neutral' }}">{{ $batch->isOpen() ? 'Open' : 'Closed' }}</span>
            </div>
            <div class="sync-stat-note">{{ $batch->isOpen() ? 'Membership can still change.' : 'Membership changes are paused.' }}</div>
        </article>
    </section>

    <section class="sync-card mb-3" aria-labelledby="batch-capacity-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="batch-capacity-title">Batch capacity</h2>
                <p class="sync-section-subtitle">{{ $batch->capacityLabel() }} beneficiaries &middot; {{ $batch->isOpen() ? 'Open' : 'Closed' }}</p>
            </div>
            @if ($limit === null)
                <span class="sync-pill sync-pill--neutral">No limit</span>
            @elseif ($batch->isFull())
                <span class="sync-pill sync-pill--danger">{{ $capacityPercent }}% full</span>
            @else
                <span class="sync-pill sync-pill--{{ $capacityTone === 'warning' ? 'warning' : 'info' }}">{{ $capacityPercent }}% full</span>
            @endif
        </div>
        <div class="sync-body">
            @if ($limit === null)
                <div class="sync-help mb-0">This batch has no beneficiary limit. Members can be added until the batch is closed.</div>
            @else
                <div class="batch-capacity-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $limit }}"
                     aria-valuenow="{{ min($memberCount, $limit) }}" aria-label="Batch capacity used">
                    <span class="{{ $capacityTone === 'danger' ? 'is-danger' : ($capacityTone === 'warning' ? 'is-warning' : '') }}"
                          style="width: {{ $capacityPercent }}%;"></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-2">
                    <span>{{ number_format($memberCount) }} filled</span>
                    <span>{{ number_format(max(0, $limit - $memberCount)) }} remaining</span>
                </div>
            @endif
        </div>
    </section>

    @if ($canManage)
        <div class="collapse mb-3" id="batchSettings">
            <div class="sync-card batch-settings-card">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title">Batch settings</h2>
                        <p class="sync-section-subtitle">Update batch identity, capacity and manager assignment.</p>
                    </div>
                    <span class="sync-pill sync-pill--neutral">{{ $batch->name }}</span>
                </div>
                <div class="sync-body">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <form method="POST" action="{{ route('beneficiary-batches.update', $batch) }}" class="row g-3"
                                  id="batch-settings-form" data-original-series="{{ $batch->beneficiary_code_series }}">
                                @csrf
                                @method('PUT')
                                <div class="col-md-6">
                                    <label class="form-label" for="batch-edit-name">Batch name</label>
                                    <input type="text" class="form-control" id="batch-edit-name" name="name"
                                           maxlength="100" required value="{{ old('name', $batch->name) }}">
                                    @error('name')
                                        <div class="tims-field-error">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="batch-edit-status">Status</label>
                                    <select class="form-select" id="batch-edit-status" name="status">
                                        <option value="open" @selected(old('status', $batch->status) === 'open')>Open</option>
                                        <option value="closed" @selected(old('status', $batch->status) === 'closed')>Closed</option>
                                    </select>
                                </div>
                                @if ($isAdmin)
                                    <div class="col-md-6">
                                        <label class="form-label" for="batch-edit-manager">Assigned manager</label>
                                        <select class="form-select" id="batch-edit-manager" name="manager_id">
                                            <option value="">Unassigned</option>
                                            @foreach ($managers as $manager)
                                                <option value="{{ $manager->id }}"
                                                        @selected((string) old('manager_id', $batch->manager_id) === (string) $manager->id)>
                                                    {{ $manager->username }}{{ $manager->server ? ' - '.$manager->server->name : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('manager_id')
                                            <div class="tims-field-error">{{ $message }}</div>
                                        @enderror
                                        @if ($managers->isEmpty())
                                            <div class="form-text text-warning">
                                                No active manager has been reported by an offline installation yet.
                                            </div>
                                        @endif
                                    </div>
                                @endif
                                <div class="col-md-6">
                                    <label class="form-label" for="batch-edit-code-series">Beneficiary ID series</label>
                                    <input type="text" class="form-control" id="batch-edit-code-series"
                                           name="beneficiary_code_series" maxlength="64" autocomplete="off"
                                           placeholder="Automatic series"
                                           value="{{ old('beneficiary_code_series', $batch->beneficiary_code_series) }}">
                                    <div class="form-text">
                                        Next beneficiary ID to issue for this batch; the trailing number increments
                                        automatically. Setting a new code renumbers the existing members in
                                        registration order, starting with this code. Leave blank for the automatic series.
                                    </div>
                                    @error('beneficiary_code_series')
                                        <div class="tims-field-error">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="batch-edit-capacity">Beneficiary limit</label>
                                    <input type="number" class="form-control" id="batch-edit-capacity"
                                           name="capacity" min="1" max="100000" step="1" inputmode="numeric"
                                           placeholder="No limit"
                                           value="{{ old('capacity', $batch->capacity) }}">
                                    <div class="form-text">
                                        Current members: {{ number_format($memberCount) }}.
                                        Leave blank for no limit; the limit cannot go below the current member count.
                                    </div>
                                    @error('capacity')
                                        <div class="tims-field-error">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="batch-edit-profiled-on">Registration date</label>
                                    <input type="date" class="form-control" id="batch-edit-profiled-on"
                                           name="profiled_on"
                                           value="{{ old('profiled_on', $batch->profiled_on?->toDateString()) }}">
                                    <div class="form-text">
                                        Updates the registration/profile date of the beneficiaries in this batch,
                                        which drives the dashboard registration trend. Leave blank to keep their dates.
                                    </div>
                                    @error('profiled_on')
                                        <div class="tims-field-error">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="batch-edit-description">Description</label>
                                    <input type="text" class="form-control" id="batch-edit-description"
                                           name="description" maxlength="255"
                                           value="{{ old('description', $batch->description) }}">
                                    @error('description')
                                        <div class="tims-field-error">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="sync-btn sync-btn--primary">Save changes</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-lg-5">
                            <div class="tims-note tims-note--danger h-100" style="margin-top: 0; flex-direction: column;">
                                <div class="fw-semibold">Delete batch</div>
                                <p class="small mb-3">
                                    Deleting this batch also permanently deletes the
                                    {{ number_format($memberCount) }}
                                    {{ Str::plural('beneficiary', $memberCount) }} filed in it.
                                    Their records are removed from the register and cannot be restored.
                                    To keep them, remove them from the batch first.
                                </p>
                                @error('delete')
                                    <div class="tims-field-error mb-2">{{ $message }}</div>
                                @enderror
                                @error('confirm_name')
                                    <div class="tims-field-error mb-2">{{ $message }}</div>
                                @enderror
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal"
                                        data-bs-target="#deleteBatchModal">
                                    Delete batch and beneficiaries
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var form = document.getElementById('batch-settings-form');
                    if (!form) return;
                    var input = form.querySelector('#batch-edit-code-series');
                    form.addEventListener('submit', function (event) {
                        if (!input) return;
                        var original = form.getAttribute('data-original-series') || '';
                        var value = input.value.trim();
                        if (value !== '' && value !== original.trim()) {
                            var confirmed = window.confirm(
                                'Setting a new beneficiary ID series will renumber the existing members of this batch, '
                                + 'starting with ' + value + '. Continue?'
                            );
                            if (!confirmed) {
                                event.preventDefault();
                                event.stopImmediatePropagation();
                            }
                        }
                    });
                });
            </script>
        </div>
    @endif

    {{-- Reassignment path: removal only clears membership, so a beneficiary taken
         out of one batch is filed into another here. --}}
    @if ($canManage && $batch->isOpen())
        <section class="sync-card mb-3" aria-labelledby="assign-members-title">
            <div class="sync-section-head">
                <div>
                    <h2 class="sync-section-title" id="assign-members-title">Assign existing beneficiaries</h2>
                    <p class="sync-section-subtitle">Beneficiaries not currently in any batch (e.g. just removed from one).</p>
                </div>
                <button class="sync-btn sync-btn--secondary sync-btn--small" type="button" data-bs-toggle="collapse"
                        data-bs-target="#assignMembers" aria-expanded="false" aria-controls="assignMembers">
                    Open assignment panel
                </button>
            </div>
            <div class="collapse" id="assignMembers">
                <div class="sync-body">
                    @if ($assignable->isEmpty())
                        <div class="sync-empty">
                            <div class="sync-empty-title">No unassigned beneficiaries right now</div>
                            <div class="sync-empty-note">Every active record is already in a batch.</div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('beneficiary-batches.members.store', $batch) }}" id="batch-assign-form">
                            @csrf
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-md-7">
                                    <label class="form-label" for="assign-search">Search unassigned beneficiaries</label>
                                    <input class="form-control" type="search" id="assign-search"
                                           placeholder="Filter by name or beneficiary ID..." autocomplete="off"
                                           aria-label="Filter unassigned beneficiaries">
                                </div>
                                <div class="col-md-5">
                                    <span class="form-text d-block" data-assign-count>{{ $assignable->count() }} eligible beneficiaries listed.</span>
                                    <span class="form-text d-block">Ctrl/Cmd-click to select several.</span>
                                </div>
                            </div>
                            <label class="form-label" for="assign-select">Select beneficiaries to add to this batch</label>
                            <select class="form-select batch-assign-select" name="ids[]" id="assign-select" multiple size="10" required
                                    aria-label="Unassigned beneficiaries">
                                @foreach ($assignable as $candidate)
                                    <option value="{{ $candidate->id }}">
                                        {{ $candidate->beneficiary_code }} — {{ $candidate->full_name }}{{ trim(' '.($candidate->barangay ?? '').' '.($candidate->city_municipality ?? '')) !== '' ? ', '.trim(($candidate->barangay ?? '').' / '.($candidate->city_municipality ?? ''), ' /') : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if ($assignable->count() >= 500)
                                <div class="form-text">Showing the first 500 by name — use the filter box to find a specific person.</div>
                            @endif
                            <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                                <button class="sync-btn sync-btn--primary" type="submit" data-assign-submit>Assign selected</button>
                                <span class="small text-muted" data-assign-selected>No beneficiaries selected.</span>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <section class="sync-card" aria-labelledby="batch-members-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="batch-members-title">Batch Members</h2>
                <p class="sync-section-subtitle">{{ number_format($members->total()) }} {{ Str::plural('beneficiary', $members->total()) }} in this batch</p>
            </div>
            <span class="sync-pill sync-pill--neutral">{{ $batch->isOpen() ? 'Open' : 'Closed' }}</span>
        </div>

        @if ($members->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No beneficiaries have been assigned to this batch yet.</div>
                <div class="sync-empty-note">
                    @if ($batch->isOpen() && $canAddBeneficiary)
                        Use <strong>Add Beneficiary</strong> to register the first one,
                        @if ($canManage) or open the assignment panel above for an existing record. @else or reopen the batch to add beneficiaries. @endif
                    @elseif (! $batch->isOpen())
                        Reopen the batch to add beneficiaries.
                    @else
                        Ask an administrator to add or assign beneficiaries.
                    @endif
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Beneficiaries filed in this batch</caption>
                    <thead>
                        <tr>
                            <th scope="col">Beneficiary ID</th>
                            <th scope="col">Name</th>
                            <th scope="col" class="text-end">Age</th>
                            <th scope="col">Sex</th>
                            <th scope="col">Barangay / City</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr class="batch-member-row">
                                <td>
                                    <span class="batch-id" title="{{ $member->beneficiary_code }}">{{ $member->beneficiary_code }}</span>
                                    @if ($member->archived_at)
                                        <span class="sync-pill sync-pill--neutral mt-1">Archived</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('beneficiaries.show', $member) }}" class="batch-table-name">{{ $member->full_name }}</a>
                                </td>
                                <td class="text-end sync-ops-num">{{ $member->computedAge() ?? '-' }}</td>
                                <td style="font-size: 12.5px;">{{ $member->sex ?? '-' }}</td>
                                <td style="font-size: 12.5px;">{{ trim(($member->barangay ?? '-').' / '.($member->city_municipality ?? '-')) }}</td>
                                <td class="text-end batch-actions-cell">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small"
                                       href="{{ route('beneficiaries.show', $member) }}">View</a>
                                    @if ($canManage)
                                        <button type="button" class="sync-btn sync-btn--small btn-remove-member"
                                                style="color: var(--sync-danger); border-color: #fecaca; background: #fff;"
                                                data-bs-toggle="modal" data-bs-target="#removeMemberModal"
                                                data-member-id="{{ $member->id }}"
                                                data-member-name="{{ $member->full_name }}"
                                                data-member-code="{{ $member->beneficiary_code }}"
                                                data-action="{{ route('beneficiary-batches.members.destroy', $batch) }}">
                                            Remove
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="sync-body--divided d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="sync-registry-count">
                    Showing {{ number_format($members->firstItem() ?? 0) }}-{{ number_format($members->lastItem() ?? 0) }}
                    of {{ number_format($members->total()) }} members
                </span>
                {{ $members->links() }}
            </div>
        @endif
    </section>

    @if ($canManage)
        {{-- Removing only clears batch membership; the beneficiary record stays. --}}
        <div class="modal fade" id="removeMemberModal" tabindex="-1" aria-labelledby="removeMemberModalLabel"
             aria-describedby="removeMemberModalDescription" aria-hidden="true">
            <div class="modal-dialog tims-modal--sm modal-dialog-centered">
                <form method="POST" action="{{ route('beneficiary-batches.members.destroy', $batch) }}" class="modal-content">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="ids[]" value="" data-remove-member-id>
                    <div class="modal-header">
                        <span class="tims-modal-icon tims-modal-icon--danger" aria-hidden="true">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                                <path d="M17 11h5"/>
                            </svg>
                        </span>
                        <div>
                            <h2 class="modal-title" id="removeMemberModalLabel">Remove from batch</h2>
                            <p class="tims-modal-subtitle" id="removeMemberModalDescription">This only clears batch membership.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            Remove <strong data-remove-member-name>this beneficiary</strong>
                            (<span class="font-monospace" data-remove-member-code></span>) from
                            <strong>{{ $batch->name }}</strong>?
                        </p>
                        <div class="tims-note">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
                            </svg>
                            <div>
                                The beneficiary record is <strong>not deleted</strong>; only its membership in this batch
                                is cleared. It can be assigned to another batch from that batch's assignment panel.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Remove from batch</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Batch deletion confirmation. Deleting the batch also deletes its
             members, so the modal spells out the consequence before submitting. --}}
        <div class="modal fade" id="deleteBatchModal" tabindex="-1" aria-labelledby="deleteBatchModalLabel"
             aria-describedby="deleteBatchModalDescription" aria-hidden="true">
            <div class="modal-dialog tims-modal--sm modal-dialog-centered">
                <form method="POST" action="{{ route('beneficiary-batches.destroy', $batch) }}" class="modal-content">
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
                            <h2 class="modal-title" id="deleteBatchModalLabel">Delete batch</h2>
                            <p class="tims-modal-subtitle" id="deleteBatchModalDescription">This also deletes every beneficiary filed in the batch.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">
                            Delete <strong>{{ $batch->name }}</strong> and the
                            <strong>{{ number_format($memberCount) }}</strong>
                            {{ Str::plural('beneficiary', $memberCount) }} filed in it?
                        </p>
                        <div class="tims-note tims-note--danger" role="alert">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                                <path d="M12 9v4"/><path d="M12 17h.01"/>
                            </svg>
                            <div>
                                The beneficiary records are removed from the register and cannot be restored.
                                To keep them, remove them from the batch first.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete batch and beneficiaries</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if (session('created_beneficiary'))
        @php($created = session('created_beneficiary'))
        <div class="modal fade" id="beneficiaryCreatedModal" tabindex="-1"
             aria-labelledby="beneficiaryCreatedModalLabel" aria-describedby="beneficiaryCreatedModalDescription"
             aria-hidden="true">
            <div class="modal-dialog tims-modal--md modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <span class="tims-modal-icon" aria-hidden="true"
                              style="background: var(--sync-success-soft); color: var(--sync-success);">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                        </span>
                        <div>
                            <h2 class="modal-title" id="beneficiaryCreatedModalLabel">Beneficiary created</h2>
                            <p class="tims-modal-subtitle" id="beneficiaryCreatedModalDescription">
                                The record was saved and added to the batch.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="sync-kv">
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Full name</dt>
                                <dd class="sync-kv-value"><strong>{{ $created['name'] }}</strong></dd>
                            </div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Beneficiary ID</dt>
                                <dd class="sync-kv-value font-monospace">{{ $created['code'] }}</dd>
                            </div>
                            <div class="sync-kv-row">
                                <dt class="sync-kv-label">Batch</dt>
                                <dd class="sync-kv-value">{{ $created['batch'] }}</dd>
                            </div>
                        </div>

                        @if ($created['duplicate'])
                            <div class="tims-note tims-note--warning">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                                    <path d="M12 9v4"/><path d="M12 17h.01"/>
                                </svg>
                                <div>
                                    A possible duplicate was flagged
                                    {{ $created['duplicate_rule'] ? '('.$created['duplicate_rule'].')' : '' }}.
                                    An administrator can confirm or clear the flag on the beneficiary record.
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="modal-footer">
                        <a class="btn btn-outline-secondary" href="{{ $created['add_url'] }}">Add another</a>
                        <a class="btn btn-primary" href="{{ $created['url'] }}">View beneficiary</a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-batch.js') }}"></script>
<script src="{{ asset('assets/js/batch-assign.js') }}?v={{ filemtime(public_path('assets/js/batch-assign.js')) }}"></script>
<script>
(function () {
    // Removal confirmation modal: fill it from the clicked row.
    var modal = document.getElementById('removeMemberModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            if (!trigger) {
                return;
            }
            var idField = modal.querySelector('[data-remove-member-id]');
            var nameField = modal.querySelector('[data-remove-member-name]');
            var codeField = modal.querySelector('[data-remove-member-code]');
            var form = modal.querySelector('form');

            if (idField) idField.value = trigger.getAttribute('data-member-id') || '';
            if (nameField) nameField.textContent = trigger.getAttribute('data-member-name') || 'this beneficiary';
            if (codeField) codeField.textContent = trigger.getAttribute('data-member-code') || '';
            if (form) form.setAttribute('action', trigger.getAttribute('data-action') || form.getAttribute('action'));
        });
    }

    // Assignment panel: live selected count while preserving the existing
    // multi-select workflow used by batch-assign.js.
    var select = document.getElementById('assign-select');
    var selectedLabel = document.querySelector('[data-assign-selected]');
    if (select && selectedLabel) {
        var refreshSelected = function () {
            var count = select.selectedOptions.length;
            selectedLabel.textContent = count === 0
                ? 'No beneficiaries selected.'
                : count + (count === 1 ? ' beneficiary selected.' : ' beneficiaries selected.');
        };
        select.addEventListener('change', refreshSelected);
        refreshSelected();
    }
})();
</script>
@if (session('created_beneficiary'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    var createdModal = document.getElementById('beneficiaryCreatedModal');
    if (createdModal && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(createdModal).show();
    }
});
</script>
@endif
@endpush
@endsection
