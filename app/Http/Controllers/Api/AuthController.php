<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\SwitchAccountRequest;
use App\Models\Membership;
use App\Models\User;
use App\Services\JwtException;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token-based authentication with stateless tenancy.
 *
 * Every issued token pair (access + refresh) embeds the active workspace,
 * the user's role in that workspace, and the role's permissions — so the
 * API needs no database lookup to determine who is acting in which
 * tenant. Switching workspace re-issues the pair (one membership check).
 */
class AuthController extends Controller
{
    public function __construct(protected JwtService $jwtService) {}

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->input('email'))->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials']);
        }

        $membership = $this->activeMembership($user, $request->integer('account_id') ?: null);

        if ($membership === null) {
            return response()->json([
                'error' => 'No active workspace membership',
                'detail' => 'An administrator must invite this user to a workspace first.',
            ], 403);
        }

        return $this->issueSession($user, (int) $membership->account_id);
    }

    /**
     * POST /api/auth/refresh
     *
     * Exchange a valid refresh token for a fresh access/refresh pair.
     * Called automatically by the SPA / mobile client on 401.
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string']]);

        try {
            $claims = $this->jwtService->verify($validated['refresh_token']);
        } catch (JwtException) {
            return response()->json([
                'error' => 'Unauthenticated',
                'detail' => 'Invalid or expired refresh token',
            ], 401);
        }

        if (($claims['type'] ?? '') !== 'refresh') {
            return response()->json([
                'error' => 'Unauthenticated',
                'detail' => 'Expected a refresh token',
            ], 401);
        }

        $user = User::query()->find($claims['sub'] ?? null);

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $membership = $this->activeMembership(
            $user,
            array_key_exists('account_id', $claims) ? (int) $claims['account_id'] : null,
        );

        if ($membership === null) {
            return response()->json([
                'error' => 'Unauthenticated',
                'detail' => 'Workspace membership no longer exists',
            ], 401);
        }

        return $this->issueSession($user, (int) $membership->account_id);
    }

    /**
     * POST /api/auth/switch-account
     *
     * Tenant switching = token refresh: verify membership in the requested
     * workspace (one query), then re-issue the JWT pair with the new
     * account_id, role, and permissions embedded.
     */
    public function switchAccount(SwitchAccountRequest $request): JsonResponse
    {
        $user = $this->currentUser();

        $membership = $this->activeMembership($user, (int) $request->input('account_id'));

        if ($membership === null) {
            return response()->json([
                'error' => 'Forbidden',
                'detail' => 'You are not a member of that workspace',
            ], 403);
        }

        return $this->issueSession($user, (int) $membership->account_id);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $claims = $request->attributes->get('jwt_claims') ?? [];
        $activeId = array_key_exists('account_id', $claims) ? (int) $claims['account_id'] : null;

        $accounts = $user->memberships()->get()
            ->map(fn (Membership $membership) => $this->describeMembership($membership, $activeId))
            ->values();

        return response()->json([
            'data' => [
                'type' => 'users',
                'id' => (string) $user->getKey(),
                'attributes' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'active_account_id' => $activeId,
                ],
            ],
            'meta' => ['accounts' => $accounts],
        ]);
    }

    /**
     * POST /api/auth/logout
     *
     * Stateless: nothing to revoke server-side, the client discards tokens.
     */
    public function logout(Request $request): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    /**
     * Find the user's active membership, preferring the requested workspace.
     */
    protected function activeMembership(User $user, ?int $accountId): ?Membership
    {
        $memberships = $user->memberships()->where('status', 'active')->get();

        if ($memberships->isEmpty()) {
            return null;
        }

        if ($accountId !== null) {
            return $memberships->firstWhere('account_id', $accountId);
        }

        return $memberships->first();
    }

    /**
     * Shape a membership for the workspace switcher UI.
     *
     * @return array<string, mixed>
     */
    protected function describeMembership(Membership $membership, ?int $activeId): array
    {
        return [
            'id' => (int) $membership->account_id,
            'name' => $membership->account?->name,
            'slug' => $membership->account?->slug,
            'role' => $membership->role?->name ?? 'Member',
            'permissions' => $membership->role?->permissions ?? [],
            'logo_url' => $membership->account?->logoUrl(),
            'active' => $membership->account_id === $activeId,
        ];
    }

    /**
     * Issue a fresh JWT pair with the workspace context embedded.
     */
    protected function issueSession(User $user, int $accountId): JsonResponse
    {
        $memberships = $user->memberships()
            ->where('status', 'active')
            ->get()
            ->map(fn (Membership $membership) => $this->describeMembership($membership, $accountId));

        $active = $memberships->firstWhere('id', $accountId);

        $claims = [
            'sub' => $user->getKey(),
            'email' => $user->email,
            'name' => $user->name,
            'account_id' => $accountId,
            'account_name' => $active['name'] ?? null,
            'role' => $active['role'] ?? 'Member',
            'permissions' => $active['permissions'] ?? [],
        ];

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $this->jwtService->issue($claims, (int) config('tenancy.access_ttl'), 'access'),
            'refresh_token' => $this->jwtService->issue($claims, (int) config('tenancy.refresh_ttl'), 'refresh'),
            'user' => [
                'id' => (int) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'active_account' => $active,
            'accounts' => $memberships->values(),
        ]);
    }
}
