<?php

namespace App\Http\Controllers;

use App\Http\Traits\JsonApiResponse;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\Request;

abstract class Controller
{
    use JsonApiResponse;

    /**
     * The authenticated user attached to the request by the jwt.auth middleware.
     */
    protected function currentUser(): ?User
    {
        return $this->request()->attributes->get('user');
    }

    /**
     * The active workspace ID, resolved from the JWT (never from the URL).
     */
    protected function activeAccountId(): ?int
    {
        return Tenancy::activeId();
    }

    protected function request(): Request
    {
        return app('request');
    }
}
