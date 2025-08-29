<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Rate limiting per user
        RateLimiter::for('api-dinamis', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return [
                Limit::perMinute(120)->by($key),
                Limit::perMinute(60)->by($key . '-exports')->response(function () {
                    return response()->json([
                        'message' => 'Terlalu banyak request export, coba lagi nanti.'
                    ], 429);
                }),
            ];
        });
    }
}
