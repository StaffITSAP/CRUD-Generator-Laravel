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
    ->create();
