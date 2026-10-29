<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC check straight from the JWT claims — no database round trip.
 * Usage: Route::middleware('permission:forms.manage')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $claims = $request->attributes->get('jwt_claims') ?? [];
        $permissions = $claims['permissions'] ?? [];

        if (! in_array($permission, $permissions, true)) {
            return response()->json([
                'error' => 'Forbidden',
                'detail' => "Requires permission: {$permission}",
            ], 403);
        }

        return $next($request);
    }
}
