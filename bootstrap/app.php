<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Group 'api' sudah ada default-nya; tambah CORS & Sanctum jika perlu
        $middleware->append(\Illuminate\Http\Middleware\HandleCors::class);
        // Anda bisa menambahkan middleware global lain di sini
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withBoot(function () {
        // Rate limit per user (fallback IP)
        RateLimiter::for('api-dinamis', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return [
                Limit::perMinute(120)->by($key), // ganti sesuai kebutuhan
                Limit::perMinute(60)->by($key . '-exports')->response(function () {
                    return response()->json([
                        'message' => 'Terlalu banyak request export, coba lagi nanti.'
                    ], 429);
                }),
            ];
        });
    })
    ->create();
