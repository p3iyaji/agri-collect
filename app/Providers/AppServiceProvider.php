<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // "jwt" auth driver: resolves the user from the Bearer token
        // (stateless; the tenant itself comes from the token claims).
        $this->app['auth']->extend('jwt', function (Application $app, string $name, array $config) {
            $guard = $app->make(JwtGuard::class);

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
