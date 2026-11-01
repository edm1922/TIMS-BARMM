<?php

namespace App\Http\Middleware;

use App\Models\SyncSetting;
use App\Models\User;
use App\Support\SyncIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a fresh offline installation (no accounts and no enrollment), every page
 * is replaced by the first-time onboarding flow. Once the device has an account
 * or is enrolled the middleware stops interfering, so onboarding never repeats
 * unless the installation is deliberately reset.
 */
class EnsureInstallationConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SyncIdentity::isOffline()) {
            return $next($request);
        }

        if ($this->isConfigured() || $this->isExempt($request)) {
            return $next($request);
        }

        return redirect()->route('onboarding.show');
    }

    private function isConfigured(): bool
    {
        if (trim((string) SyncSetting::get('server_uuid')) !== '') {
            return true;
        }

        // A device that already has accounts has been through setup (for
        // example a silent install that seeded a Manager). Onboarding is only
        // for a genuinely fresh installation that has no account at all.
        return User::query()->exists();
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs('onboarding.*')
            || $request->routeIs('logout')
            || $request->is('up')
            || $request->is('assets/*')
            || $request->is('build/*')
            || $request->is('images/*')
            || $request->is('favicon.ico');
    }
}
