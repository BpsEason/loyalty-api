<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyMiddleware
{
    public function __construct(protected TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey || !$request->isMethod('POST')) {
            return $next($request);
        }

        // 從TenantContext獲取正確的租戶ID，確保跨租戶的冪等性鍵不會碰撞
        $tenant = $this->tenantContext->getTenant();
        $tenantId = $tenant ? $tenant->id : $request->header('X-Tenant-ID', 'global');
        $cacheKey = "idempotency:{$tenantId}:{$idempotencyKey}";

        // 檢查快取中是否已有先前儲存的回應
        if ($cachedResponse = Cache::get($cacheKey)) {
            return response()->json(
                array_merge($cachedResponse['body'], [
                    'message' => 'Point transaction retrieved (idempotent)',
                ]),
                $cachedResponse['status']
            );
        }

        /** @var Response $response */
        $response = $next($request);

        // 只將成功的建立請求 (200 / 201) 快取 24 小時
        if (in_array($response->getStatusCode(), [200, 201])) {
            $data = json_decode($response->getContent(), true);
            Cache::put($cacheKey, [
                'body' => $data,
                'status' => 200, // 後續冪等請求回傳 200
            ], now()->addHours(24));
        }

        return $response;
    }
}
