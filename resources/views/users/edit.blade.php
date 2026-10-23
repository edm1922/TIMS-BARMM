@extends('layouts.app')

@section('title', 'Edit User: '.$user->name)

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $user->name }}</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0">Edit user: {{ $user->name }}</h1>
        <div class="small text-muted">Update user account information and role</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Back to users</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                           value="{{ old('name', $user->name) }}" required maxlength="255">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username"
                           value="{{ old('username', $user->username) }}" required maxlength="50">
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="email">Email address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                           value="{{ old('email', $user->email) }}" required maxlength="255">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="role">User role <span class="text-danger">*</span></label>
                    <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required
                            @if ($user->id === auth()->id()) disabled @endif>
                        @foreach ($roles as $roleKey => $roleLabel)
                            <option value="{{ $roleKey }}" @selected(old('role', $user->role) === $roleKey)>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                    @if ($user->id === auth()->id())
                        <input type="hidden" name="role" value="{{ $user->role }}">
                        <div class="form-text text-muted">You cannot change your own role.</div>
                    @else
                        <div class="form-text">
                            <strong>Encoder:</strong> Access to Dashboard, Beneficiaries, Batches (add members), and Reports (download).
                        </div>
                    @endif
                    @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="password">New password</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password"
                           minlength="8" placeholder="Leave blank to keep existing password">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="password_confirmation">Confirm new password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                           minlength="8" placeholder="Repeat new password">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               @checked(old('is_active', $user->is_active ? '1' : '0') === '1')
                               @if ($user->id === auth()->id()) disabled @endif>
                        <label class="form-check-label" for="is_active">
                            Active account
                        </label>
                        @if ($user->id === auth()->id())
                            <input type="hidden" name="is_active" value="1">
                            <span class="text-muted small ms-2">(You cannot deactivate your own account)</span>
                        @endif
                    </div>
                </div>

                <div class="col-12 d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Update user</button>
                    <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
