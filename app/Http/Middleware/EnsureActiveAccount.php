<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects tokens that carry no workspace. Clients should call
 * POST /api/auth/switch-account to pick (or re-assert) a workspace.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Tenancy::activeId() === null) {
            return response()->json([
                'error' => 'No active workspace',
                'detail' => 'This token carries no account_id. Call POST /api/auth/switch-account to pick a workspace.',
            ], 403);
        }

        return $next($request);
    }
}
