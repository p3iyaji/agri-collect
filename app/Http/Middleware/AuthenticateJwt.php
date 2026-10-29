<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtException;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the Bearer JWT and attaches the user + verified claims
 * to the request attributes for downstream middleware and controllers.
 */
class AuthenticateJwt
{
    public function __construct(protected JwtService $jwtService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return $this->unauthenticated('Missing bearer token');
        }

        try {
            $claims = $this->jwtService->verify($token);
        } catch (JwtException $exception) {
            return $this->unauthenticated($exception->getMessage());
        }

        if (($claims['type'] ?? 'access') !== 'access') {
            return $this->unauthenticated('Expected an access token');
        }

        $user = User::query()->find($claims['sub'] ?? null);

        if ($user === null) {
            return $this->unauthenticated('User is no longer active');
        }

        $request->attributes->set('jwt_claims', $claims);
        $request->attributes->set('user', $user);

        return $next($request);
    }

    protected function unauthenticated(string $detail): Response
    {
        return response()->json([
            'error' => 'Unauthenticated',
            'detail' => $detail,
        ], 401);
    }
}
