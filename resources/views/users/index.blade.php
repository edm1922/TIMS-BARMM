@extends('layouts.app')

@section('title', 'Users & Roles')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Users &amp; Roles</h1>
        <div class="text-muted small">Manage system user accounts and role permissions</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-primary" href="{{ route('users.create') }}">Add user</a>
        <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Dashboard</a>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="h5 mb-0">{{ number_format($roleCounts['total']) }}</div>
            <div class="text-muted small">Total Users</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="h5 mb-0 text-primary">{{ number_format($roleCounts['admin']) }}</div>
            <div class="text-muted small">Administrators</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="h5 mb-0 text-success">{{ number_format($roleCounts['encoder']) }}</div>
            <div class="text-muted small">Encoders</div>
        </div>
    </div>
    <div class="col-sm-6 col-md-3">
        <div class="card p-2 text-center">
            <div class="h5 mb-0 text-secondary">{{ number_format($roleCounts['staff']) }}</div>
            <div class="text-muted small">Staff</div>
        </div>
    </div>
</div>

<form method="GET" action="{{ route('users.index') }}" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-0" for="q">Search</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] }}" placeholder="Search by name, username, or email"
                       onsearch="if (this.value === '') this.form.submit()">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-0" for="role">Role</label>
                <select class="form-select form-select-sm" id="role" name="role" onchange="this.form.submit()">
                    <option value="">All roles</option>
                    @foreach ($roles as $roleKey => $roleLabel)
                        <option value="{{ $roleKey }}" @selected($filters['role'] === $roleKey)>{{ $roleLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                @if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== '')
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('users.index') }}">Reset</a>
                @endif
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td class="fw-medium">{{ $user->name }}</td>
                        <td><code>{{ $user->username }}</code></td>
                        <td>{{ $user->email }}</td>
                        <td>
                            @php
                                $badgeClass = match($user->role) {
                                    'admin' => 'text-bg-primary',
                                    'encoder' => 'text-bg-success',
                                    'staff' => 'text-bg-secondary',
                                    default => 'text-bg-dark',
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ ucfirst($user->role) }}</span>
                        </td>
                        <td>
                            @if ($user->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger">Inactive</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $user->created_at?->format('M d, Y') ?? '—' }}</td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('users.edit', $user) }}">Edit</a>
                            @if ($user->id !== auth()->id())
                                <form method="POST" action="{{ route('users.destroy', $user) }}" class="d-inline"
                                      onsubmit="return confirm('Are you sure you want to delete user {{ $user->username }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No users found matching your criteria.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())
        <div class="card-footer bg-white py-2">
            {{ $users->links() }}
        </div>
    @endif
</div>
@endsection
