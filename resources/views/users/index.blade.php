@extends('layouts.app')

@section('title', 'Users & Roles')

@section('content')
@php
    $hasFilters = $filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== '';

    $filterLabels = [];
    if ($filters['q'] !== '') {
        $filterLabels['Search'] = $filters['q'];
    }
    if ($filters['role'] !== '') {
        $filterLabels['Role'] = $roles[$filters['role']] ?? $filters['role'];
    }
    if ($filters['status'] !== '') {
        $filterLabels['Status'] = ucfirst($filters['status']);
    }

    $roleTone = fn (string $role): string => match ($role) {
        \App\Models\User::ROLE_ADMIN => 'sync-pill--info',
        \App\Models\User::ROLE_MANAGER => 'sync-pill--info',
        \App\Models\User::ROLE_ENCODER => 'sync-pill--ok',
        default => 'sync-pill--neutral',
    };
@endphp

<div class="sync-console">
    @include('partials.sync-console-styles')

    <style>
        .sync-console .user-summary { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        @media (max-width: 1199.98px) { .sync-console .user-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 767.98px) { .sync-console .user-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .sync-console .user-summary .sync-stat { padding: 16px 18px; }
        .sync-console .user-summary .sync-stat-value { font-size: 24px; }
        .sync-console .reg-filter-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 12px; padding: 18px 20px; }
        .sync-console .reg-filter-grid .span-5 { grid-column: span 5; }
        .sync-console .reg-filter-grid .span-3 { grid-column: span 3; }
        .sync-console .reg-filter-grid .span-2 { grid-column: span 2; }
        @media (max-width: 991.98px) {
            .sync-console .reg-filter-grid .span-5, .sync-console .reg-filter-grid .span-3, .sync-console .reg-filter-grid .span-2 { grid-column: span 6; }
        }
        @media (max-width: 575.98px) {
            .sync-console .reg-filter-grid { grid-template-columns: 1fr; padding: 16px; }
            .sync-console .reg-filter-grid > * { grid-column: auto !important; }
        }
        .sync-console .user-search-wrap { position: relative; }
        .sync-console .user-search-wrap .sync-input { padding-left: 34px; }
        .sync-console .user-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--sync-muted); pointer-events: none; display: inline-flex; }
        .sync-console .user-name { font-weight: 600; font-size: 13px; color: var(--sync-text); }
        .sync-console .user-username { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; color: #334155; }
        .sync-console .user-table-actions { white-space: nowrap; }
        .sync-console .user-table-actions .sync-btn { min-height: 32px; padding: 0 11px; font-size: 12.5px; }
        .sync-console .user-filter-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .sync-console .user-filter-chip { display: inline-flex; gap: 4px; font-size: 11.5px; padding: 3px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; border: 1px solid #e5e7eb; }
    </style>

    <header class="sync-page-head">
        <div>
            <h1 class="sync-page-title">Users &amp; Roles</h1>
            <p class="sync-page-subtitle">Monitor system user accounts, role permissions and offline installation accounts.</p>
            <div class="sync-meta">
                <span><strong>{{ number_format($roleCounts['total']) }}</strong> central {{ Str::plural('account', $roleCounts['total']) }}</span>
                <span>{{ number_format($offlineAccounts->count()) }} offline accounts reported</span>
            </div>
        </div>
        <div class="sync-page-actions">
            <a class="sync-btn sync-btn--secondary" href="{{ route('dashboard') }}">Dashboard</a>
        </div>
    </header>

    <section class="sync-summary user-summary" aria-label="User overview">
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Total Users</div>
            <div class="sync-stat-value">{{ number_format($roleCounts['total']) }}</div>
            <div class="sync-stat-note">Central accounts with dashboard access.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Administrators</div>
            <div class="sync-stat-value">{{ number_format($roleCounts['admin']) }}</div>
            <div class="sync-stat-note">Full administrative access.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Encoders</div>
            <div class="sync-stat-value">{{ number_format($roleCounts['encoder']) }}</div>
            <div class="sync-stat-note">Beneficiary encoding accounts.</div>
        </article>
        <article class="sync-card sync-stat">
            <div class="sync-stat-label">Staff</div>
            <div class="sync-stat-value">{{ number_format($roleCounts['staff']) }}</div>
            <div class="sync-stat-note">Read/limited operational accounts.</div>
        </article>
        <article class="sync-card sync-stat {{ $roleCounts['inactive'] > 0 ? 'sync-stat--warning' : '' }}">
            <div class="sync-stat-label">Inactive</div>
            <div class="sync-stat-value">{{ number_format($roleCounts['inactive']) }}</div>
            <div class="sync-stat-note">Accounts currently unable to sign in.</div>
        </article>
    </section>

    <form method="GET" action="{{ route('users.index') }}" class="sync-card mb-3">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title">Search &amp; filter users</h2>
                <p class="sync-section-subtitle">Filters apply to the central account list.</p>
            </div>
            @if ($hasFilters)
                <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('users.index') }}">Clear filters</a>
            @endif
        </div>
        <div class="reg-filter-grid">
            <div class="span-5">
                <label class="sync-kv-label d-block mb-1" for="q">Search</label>
                <div class="user-search-wrap">
                    <span class="user-search-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>
                    </span>
                    <input type="search" class="sync-input" id="q" name="q" value="{{ $filters['q'] }}"
                           placeholder="Search by name, username, or email"
                           onsearch="if (this.value === '') this.form.submit()">
                </div>
            </div>
            <div class="span-3">
                <label class="sync-kv-label d-block mb-1" for="role">Role</label>
                <select class="sync-input" id="role" name="role" onchange="this.form.submit()">
                    <option value="">All roles</option>
                    @foreach ($roles as $roleKey => $roleLabel)
                        <option value="{{ $roleKey }}" @selected($filters['role'] === $roleKey)>{{ $roleLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="span-2">
                <label class="sync-kv-label d-block mb-1" for="status">Status</label>
                <select class="sync-input" id="status" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="span-2 d-flex align-items-end gap-2">
                <button class="sync-btn sync-btn--primary sync-btn--small" type="submit">Filter</button>
                @if ($hasFilters)
                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('users.index') }}">Reset</a>
                @endif
            </div>
        </div>
    </form>

    <section class="sync-card mb-3" aria-labelledby="central-users-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="central-users-title">System users</h2>
                <p class="sync-section-subtitle">{{ number_format($users->total()) }} {{ Str::plural('account', $users->total()) }} matching the current filters</p>
            </div>
            <div class="user-filter-chips">
                @foreach ($filterLabels as $label => $value)
                    <span class="user-filter-chip">{{ $label }}: <strong>{{ $value }}</strong></span>
                @endforeach
            </div>
        </div>

        @if ($users->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No users found matching your criteria.</div>
                <div class="sync-empty-note">Adjust the search or <a href="{{ route('users.index') }}">clear the filters</a>.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Central user accounts</caption>
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Username</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Created</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr>
                                <td>
                                    <div class="user-name">{{ $user->name }}</div>
                                    @if ($user->id === auth()->id())
                                        <span class="text-muted" style="font-size: 11px;">You</span>
                                    @endif
                                </td>
                                <td><span class="user-username">{{ $user->username }}</span></td>
                                <td style="font-size: 12.5px;">{{ $user->email }}</td>
                                <td><span class="sync-pill {{ $roleTone($user->role) }}">{{ ucfirst($user->role) }}</span></td>
                                <td>
                                    @if ($user->is_active)
                                        <span class="sync-pill sync-pill--ok">Active</span>
                                    @else
                                        <span class="sync-pill sync-pill--danger">Inactive</span>
                                    @endif
                                </td>
                                <td class="sync-ops-when">{{ $user->created_at?->format('M d, Y') ?? '-' }}</td>
                                <td class="text-end user-table-actions">
                                    <a class="sync-btn sync-btn--secondary sync-btn--small" href="{{ route('users.edit', $user) }}">Edit</a>
                                    @if ($user->id !== auth()->id())
                                        <button type="button" class="sync-btn sync-btn--small"
                                                style="color: var(--sync-danger); border-color: #fecaca; background: #fff;"
                                                data-bs-toggle="modal" data-bs-target="#deleteUserModal"
                                                data-user-id="{{ $user->id }}"
                                                data-user-name="{{ $user->name }}"
                                                data-user-username="{{ $user->username }}"
                                                data-action="{{ route('users.destroy', $user) }}">
                                            Delete
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="sync-body--divided d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="sync-registry-count">
                        Showing {{ number_format($users->firstItem() ?? 0) }}-{{ number_format($users->lastItem() ?? 0) }}
                        of {{ number_format($users->total()) }} users
                    </span>
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </section>

    <section class="sync-card" aria-labelledby="offline-accounts-title">
        <div class="sync-section-head">
            <div>
                <h2 class="sync-section-title" id="offline-accounts-title">Offline installation accounts</h2>
                <p class="sync-section-subtitle">
                    Managers and encoders are created on their own offline installation. The central server
                    keeps this registry read-only for monitoring; passwords and contact details are never reported.
                </p>
            </div>
            <span class="sync-pill sync-pill--info">{{ $offlineAccounts->count() }}</span>
        </div>

        @if ($offlineAccounts->isEmpty())
            <div class="sync-empty">
                <div class="sync-empty-title">No offline accounts reported yet</div>
                <div class="sync-empty-note">No offline installation has reported managers or encoders yet.</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="sync-ops-table">
                    <caption class="visually-hidden">Offline installation manager and encoder registry</caption>
                    <thead>
                        <tr>
                            <th scope="col">Server</th>
                            <th scope="col">Name</th>
                            <th scope="col">Username</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Reported</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($offlineAccounts as $account)
                            <tr>
                                <td style="font-size: 12.5px;">
                                    @if ($account->server)
                                        <a href="{{ route('sync-servers.show', $account->server) }}" class="text-decoration-none">{{ $account->server->name }}</a>
                                    @else
                                        <span class="text-muted">Removed server</span>
                                    @endif
                                </td>
                                <td style="font-size: 12.5px;">{{ $account->name ?? '-' }}</td>
                                <td><span class="user-username">{{ $account->username }}</span></td>
                                <td>
                                    @if ($account->role === \App\Models\User::ROLE_MANAGER)
                                        <span class="sync-pill sync-pill--info">Manager</span>
                                    @else
                                        <span class="sync-pill sync-pill--neutral">Encoder</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($account->is_active)
                                        <span class="sync-pill sync-pill--ok">Active</span>
                                    @else
                                        <span class="sync-pill sync-pill--danger">Inactive</span>
                                    @endif
                                </td>
                                <td class="sync-ops-when">{{ $account->reported_at?->diffForHumans() ?? 'Not reported' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Destructive action confirmation, shared modal system. --}}
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel"
         aria-describedby="deleteUserModalDescription" aria-hidden="true">
        <div class="modal-dialog tims-modal--sm modal-dialog-centered">
            <form method="POST" action="{{ route('users.destroy', '__USER__') }}" class="modal-content" data-delete-user-form>
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
                        <h2 class="modal-title" id="deleteUserModalLabel">Delete user account</h2>
                        <p class="tims-modal-subtitle" id="deleteUserModalDescription">This removes access for the account.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">
                        Delete <strong data-delete-user-name>this user</strong>
                        (<span class="font-monospace" data-delete-user-username></span>)?
                    </p>
                    <div class="tims-note tims-note--danger" role="alert">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                            <path d="M12 9v4"/><path d="M12 17h.01"/>
                        </svg>
                        <div>The account can no longer sign in. The action is recorded in the audit trail.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete user</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var modal = document.getElementById('deleteUserModal');
    if (!modal) {
        return;
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) {
            return;
        }

        var form = modal.querySelector('[data-delete-user-form]');
        var name = modal.querySelector('[data-delete-user-name]');
        var username = modal.querySelector('[data-delete-user-username]');

        if (name) name.textContent = trigger.getAttribute('data-user-name') || 'this user';
        if (username) username.textContent = trigger.getAttribute('data-user-username') || '';
        if (form) form.setAttribute('action', trigger.getAttribute('data-action') || form.getAttribute('action'));
    });
})();
</script>
@endpush
