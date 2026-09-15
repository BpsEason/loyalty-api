<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;

Route::prefix('v1')->group(function () {
    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
        Route::get('me', [AuthController::class, 'me'])->middleware('auth:api', 'tenant');
    });

    // Protected routes that require both auth and tenant resolution
    Route::middleware(['auth:api', 'tenant'])->group(function () {
        // Future customer and point management routes will be added here
    });
});
