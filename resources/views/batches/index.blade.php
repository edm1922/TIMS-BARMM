@extends('layouts.app')

@section('title', 'Batches')

@section('content')
@php
    $canCreate = auth()->user()->can('create', \App\Models\BeneficiaryBatch::class);
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .batch-list-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        @media (max-width: 991.98px) { .sync-console .batch-list-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575.98px) { .sync-console .batch-list-summary { grid-template-columns: 1fr; } }
        .sync-console .batch-list-summary .sync-stat { padding: 16px 18px; }
        .sync-console .batch-list-summary .sync-stat-value { font-size: 24px; }
        .sync-console .batch-name { font-weight: 600; color: var(--sync-primary); text-decoration: none; font-size: 13.5px; }
        .sync-console .batch-name:hover { text-decoration: underline; }
        .sync-console .batch-desc { font-size: 11.5px; color: var(--sync-muted); margin-top: 2px; }
        .sync-console .batch-manager { font-size: 12.5px; }
        .sync-console .batch-manager-server { font-size: 11.5px; color: var(--sync-muted); }
        .sync-console .batch-created { font-size: 11.5px; color: var(--sync-muted); white-space: nowrap; }
        .sync-console .batch-created-main { font-size: 12.5px; color: #334155; white-space: nowrap; }
        .sync-console .batch-members-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .sync-console .batch-members-cell .sync-pill { margin-left: 5px; }
        .sync-console .batch-list-actions .sync-btn { min-height: 32px; padding: 0 11px; font-size: 12.5px; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Batches</h1>
            <p class="sync-page-subtitle">Operational release groups for a round of payout or verification work.</p>
            <div class="sync-meta">
                <span><strong>{{ number_format($counts['total']) }}</strong> {{ Str::plural('batch', $counts['total']) }}</span>
                <span>{{ number_format($counts['open']) }} open</span>
                <span>{{ number_format($counts['closed']) }} closed</span>
                <span>{{ number_format($counts['members']) }} filed beneficiaries</span>
            </div>
        </div>
        <div class="sync-page-actions">
            @if ($canCreate)
                <button class="sync-btn sync-btn--primary" type="button" data-bs-toggle="modal" data-bs-target="#batchModal">
                    Create Batch
                </button>
            @endif
            <a class="sync-btn sync-btn--secondary" href="{{ route('beneficiaries.index') }}">Beneficiaries</a>
        </div>
    </header>

    @if (! $canCreate && \App\Support\SyncIdentity::isOffline())
        <div class="sync-alerts">
            <div class="alert alert-info mb-0" role="note">
                Batches are created on the central server and delivered to this installation through synchronization.
                Contact a central administrator to create a new batch.
            </div>
        </div>
    @endif

    <section class="sync-summary batch-list-summary" aria-label="Batch overview">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Total batches</div>
            <div class="sync-stat-value">{{ number_format($counts['total']) }}</div>
            <div class="sync-stat-note">Release groups on this installation.</div>
        </article>
        <article class="sync-card sync-stat sync-stat--ok">
            <div class="sync-stat-label">Open</div>
            <div class="sync-stat-value">{{ number_format($counts['open']) }}</div>
            <div class="sync-stat-note">Accepting beneficiary membership.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Closed</div>
            <div class="sync-stat-value">{{ number_format($counts['closed']) }}</div>
            <div class="sync-stat-note">Membership changes paused.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Filed beneficiaries</div>
            <div class="sync-stat-value">{{ number_format($counts['members']) }}</div>
            <div class="sync-stat-note">Active records assigned to a batch.</div>
        </article>
    </section>

    <section class="sync-card" aria-labelledby="batch-list-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="batch-list-title">All batches</h2>
                <p class="sync-section-subtitle">{{ number_format($batches->total()) }} {{ Str::plural('batch', $batches->total()) }} ordered newest first</p>
            </div>
            <span class="sync-pill sync-pill--neutral">{{ number_format($batches->total()) }}</span>
        </div>

        @if ($batches->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No batches yet.</div>
                <div class="sync-empty-note">
                    @if ($canCreate)
                        Create one to group beneficiaries for a payout round.
                    @else
                        Batches appear here once an administrator creates them.
                    @endif
                </div>
                @if ($canCreate)
                    <button class="sync-btn sync-btn--primary sync-btn--small mt-3" type="button"
                            data-bs-toggle="modal" data-bs-target="#batchModal">
                        Create Batch
                    </button>
                @endif
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Beneficiary batches</caption>
                    <thead>
                        <tr>
                            <th scope="col">Batch</th>
                            <th scope="col">Status</th>
                            <th scope="col">Assigned manager</th>
                            <th scope="col" class="text-end">Members</th>
                            <th scope="col">Created</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($batches as $batch)
                            <tr>
                                <td>
                                    <a href="{{ route('beneficiary-batches.show', $batch) }}" class="batch-name">{{ $batch->name }}</a>
                                    @if ($batch->description)
                                        <div class="batch-desc">{{ $batch->description }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($batch->isOpen())
                                        <span class="sync-pill sync-pill--ok">Open</span>
                                    @else
                                        <span class="sync-pill sync-pill--neutral">Closed</span>
                                    @endif
                                </td>
                                <td class="batch-manager">
                                    {{ $batch->managerDisplayName() ?? 'Unassigned' }}
                                    @if ($batch->manager?->server)
                                        <div class="batch-manager-server">{{ $batch->manager->server->name }}</div>
                                    @endif
                                </td>
                                <td class="text-end batch-members-cell"
                                    title="{{ $batch->capacityLimit() !== null ? 'Limit '.number_format($batch->capacityLimit()).' beneficiaries' : 'No limit set' }}">
                                    {{ $batch->capacityLabel() }}
                                    @if ($batch->isFull())
                                        <span class="sync-pill sync-pill--warning">Full</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="batch-created-main">{{ $batch->created_at?->format('Y-m-d H:i') }}</div>
                                    @if ($batch->creator)
                                        <div class="batch-created">by {{ $batch->creator->name }}</div>
                                    @endif
                                </td>
                                <td class="text-end batch-list-actions">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small"
                                       href="{{ route('beneficiary-batches.show', $batch) }}">
                                        @can('update', $batch)
                                            Manage
                                        @else
                                            View
                                        @endcan
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($batches->hasPages())
                <div class="sync-body--divided d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="sync-registry-count">
                        Showing {{ number_format($batches->firstItem() ?? 0) }}-{{ number_format($batches->lastItem() ?? 0) }}
                        of {{ number_format($batches->total()) }} batches
                    </span>
                    {{ $batches->links() }}
                </div>
            @endif
        @endif
    </section>

    @if ($canCreate)
        @include('batches.partials.create-modal')
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/beneficiary-batch.js') }}"></script>
@endpush
