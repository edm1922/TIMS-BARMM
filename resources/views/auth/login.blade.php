@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<style>
    .login-shell {
        min-height: calc(100vh - 3rem);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1.5rem 0;
        background:
            radial-gradient(1100px 420px at 50% -10%, rgba(37, 99, 235, .10), transparent 60%),
            radial-gradient(700px 320px at 100% 100%, rgba(18, 59, 140, .07), transparent 60%);
        border-radius: 1rem;
    }

    .login-card {
        width: 100%;
        max-width: 460px;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 22px 48px -22px rgba(15, 23, 42, .28), 0 2px 8px rgba(15, 23, 42, .05);
        overflow: hidden;
        background: #fff;
    }

    .login-card-accent {
        height: 4px;
        background: linear-gradient(90deg, #123b8c 0 55%, #dc2626 55% 100%);
    }

    .login-body {
        padding: 34px 36px 30px;
    }

    .login-logo {
        display: block;
        width: 96px;
        max-width: 42%;
        height: auto;
        margin: 0 auto 14px;
    }

    .login-brand-title {
        margin: 0;
        font-size: 21px;
        font-weight: 700;
        letter-spacing: .01em;
        color: #0f172a;
        text-align: center;
    }

    .login-brand-subtitle {
        margin: 4px 0 0;
        font-size: 11.5px;
        font-weight: 500;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: #64748b;
        text-align: center;
    }

    .login-divider {
        height: 1px;
        background: #e2e8f0;
        margin: 22px 0 20px;
    }

    .login-heading {
        margin: 0;
        font-size: 19px;
        font-weight: 600;
        color: #0f172a;
        text-align: center;
    }

    .login-subheading {
        margin: 4px 0 0;
        font-size: 12.5px;
        color: #64748b;
        text-align: center;
    }

    .login-form-label {
        font-size: 12.5px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 5px;
    }

    .login-input-wrap { position: relative; }

    .login-input-wrap .login-input-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
        display: inline-flex;
    }

    .login-input {
        min-height: 46px;
        padding: 10px 12px 10px 38px;
        font-size: 14px;
        color: #0f172a;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .login-input::placeholder { color: #94a3b8; }

    .login-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .18);
    }

    .login-input.is-invalid {
        border-color: #dc2626;
        background-image: none;
    }

    .login-input.is-invalid:focus {
        box-shadow: 0 0 0 3px rgba(220, 38, 38, .15);
    }

    .login-field-error {
        display: block;
        margin-top: 5px;
        font-size: 12px;
        color: #dc2626;
    }

    .login-password-wrap .login-input { padding-right: 46px; }

    .login-password-toggle {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #64748b;
        cursor: pointer;
    }

    .login-password-toggle:hover { background: #f1f5f9; color: #0f172a; }
    .login-password-toggle:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .login-password-toggle .eye-off { display: none; }
    .login-password-toggle[aria-pressed="true"] .eye-on { display: none; }
    .login-password-toggle[aria-pressed="true"] .eye-off { display: inline-flex; }

    .login-remember {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 32px;
    }

    .login-remember .form-check-input {
        margin-top: 0;
        width: 16px;
        height: 16px;
        border-color: #94a3b8;
    }

    .login-remember .form-check-input:checked {
        background-color: #2563eb;
        border-color: #2563eb;
    }

    .login-remember .form-check-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .18);
    }

    .login-remember .form-check-label {
        font-size: 13px;
        color: #334155;
        cursor: pointer;
    }

    .login-submit {
        min-height: 46px;
        width: 100%;
        border: 0;
        border-radius: 8px;
        background: #2563eb;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        transition: background-color .16s ease, box-shadow .16s ease;
    }

    .login-submit:hover { background: #1d4ed8; color: #fff; }
    .login-submit:focus-visible { outline: 2px solid #1d4ed8; outline-offset: 2px; }

    .login-submit:disabled {
        background: #93c5fd;
        cursor: not-allowed;
        opacity: 1;
    }

    .login-submit .login-spinner {
        display: none;
        width: 15px;
        height: 15px;
        margin-right: 8px;
        border: 2px solid rgba(255, 255, 255, .5);
        border-top-color: #fff;
        border-radius: 50%;
        animation: login-spin .7s linear infinite;
        vertical-align: -2px;
    }

    .login-submit.is-loading .login-spinner { display: inline-block; }

    @keyframes login-spin { to { transform: rotate(360deg); } }

    .login-alert {
        display: flex;
        gap: 9px;
        align-items: flex-start;
        margin-bottom: 16px;
        padding: 10px 12px;
        border: 1px solid #fecaca;
        border-radius: 8px;
        background: #fef2f2;
        color: #7f1d1d;
        font-size: 12.5px;
        line-height: 1.45;
    }

    .login-alert svg { flex: 0 0 auto; margin-top: 1px; }

    .login-footer {
        margin-top: 20px;
        text-align: center;
        color: #64748b;
        font-size: 11px;
        line-height: 1.6;
    }

    .login-footer strong { color: #475569; font-weight: 600; }

    @media (max-width: 575.98px) {
        .login-shell { padding: .75rem 0; min-height: calc(100vh - 1.5rem); }
        .login-body { padding: 26px 20px 22px; }
        .login-logo { width: 84px; }
        .login-brand-title { font-size: 19px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .login-submit .login-spinner { animation-duration: 2s; }
    }
</style>

<div class="login-shell">
    <div class="login-card">
        <div class="login-card-accent" aria-hidden="true"></div>
        <div class="login-body">
            <div class="text-center">
                <img src="{{ asset('images/RGB-DOLE-LOGO-WITH-BLACK-WORDMARK-PNG.png') }}"
                     alt="Department of Labor and Employment"
                     class="login-logo" width="569" height="600" decoding="async">
                <h1 class="login-brand-title">TIMS-BARMM</h1>
                <p class="login-brand-subtitle">Department of Labor and Employment</p>
            </div>

            <div class="login-divider" aria-hidden="true"></div>

            <h2 class="login-heading">Welcome Back</h2>
            <p class="login-subheading">Sign in to continue to TIMS-BARMM.</p>

            <form method="POST" action="{{ route('login.attempt') }}" class="mt-4" id="loginForm">
                @csrf

                @if ($errors->any())
                    <div class="login-alert" role="alert">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                            <path d="M12 9v4"/><path d="M12 17h.01"/>
                        </svg>
                        <div>{{ $errors->first() }}</div>
                    </div>
                @endif

                <div class="mb-3">
                    <label for="username" class="login-form-label">Username</label>
                    <div class="login-input-wrap">
                        <span class="login-input-icon" aria-hidden="true">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>
                            </svg>
                        </span>
                        <input type="text" name="username" id="username" value="{{ old('username') }}"
                               class="form-control login-input @error('username') is-invalid @enderror"
                               autocomplete="username" placeholder="Enter your username" required autofocus>
                    </div>
                    @error('username')
                        <span class="login-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="login-form-label">Password</label>
                    <div class="login-input-wrap login-password-wrap">
                        <span class="login-input-icon" aria-hidden="true">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <input type="password" name="password" id="password"
                               class="form-control login-input @error('password') is-invalid @enderror"
                               autocomplete="current-password" placeholder="Enter your password" required>
                        <button type="button" class="login-password-toggle" id="passwordToggle"
                                aria-label="Show password" aria-pressed="false" aria-controls="password"
                                title="Show password">
                            <svg class="eye-on" width="17" height="17" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="eye-off" width="17" height="17" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.5 0 10 7 10 7a17.8 17.8 0 0 1-2.8 3.9"/>
                                <path d="M6.6 6.6C3.6 8.5 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/>
                                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="m3 3 18 18"/>
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <span class="login-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="login-remember mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"
                           @checked(old('remember'))>
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>

                <button class="login-submit" type="submit" id="loginSubmit">
                    <span class="login-spinner" aria-hidden="true"></span>
                    <span data-login-label>Sign In</span>
                </button>
            </form>
        </div>
    </div>

    <footer class="login-footer">
        <strong>Authorized Personnel Only</strong><br>
        TIMS-BARMM | Department of Labor and Employment
    </footer>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var toggle = document.getElementById('passwordToggle');
    var password = document.getElementById('password');

    if (toggle && password) {
        toggle.addEventListener('click', function () {
            var show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggle.setAttribute('title', show ? 'Hide password' : 'Show password');
            password.focus({ preventScroll: true });
        });
    }

    var form = document.getElementById('loginForm');
    var submit = document.getElementById('loginSubmit');

    if (form && submit) {
        form.addEventListener('submit', function () {
            if (submit.dataset.loading === '1') {
                return;
            }

            submit.dataset.loading = '1';
            submit.disabled = true;
            submit.classList.add('is-loading');
            submit.setAttribute('aria-busy', 'true');

            var label = submit.querySelector('[data-login-label]');
            if (label) {
                label.textContent = 'Signing in...';
            }
        });
    }
})();
</script>
@endpush
