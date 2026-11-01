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
            radial-gradient(1000px 380px at 15% 0%, rgba(30, 66, 155, .07), transparent 60%),
            radial-gradient(800px 380px at 90% 100%, rgba(30, 66, 155, .05), transparent 60%);
    }

    .login-container {
        display: flex;
        width: 850px;
        max-width: 100%;
        min-height: 520px;
        overflow: hidden;
        border-radius: 20px;
        background: #fff;
        box-shadow: 0 20px 45px rgba(0, 0, 0, .22), 0 10px 10px rgba(0, 0, 0, .1);
    }

    .login-overlay {
        width: 45%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2.5rem 2.2rem;
        text-align: center;
        color: #1f2937;
        background:
            radial-gradient(600px 300px at 50% 0%, rgba(30, 66, 155, .10), transparent 65%),
            #f6f7fb;
        border-right: 1px solid #e5e7eb;
    }

    .login-overlay-logo {
        width: min(150px, 70%);
        height: auto;
        margin-bottom: 1.1rem;
    }

    .login-overlay h1 {
        margin: 0;
        font-size: 2rem;
        font-weight: 800;
        letter-spacing: -.01em;
        color: #1f2937;
    }

    .login-overlay p {
        margin: .5rem 0 0;
        max-width: 28ch;
        color: #4b5563;
        font-size: .9rem;
        line-height: 1.55;
    }

    .login-overlay .login-overlay-note {
        margin-top: 1.25rem;
        font-size: .72rem;
        font-weight: 600;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #6b7280;
    }

    .login-form-panel {
        width: 55%;
        padding: 2.6rem 3.2rem;
        display: flex;
        flex-direction: column;
        justify-content: center;
        background: #f6f7fb;
    }

    .login-form-panel h2 {
        margin: 0;
        text-align: center;
        font-size: 2rem;
        font-weight: 800;
        color: #333;
    }

    .login-form-subtitle {
        margin: .35rem 0 1.4rem;
        text-align: center;
        font-size: .78rem;
        color: #777;
    }

    .login-alert {
        display: flex;
        gap: 9px;
        align-items: flex-start;
        margin-bottom: 14px;
        padding: 10px 12px;
        border: 1px solid #fecaca;
        border-radius: 8px;
        background: #fef2f2;
        color: #7f1d1d;
        font-size: .78rem;
        line-height: 1.45;
    }

    .login-alert svg { flex: 0 0 auto; margin-top: 1px; }

    .login-field { margin-bottom: 10px; }

    .login-field label {
        display: block;
        margin: 0 0 4px;
        font-size: .74rem;
        font-weight: 600;
        color: #4b5563;
    }

    .login-input-wrap { position: relative; }

    .login-input {
        width: 100%;
        min-height: 44px;
        padding: 11px 14px;
        border: 1px solid transparent;
        border-radius: 8px;
        background: #eee;
        color: #1f2937;
        font-size: .875rem;
        outline: none;
        transition: box-shadow .15s ease, background-color .15s ease;
    }

    .login-input:focus {
        background: #fff;
        box-shadow: 0 0 0 2px rgba(30, 66, 155, .2);
    }

    .login-input.is-invalid {
        background: #fff5f5;
        box-shadow: 0 0 0 2px rgba(220, 38, 38, .22);
    }

    .login-field-error {
        display: block;
        margin-top: 4px;
        color: #dc2626;
        font-size: .72rem;
    }

    .login-password-wrap .login-input { padding-right: 44px; }

    .login-password-toggle {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        padding: 0;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #6b7280;
        cursor: pointer;
    }

    .login-password-toggle:hover { background: #e5e7eb; color: #1f2937; }
    .login-password-toggle:focus-visible { outline: 2px solid #1E429B; outline-offset: 1px; }
    .login-password-toggle .eye-off { display: none; }
    .login-password-toggle[aria-pressed="true"] .eye-on { display: none; }
    .login-password-toggle[aria-pressed="true"] .eye-off { display: inline-flex; }

    .login-remember {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 2px 0 14px;
        min-height: 28px;
    }

    .login-remember .form-check-input {
        width: 15px;
        height: 15px;
        margin: 0;
        border-color: #9ca3af;
    }

    .login-remember .form-check-input:checked {
        background-color: #1E429B;
        border-color: #1E429B;
    }

    .login-remember .form-check-input:focus {
        border-color: #1E429B;
        box-shadow: 0 0 0 2px rgba(30, 66, 155, .2);
    }

    .login-remember .form-check-label {
        font-size: .78rem;
        color: #4b5563;
        cursor: pointer;
    }

    .login-submit {
        width: 100%;
        min-height: 44px;
        border: 1px solid #1E429B;
        border-radius: 20px;
        background: #1E429B;
        color: #fff;
        font-size: .76rem;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        transition: background-color .15s ease, transform 80ms ease;
    }

    .login-submit:hover { background: #16357C; border-color: #16357C; color: #fff; }
    .login-submit:active { transform: scale(.97); }
    .login-submit:focus-visible { outline: 2px solid #1E429B; outline-offset: 2px; }
    .login-submit:disabled { opacity: .7; cursor: not-allowed; }

    .login-submit .login-spinner {
        display: none;
        width: 14px;
        height: 14px;
        margin-right: 8px;
        border: 2px solid rgba(255, 255, 255, .5);
        border-top-color: #fff;
        border-radius: 50%;
        animation: login-spin .7s linear infinite;
        vertical-align: -2px;
    }

    .login-submit.is-loading .login-spinner { display: inline-block; }
    @keyframes login-spin { to { transform: rotate(360deg); } }

    .login-footer {
        margin-top: 18px;
        text-align: center;
        color: #6b7280;
        font-size: .68rem;
        line-height: 1.6;
    }

    .login-footer strong { color: #4b5563; font-weight: 600; }

    @media (max-width: 899.98px) {
        .login-container { flex-direction: column; width: 100%; min-height: 0; }
        .login-overlay { width: 100%; padding: 1.8rem 1.5rem; border-right: 0; border-bottom: 1px solid #e5e7eb; }
        .login-overlay-logo { width: 92px; margin-bottom: .65rem; }
        .login-overlay h1 { font-size: 1.5rem; }
        .login-overlay p, .login-overlay .login-overlay-note { display: none; }
        .login-form-panel { width: 100%; padding: 1.8rem 1.4rem; }
        .login-form-panel h2 { font-size: 1.6rem; }
    }

    /* The GIPS auth card is always light; keep its inputs light in dark mode too. */
    html[data-bs-theme="dark"] .login-input {
        background: #eee !important;
        color: #1f2937 !important;
        border-color: transparent !important;
    }

    html[data-bs-theme="dark"] .login-input:focus {
        background: #fff !important;
        box-shadow: 0 0 0 2px rgba(30, 66, 155, .28) !important;
    }

    html[data-bs-theme="dark"] .login-input::placeholder { color: #6b7280 !important; }
    html[data-bs-theme="dark"] .login-password-toggle { color: #6b7280; }
    html[data-bs-theme="dark"] .login-password-toggle:hover { background: #e5e7eb; color: #1f2937; }

    html[data-bs-theme="dark"] .login-remember .form-check-input {
        background-color: #fff !important;
        border-color: #9ca3af !important;
    }

    html[data-bs-theme="dark"] .login-remember .form-check-input:checked {
        background-color: #1E429B !important;
        border-color: #1E429B !important;
    }

    html[data-bs-theme="dark"] .login-footer { color: #94a3b8; }
    html[data-bs-theme="dark"] .login-footer strong { color: #e2e8f0; }

    @media (prefers-reduced-motion: reduce) {
        .login-submit .login-spinner { animation-duration: 2s; }
    }
</style>

<div class="login-shell">
    <div class="login-container">
        <div class="login-overlay">
            <img src="{{ asset('images/RGB-DOLE-LOGO-WITH-BLACK-WORDMARK-PNG.png') }}"
                 alt="Department of Labor and Employment"
                 class="login-overlay-logo" width="569" height="600" decoding="async">
            <h1>TIMS-BARMM</h1>
            <p>TUPAD INFORMATION MANAGEMENT SYSTEM OF BARMM.</p>
            <div class="login-overlay-note">DOLE 12 - South Cotabato</div>
        </div>

        <div class="login-form-panel">
            <h2>Welcome Back</h2>
            <p class="login-form-subtitle">Sign in to continue to TIMS-BARMM.</p>

            <form method="POST" action="{{ route('login.attempt') }}" id="loginForm">
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

                <div class="login-field">
                    <label for="username">Username</label>
                    <div class="login-input-wrap">
                        <input type="text" name="username" id="username" value="{{ old('username') }}"
                               class="login-input form-control @error('username') is-invalid @enderror"
                               autocomplete="username" placeholder="Username" required autofocus>
                    </div>
                    @error('username')
                        <span class="login-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="login-field">
                    <label for="password">Password</label>
                    <div class="login-input-wrap login-password-wrap">
                        <input type="password" name="password" id="password"
                               class="login-input form-control @error('password') is-invalid @enderror"
                               autocomplete="current-password" placeholder="Password" required>
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

                <div class="login-remember">
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
