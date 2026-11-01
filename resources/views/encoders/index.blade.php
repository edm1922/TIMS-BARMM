@extends('layouts.app')

@section('title', 'Encoders')

@section('content')
@php
    $inactiveCount = $encoders->where('is_active', false)->count();
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .encoder-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        @media (max-width: 767.98px) { .sync-console .encoder-summary { grid-template-columns: 1fr; } }
        .sync-console .encoder-summary .sync-stat { padding: 16px 18px; }
        .sync-console .encoder-summary .sync-stat-value { font-size: 24px; }
        .sync-console .encoder-username { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; font-weight: 600; color: #334155; }
        .sync-console .encoder-name { font-size: 13px; font-weight: 600; }
        .sync-console .encoder-uuid { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; color: var(--sync-muted); overflow-wrap: anywhere; }
        .sync-console .encoder-edit-form { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; padding: 16px; }
        .sync-console .encoder-edit-form .span-3 { grid-column: span 3; }
        .sync-console .encoder-edit-form .span-2 { grid-column: span 2; }
        .sync-console .encoder-edit-form .span-12 { grid-column: 1 / -1; }
        @media (max-width: 991.98px) { .sync-console .encoder-edit-form .span-3 { grid-column: span 6; } .sync-console .encoder-edit-form .span-2 { grid-column: span 6; } }
        @media (max-width: 575.98px) { .sync-console .encoder-edit-form { grid-template-columns: 1fr; } .sync-console .encoder-edit-form > * { grid-column: auto !important; } }
        .sync-console .encoder-form .form-label { font-size: 12.5px; font-weight: 600; }
        .sync-console .encoder-form .form-control { min-height: 40px; border-radius: 8px; font-size: 13.5px; }
        .sync-console .encoder-form .form-text { font-size: 11.5px; }
        .sync-console .encoder-actions { white-space: nowrap; }
        .sync-console .encoder-actions .sync-btn { min-height: 32px; padding: 0 11px; font-size: 12.5px; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Encoders</h1>
            <p class="sync-page-subtitle">Field encoder accounts for this installation. Their identity is reported to the central server so audit records stay attributable.</p>
            <div class="sync-meta">
                <span><strong>{{ number_format($encoders->count()) }}</strong> {{ Str::plural('encoder', $encoders->count()) }}</span>
                <span>{{ number_format($activeCount) }} active</span>
                <span>{{ number_format($inactiveCount) }} deactivated</span>
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--secondary" href="{{ route('sync.local.index') }}">Enroll Server</a>
            <a class="sync-btn sync-btn--secondary" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    @if (session('success'))
        <div class="sync-alerts"><div class="alert alert-success mb-0">{{ session('success') }}</div></div>
    @endif

    @if ($errors->any())
        <div class="sync-alerts">
            <div class="alert alert-danger mb-0">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <section class="sync-summary encoder-summary" aria-label="Encoder overview">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Total encoders</div>
            <div class="sync-stat-value">{{ number_format($encoders->count()) }}</div>
            <div class="sync-stat-note">Accounts available on this installation.</div>
        </article>
        <article class="sync-card sync-stat sync-stat--ok">
            <div class="sync-stat-label">Active</div>
            <div class="sync-stat-value">{{ number_format($activeCount) }}</div>
            <div class="sync-stat-note">Can sign in and encode records.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Deactivated</div>
            <div class="sync-stat-value">{{ number_format($inactiveCount) }}</div>
            <div class="sync-stat-note">Accounts currently unable to sign in.</div>
        </article>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <section class="sync-card h-100" aria-labelledby="encoder-accounts-title">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title" id="encoder-accounts-title">Encoder accounts</h2>
                        <p class="sync-section-subtitle">Manage usernames, display names and account status.</p>
                    </div>
                    <span class="sync-pill sync-pill--info">{{ $activeCount }} active</span>
                </div>

                @if ($encoders->isEmpty())
                    <div class="sync-empty">
                        <div class="sync-empty-title">No encoder accounts yet</div>
                        <div class="sync-empty-note">Create one so field data entry is attributable.</div>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="sync-ops-table">
                            <caption class="visually-hidden">Encoder accounts for this installation</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Username</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Identity</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($encoders as $encoder)
                                    <tr>
                                        <td><span class="encoder-username">{{ $encoder->username }}</span></td>
                                        <td><span class="encoder-name">{{ $encoder->name }}</span></td>
                                        <td><span class="encoder-uuid">{{ $encoder->uuid }}</span></td>
                                        <td>
                                            @if ($encoder->is_active)
                                                <span class="sync-pill sync-pill--ok">Active</span>
                                            @else
                                                <span class="sync-pill sync-pill--neutral">Deactivated</span>
                                            @endif
                                        </td>
                                        <td class="text-end encoder-actions">
                                            <button class="sync-btn sync-btn--secondary sync-btn--small" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#encoder-{{ $encoder->id }}"
                                                    aria-controls="encoder-{{ $encoder->id }}" aria-expanded="false">
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                    <tr class="collapse" id="encoder-{{ $encoder->id }}">
                                        <td colspan="5" style="background: #f6f7fb;">
                                            <form method="POST" action="{{ route('encoders.update', $encoder) }}" class="encoder-edit-form encoder-form">
                                                @csrf
                                                @method('PUT')
                                                <div class="span-3">
                                                    <label class="form-label" for="name-{{ $encoder->id }}">Name</label>
                                                    <input type="text" class="form-control" id="name-{{ $encoder->id }}"
                                                           name="name" value="{{ $encoder->name }}" required>
                                                </div>
                                                <div class="span-3">
                                                    <label class="form-label" for="username-{{ $encoder->id }}">Username</label>
                                                    <input type="text" class="form-control" id="username-{{ $encoder->id }}"
                                                           name="username" value="{{ $encoder->username }}" required>
                                                </div>
                                                <div class="span-2">
                                                    <label class="form-label" for="password-{{ $encoder->id }}">New password</label>
                                                    <input type="password" class="form-control" id="password-{{ $encoder->id }}"
                                                           name="password" autocomplete="new-password" placeholder="Leave blank to keep">
                                                </div>
                                                <div class="span-2">
                                                    <label class="form-label" for="password_confirmation-{{ $encoder->id }}">Confirm</label>
                                                    <input type="password" class="form-control" id="password_confirmation-{{ $encoder->id }}"
                                                           name="password_confirmation" autocomplete="new-password">
                                                </div>
                                                <div class="span-2 d-flex align-items-end">
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="checkbox" value="1" id="is_active-{{ $encoder->id }}"
                                                               name="is_active" @checked($encoder->is_active)>
                                                        <label class="form-check-label small" for="is_active-{{ $encoder->id }}">Active</label>
                                                    </div>
                                                </div>
                                                <div class="span-12 d-flex justify-content-end gap-2">
                                                    <button class="sync-btn sync-btn--primary sync-btn--small" type="submit">Save changes</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="col-lg-5">
            <section class="sync-card encoder-form h-100" aria-labelledby="encoder-create-title">
                <div class="sync-section-head">
                    <div>
                        <h2 class="sync-section-title" id="encoder-create-title">Create an encoder account</h2>
                        <p class="sync-section-subtitle">Accounts are local to this installation.</p>
                    </div>
                </div>
                <div class="sync-body">
                    <form method="POST" action="{{ route('encoders.store') }}" class="row g-3">
                        @csrf
                        <div class="col-12">
                            <label class="form-label" for="new-name">Name</label>
                            <input type="text" class="form-control" id="new-name" name="name"
                                   value="{{ old('name') }}" placeholder="Full name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="new-username">Username</label>
                            <input type="text" class="form-control" id="new-username" name="username"
                                   value="{{ old('username') }}" placeholder="e.g. m.delacruz" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="new-password">Password</label>
                            <input type="password" class="form-control" id="new-password" name="password"
                                   autocomplete="new-password" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="new-password_confirmation">Confirm password</label>
                            <input type="password" class="form-control" id="new-password_confirmation"
                                   name="password_confirmation" autocomplete="new-password" required>
                        </div>
                        <div class="col-12">
                            <button class="sync-btn sync-btn--primary" type="submit">Create encoder</button>
                        </div>
                    </form>
                    <div class="sync-help mt-3 mb-0">
                        Encoder accounts are local to this installation. Only the username, role, status, and a
                        stable identifier are reported to the central server. Passwords never leave this device.
                    </div>
                </div>
            </section>
        </div>
    </div>

    <section class="sync-card" aria-labelledby="encoder-activity-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="encoder-activity-title">Recent encoder account activity</h2>
                <p class="sync-section-subtitle">Audit trail entries for encoder accounts on this installation.</p>
            </div>
            <span class="sync-pill sync-pill--neutral">{{ $activity->count() }}</span>
        </div>

        @if ($activity->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No encoder account changes recorded yet</div>
                <div class="sync-empty-note">Create or update an encoder to populate this activity log.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Recent encoder account audit activity</caption>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Action</th>
                            <th scope="col">Encoder</th>
                            <th scope="col">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($activity as $entry)
                            <tr>
                                <td class="sync-ops-when">{{ $entry->occurred_at?->diffForHumans() ?? $entry->created_at?->diffForHumans() }}</td>
                                <td style="font-size: 12.5px;">{{ $entry->action }}</td>
                                <td style="font-size: 12.5px;">{{ $entry->actor_username ?: 'system' }}</td>
                                <td style="font-size: 12.5px;">
                                    {{ $entry->metadata['username'] ?? '' }}
                                    @if (isset($entry->metadata['is_active']))
                                        <span class="sync-pill sync-pill--neutral">{{ $entry->metadata['is_active'] ? 'active' : 'inactive' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
