<?php

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the account_id claim from the verified JWT and activates the
 * tenancy context for the duration of the request. This is the only place
 * where the active workspace is resolved; everything downstream (global
 * scopes, receipt lookups, exports) reads it from Tenancy.
 */
class ResolveActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('jwt_claims') ?? [];

        Tenancy::set(array_key_exists('account_id', $claims) ? (int) $claims['account_id'] : null);

        try {
            return $next($request);
        } finally {
            Tenancy::forget();
        }
    }
}
