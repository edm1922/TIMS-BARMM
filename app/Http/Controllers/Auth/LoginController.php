<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');
        $loginInput = trim($input['username']);

        $field = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $authenticated = Auth::attempt([
            $field => $loginInput,
            'password' => $input['password'],
            'is_active' => true,
        ], $remember);

        if (! $authenticated && $field === 'username') {
            $authenticated = Auth::attempt([
                'email' => $loginInput,
                'password' => $input['password'],
                'is_active' => true,
            ], $remember);
        }

        if (! $authenticated) {
            AuditLogger::log('auth.login_failed', metadata: ['username' => $loginInput]);

            throw ValidationException::withMessages([
                'username' => 'Invalid credentials or inactive account.',
            ]);
        }

        $request->session()->regenerate();

        AuditLogger::log('auth.login');

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            AuditLogger::log('auth.logout');
            Auth::logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
