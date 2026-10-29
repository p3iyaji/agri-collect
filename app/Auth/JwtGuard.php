<?php

namespace App\Auth;

use App\Models\User;
use App\Services\JwtException;
use App\Services\JwtService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Stateless guard that resolves the user from a Bearer JWT.
 *
 * Registered as the "jwt" auth driver in AppServiceProvider. A single
 * primary-key lookup per request maps the token's sub claim to a user;
 * tenant identity itself comes entirely from the token (see Tenancy).
 */
class JwtGuard implements Guard
{
    protected ?Authenticatable $user = null;

    /** @var array<string, mixed>|null */
    protected ?array $claims = null;

    protected ?Request $request = null;

    public function __construct(protected JwtService $jwtService) {}

    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request?->bearerToken();

        if ($token === null) {
            return null;
        }

        try {
            $claims = $this->jwtService->verify($token);
        } catch (JwtException) {
            return null;
        }

        if (($claims['type'] ?? 'access') !== 'access') {
            return null;
        }

        $this->claims = $claims;

        $this->user = User::query()->find($claims['sub'] ?? null);

        return $this->user;
    }

    /**
     * The verified claims of the current token (null when unauthenticated).
     *
     * @return array<string, mixed>|null
     */
    public function claims(): ?array
    {
        $this->user();

        return $this->claims;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthKey();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user() !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }
}
