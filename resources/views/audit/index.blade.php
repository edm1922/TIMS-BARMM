@extends('layouts.app')

@section('title', 'Audit log')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h4 mb-1">Audit log</h1>
        <div class="text-muted small">Who performed sensitive operations and when. {{ number_format($logs->total()) }} entries.</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Dashboard</a>
</div>

<form method="GET" action="{{ route('audit.index') }}" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-0" for="q">Search</label>
                <input type="search" class="form-control form-control-sm" id="q" name="q"
                       value="{{ $filters['q'] }}" placeholder="User, action, entity, IP">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="action">Action</label>
                <select class="form-select form-select-sm" id="action" name="action">
                    <option value="">All</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="user_id">User</label>
                <select class="form-select form-select-sm" id="user_id" name="user_id">
                    <option value="">All</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['user_id'] === (string) $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="created_from">From</label>
                <input type="date" class="form-control form-control-sm" id="created_from" name="created_from"
                       value="{{ $filters['created_from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0" for="created_to">To</label>
                <input type="date" class="form-control form-control-sm" id="created_to" name="created_to"
                       value="{{ $filters['created_to'] }}">
            </div>
            <div class="col-md-1 d-grid">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
            <tr>
                <th>When</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>IP</th>
                <th>Details</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="small text-nowrap">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td class="small">
                        @if ($log->user)
                            {{ $log->user->name }}
                        @else
                            <span class="text-muted">system</span>
                        @endif
                    </td>
                    <td><span class="badge text-bg-secondary">{{ $log->action }}</span></td>
                    <td class="small">
                        @if ($log->entity_type)
                            {{ $log->entity_type }}
                            @if ($log->entity_id)
                                #{{ $log->entity_id }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="small font-monospace">{{ $log->ip ?? '—' }}</td>
                    <td class="small">
                        @if ($log->metadata)
                            <code class="text-body d-inline-block text-truncate" style="max-width: 28rem"
                                  title="{{ json_encode($log->metadata) }}">{{ json_encode($log->metadata) }}</code>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No audit entries match these filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer">{{ $logs->links() }}</div>
</div>
@endsection
