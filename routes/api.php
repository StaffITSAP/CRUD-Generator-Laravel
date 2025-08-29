<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;

Route::prefix('v1')->group(function () {
    // AUTH
    Route::post('auth/login',    [AuthController::class, 'login'])->middleware(['throttle:api-dinamis', 'guest:sanctum']);
    Route::post('auth/register', [AuthController::class, 'register'])->middleware(['throttle:api-dinamis', 'guest:sanctum']);
    Route::post('auth/logout',   [AuthController::class, 'logout'])->middleware(['auth:sanctum']);

    // [crud-generator] tambahkan-di-bawah
});
