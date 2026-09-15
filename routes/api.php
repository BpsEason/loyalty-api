<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;

Route::prefix('v1')->group(function () {
    // 套用API速率限制
    Route::middleware('throttle:api')->group(function () {
        // Auth routes
        Route::prefix('auth')->group(function () {
            Route::post('login', [AuthController::class, 'login']);
            Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
            Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
            Route::get('me', [AuthController::class, 'me'])->middleware('auth:api', 'tenant');
        });

        // Protected routes that require both auth and tenant resolution
        Route::middleware(['auth:api', 'tenant'])->group(function () {
            // Customer API routes
            Route::apiResource('customers', CustomerController::class);

            // Point Account API routes
            Route::get('customers/{customer}/points', [\App\Http\Controllers\Api\V1\PointAccountController::class, 'show']);

            // Point Transaction API routes
            Route::get('customers/{customer}/point-transactions', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'index']);
            Route::get('customers/{customer}/point-transactions/{pointTransaction}', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'show']);
            Route::post('customers/{customer}/point-transactions', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'store'])->middleware('idempotent');
        });
    });
});
