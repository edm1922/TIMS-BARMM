@extends('layouts.app')

@section('title', 'Add User')

@section('content')
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
        <li class="breadcrumb-item active" aria-current="page">Add user</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0">Create new user</h1>
        <div class="small text-muted">Register a new user account and assign their role</div>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Back to users</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('users.store') }}">
            @csrf

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                           value="{{ old('name') }}" required maxlength="255" placeholder="e.g. Juan Dela Cruz">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username"
                           value="{{ old('username') }}" required maxlength="50" placeholder="e.g. jdelacruz">
                    <div class="form-text">Used to log in. Letters, numbers, dashes and underscores only.</div>
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="email">Email address <span class="text-danger">*</span></label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                           value="{{ old('email') }}" required maxlength="255" placeholder="e.g. user@example.com">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="role">User role <span class="text-danger">*</span></label>
                    <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                        <option value="">-- select role --</option>
                        @foreach ($roles as $roleKey => $roleLabel)
                            <option value="{{ $roleKey }}" @selected(old('role') === $roleKey)>{{ $roleLabel }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        <strong>Encoder:</strong> Access to Dashboard, Beneficiaries, Batches (add members), and Reports (download).
                    </div>
                    @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password"
                           required minlength="8" placeholder="Minimum 8 characters">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="password_confirmation">Confirm password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                           required minlength="8" placeholder="Repeat password">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               @checked(old('is_active', '1') === '1')>
                        <label class="form-check-label" for="is_active">
                            Active account (user can log in immediately)
                        </label>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Create user</button>
                    <a class="btn btn-outline-secondary" href="{{ route('users.index') }}">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
