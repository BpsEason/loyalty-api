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
            Route::get('customers/{customer}/membership', [CustomerController::class, 'getMembership']);

            // Point Account API routes
            Route::get('customers/{customer}/points', [\App\Http\Controllers\Api\V1\PointAccountController::class, 'show']);

            // Point Transaction API routes
            Route::get('customers/{customer}/point-transactions', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'index']);
            // 點數即將過期查詢API - 必須放在{pointTransaction}動態路由之前
            Route::get('customers/{customer}/point-transactions/expiring', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'expiring']);
            Route::get('customers/{customer}/point-transactions/{pointTransaction}', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'show']);
            Route::post('customers/{customer}/point-transactions', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'store'])->middleware('idempotent');

            // QR Code & POS Scan endpoints for demo flow
            Route::get('customers/{customer}/qr-code', [\App\Http\Controllers\Api\V1\CustomerController::class, 'getQrCode']);
            Route::post('customers/identify', [\App\Http\Controllers\Api\V1\CustomerController::class, 'identifyByQrToken']);

            // POS-specific redemption endpoint (semantic route)
            Route::post('customers/{customer}/points/redeem', [\App\Http\Controllers\Api\V1\PointTransactionController::class, 'redeem'])->middleware('idempotent');

            // Coupon API routes
            Route::get('customers/{customer}/coupons', [\App\Http\Controllers\Api\V1\CouponController::class, 'index']);
            Route::get('customers/{customer}/coupons/{userCoupon}', [\App\Http\Controllers\Api\V1\CouponController::class, 'show']);
            Route::post('customers/{customer}/coupons/claim', [\App\Http\Controllers\Api\V1\CouponController::class, 'claim'])->middleware('idempotent');
            Route::post('customers/{customer}/coupons/{userCoupon}/redeem', [\App\Http\Controllers\Api\V1\CouponController::class, 'redeem'])->middleware('idempotent');
            // 混合支付API
            Route::post('customers/{customer}/mixed-payment', [\App\Http\Controllers\Api\V1\CouponController::class, 'mixedPayment'])->middleware('idempotent');

            // 優惠券核銷歷史查詢
            Route::get('customers/{customer}/coupon-redemptions', [\App\Http\Controllers\Api\V1\CouponController::class, 'redemptionHistory']);

            // 獎勵相關API
            Route::get('customers/{customer}/reward-grants', [\App\Http\Controllers\Api\V1\RewardController::class, 'index']);
            Route::post('customers/{customer}/rewards/grant', [\App\Http\Controllers\Api\V1\RewardController::class, 'grant'])->middleware('idempotent');
        });
    });
});
