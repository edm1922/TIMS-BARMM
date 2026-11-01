<?php

namespace App\Http\Middleware;

use App\Services\OfflineAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks protected write operations on an offline installation whose
 * authorization period has expired.
 *
 * Reads stay available so preserved beneficiary data, audit history, and
 * pending operations remain accessible for review and recovery.
 */
class EnsureOfflineAuthorization
{
    public function __construct(
        private readonly OfflineAuthorization $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->authorization->isExpired()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_expired',
                    'message' => 'The authorization period for this installation has expired. Reauthorization is required before new records can be saved.',
                ],
                'authorization_expires_at' => $this->authorization->expiresAt()?->toIso8601String(),
            ], 403);
        }

        return redirect()
            ->route('sync.local.index')
            ->with('error', 'The authorization period for this installation has expired. Reauthorization is required before new records can be saved.');
    }
}
