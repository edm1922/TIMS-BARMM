@extends('layouts.app')

@section('title', 'Log in')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card shadow-sm mt-4">
            <div class="card-body p-4">
                <h1 class="h5 mb-1">TUPAD Beneficiary LOB System</h1>
                <p class="text-muted small mb-4">Sign in to continue.</p>

                <form method="POST" action="{{ route('login.attempt') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" name="username" id="username" value="{{ old('username') }}"
                               class="form-control @error('username') is-invalid @enderror"
                               autocomplete="username" required autofocus>
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               autocomplete="current-password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                        <label class="form-check-label" for="remember">Remember me</label>
                    </div>

                    <button class="btn btn-primary w-100" type="submit">Log in</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
