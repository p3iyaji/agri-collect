<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For explicit /workspaces/{account} routes: the workspace named in the
 * URL must be the workspace embedded in the token. A user can only ever
 * read data from a workspace they are a member of (the token proves it).
 */
class EnsureWorkspaceMatches
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeAccount = (int) $request->route('account');

        if (Tenancy::activeId() !== $routeAccount) {
            return response()->json([
                'error' => 'Workspace mismatch',
                'detail' => 'This token is scoped to a different workspace. Switch account or target your own workspace.',
            ], 403);
        }

        return $next($request);
    }
}
