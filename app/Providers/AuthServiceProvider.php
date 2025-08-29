<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // Model::class => Policy::class
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability) {
            return $user->hasRole('admin') ? true : null;
        });
    }
}
