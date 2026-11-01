<?php

use App\Http\Middleware\AuthenticateSyncServer;
use App\Http\Middleware\EnsureInstallationConfigured;
use App\Http\Middleware\EnsureOfflineAuthorization;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureServerAuthorization;
use App\Http\Middleware\RequireInstanceMode;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tailscale Serve terminates TLS locally and forwards over loopback
        // with X-Forwarded-* headers. Trust only the local proxy, not arbitrary
        // clients, so HTTPS detection and the real client IP work.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // The updater enables maintenance mode while it replaces files, but
        // the administrator watching the update (and the page's polling) must
        // keep working, otherwise a refresh shows 503 and the bar freezes at
        // the last value written before maintenance began.
        $middleware->preventRequestsDuringMaintenance(except: [
            'sync/setup',
            'sync/setup/connection',
            'sync/setup/app-update/status',
        ]);

        // An offline installation that has not been enrolled yet is redirected
        // to first-time onboarding, whatever page is requested. It is prepended
        // so it runs before the authentication middleware, which would
        // otherwise send a fresh device to the login page instead.
        $middleware->web(prepend: [
            EnsureInstallationConfigured::class,
        ]);

        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'sync.server' => AuthenticateSyncServer::class,
            'sync.authorized' => EnsureServerAuthorization::class,
            'offline.authorized' => EnsureOfflineAuthorization::class,
            'instance.mode' => RequireInstanceMode::class,
            'installation.configured' => EnsureInstallationConfigured::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Only send the browser back to a page on this installation. The
        // referrer is client supplied, so it cannot be trusted on its own.
        $safePreviousUrl = function (Request $request): string {
            $candidate = $request->headers->get('referer') ?: $request->session()->previousUrl();

            if (! is_string($candidate) || $candidate === '') {
                return $request->root();
            }

            $host = parse_url($candidate, PHP_URL_HOST);

            return $host === null || $host === $request->getHost() ? $candidate : $request->root();
        };

        // A stale CSRF token means the form was rendered against a session
        // that no longer exists. Laravel rewrites the token mismatch into a
        // generic 419 before render callbacks are consulted, so the status
        // code is what identifies it here.
        $exceptions->render(function (HttpException $e, Request $request) use ($safePreviousUrl): mixed {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->routeIs('logout')) {
                if (Auth::check()) {
                    Auth::logout();
                }

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }

            return redirect()
                ->to($safePreviousUrl($request))
                ->withInput(Arr::except($request->input(), [
                    '_token',
                    'current_password',
                    'password',
                    'password_confirmation',
                    'enrollment_token',
                    'reauthorization_token',
                    'preparation_token',
                ]))
                ->with('error', 'Your session expired while this page was open. The page has been refreshed with your input intact - please submit again.');
        });
    })->create();
