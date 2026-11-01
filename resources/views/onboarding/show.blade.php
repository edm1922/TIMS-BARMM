@extends('layouts.app')

@section('title', 'First-time setup')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm mt-4">
            <div class="card-body p-4">
                <h1 class="h5 mb-1">First-time setup</h1>
                <p class="text-muted small mb-4">
                    This offline installation is not enrolled yet. Enter the enrollment token issued by the central
                    administrator, choose the Manager account for this device, and identify the area it serves.
                    Onboarding runs once; later visits go straight to the login page.
                </p>

                @if ($bootstrapUrl === null)
                    <div class="alert alert-danger small" role="alert">
                        This installation was not provisioned with a trusted Central Server address
                        (<code>SYNC_BOOTSTRAP_URL</code>). Reinstall from a package built by the Central Server, or set
                        the value in <code>.env</code>, then reload this page.
                    </div>
                @else
                    <p class="small text-muted">
                        Central Server (trusted, verified before use):
                        <span class="font-monospace">{{ $bootstrapUrl }}</span>
                    </p>
                @endif

                <form method="POST" action="{{ route('onboarding.store') }}">
                    @csrf

                    <h2 class="h6 mt-4">Enrollment</h2>
                    <div class="mb-3">
                        <label for="enrollment_token" class="form-label">Enrollment token</label>
                        <input type="text" name="enrollment_token" id="enrollment_token"
                               value="{{ old('enrollment_token') }}"
                               class="form-control @error('enrollment_token') is-invalid @enderror"
                               autocomplete="off" required>
                        @error('enrollment_token')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <h2 class="h6 mt-4">Manager account</h2>
                    <div class="mb-3">
                        <label for="manager_name" class="form-label">Full name</label>
                        <input type="text" name="manager_name" id="manager_name" value="{{ old('manager_name') }}"
                               class="form-control @error('manager_name') is-invalid @enderror"
                               autocomplete="name" required>
                        @error('manager_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="manager_username" class="form-label">Username</label>
                            <input type="text" name="manager_username" id="manager_username"
                                   value="{{ old('manager_username') }}"
                                   class="form-control @error('manager_username') is-invalid @enderror"
                                   autocomplete="username" required>
                            @error('manager_username')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="manager_password" class="form-label">Password (min 12 characters)</label>
                            <input type="password" name="manager_password" id="manager_password"
                                   class="form-control @error('manager_password') is-invalid @enderror"
                                   autocomplete="new-password" required>
                            @error('manager_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="manager_password_confirmation" class="form-label">Confirm password</label>
                            <input type="password" name="manager_password_confirmation" id="manager_password_confirmation"
                                   class="form-control" autocomplete="new-password" required>
                        </div>
                    </div>

                    <h2 class="h6 mt-4">Area served</h2>
                    <div class="mb-3">
                        <label for="area_name" class="form-label">Area name</label>
                        <input type="text" name="area_name" id="area_name" value="{{ old('area_name') }}"
                               class="form-control @error('area_name') is-invalid @enderror"
                               placeholder="e.g. Cotabato City Field Office" required>
                        <div class="form-text">A human-readable name for this device's area.</div>
                        @error('area_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="scope_type" class="form-label">Area level</label>
                            <select name="scope_type" id="scope_type"
                                    class="form-select @error('scope_type') is-invalid @enderror" required>
                                @foreach (['region' => 'Entire BARMM region', 'province' => 'Province', 'city_municipality' => 'City / Municipality', 'barangay' => 'Barangay'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('scope_type') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('scope_type')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-8">
                            <label for="scope_code" class="form-label">Area code (PSGC)</label>
                            <input type="text" name="scope_code" id="scope_code" value="{{ old('scope_code') }}"
                                   class="form-control @error('scope_code') is-invalid @enderror"
                                   placeholder="e.g. 1900 or 1900702000" required>
                            <div class="form-text">
                                Province codes:
                                @foreach ($scopeSuggestions as $code => $province)
                                    <code>{{ $code }}</code>{{ !$loop->last ? ',' : '' }}
                                @endforeach
                            </div>
                            @error('scope_code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <button class="btn btn-primary w-100 mt-4" type="submit" @disabled($bootstrapUrl === null)>
                        Complete setup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
