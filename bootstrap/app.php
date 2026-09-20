<?php

use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\TenantMiddleware::class,
            'idempotent' => \App\Http\Middleware\DatabaseIdempotencyMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 統一處理驗證錯誤 (422)
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'Validation failed',
                    errors: $e->errors(),
                    status: 422
                );
            }
        });

        // 統一處理未認證錯誤 (401)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'Unauthenticated',
                    status: 401
                );
            }
        });

        // 統一處理找不到資源錯誤 (404)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'Resource not found',
                    status: 404
                );
            }
        });

        // API 全域未捕捉例外 (500)
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $debugData = app()->environment('local') ? [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ] : null;

                return ApiResponse::error(
                    message: app()->environment('local') ? $e->getMessage() : 'Internal Server Error',
                    data: $debugData,
                    status: 500
                );
            }
        });
    })->create();
