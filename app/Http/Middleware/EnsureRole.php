<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * usage: ->middleware('role:admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if (! $user->is_active) {
            auth()->logout();

            return redirect()->route('login')->withErrors([
                'username' => 'This account has been deactivated.',
            ]);
        }

        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            abort(403, 'You are not allowed to perform this action.');
        }

        return $next($request);
    }
}
