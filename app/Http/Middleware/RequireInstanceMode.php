<?php

namespace App\Http\Middleware;

use App\Support\SyncIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInstanceMode
{
    public function handle(Request $request, Closure $next, string ...$modes): Response
    {
        if (! in_array(SyncIdentity::mode(), $modes, true)) {
            abort(404);
        }

        return $next($request);
    }
}