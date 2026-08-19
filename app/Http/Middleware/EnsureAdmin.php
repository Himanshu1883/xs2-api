<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureAdmin
{
    /**
     * Restrict a Sanctum-authenticated route to administrator accounts.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()?->canAccessProviderConsole()) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'You are not authorized to perform this action.',
        ], 403);
    }
}
