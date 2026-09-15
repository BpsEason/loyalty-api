<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // 支援從 X-Tenant-ID 標頭解析租戶（供外部系統使用）
        if ($request->hasHeader('X-Tenant-ID')) {
            $tenantId = $request->header('X-Tenant-ID');

            try {
                $tenant = Tenant::find($tenantId);
            } catch (\Throwable $e) {
                $tenant = null;
            }

            if ($tenant) {
                // 驗證使用者是否有權存取此租戶
                if ($user && $user->tenant_id !== $tenant->id) {
                    return ApiResponse::error(
                        message: 'User not authorized to access this tenant.',
                        status: 403
                    );
                }

                $this->tenantContext->setTenant($tenant);

                return $next($request);
            }

            return ApiResponse::error(
                message: 'Invalid tenant identifier.',
                status: 403
            );
        }

        if ($user) {
            // Super admin can bypass tenant check
            if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
                return $next($request);
            }

            $tenant = $this->tenantResolver->resolveForUser($user);

            if (!$tenant) {
                return ApiResponse::error(
                    message: 'No tenant associated with this user.',
                    status: 403
                );
            }

            $this->tenantContext->setTenant($tenant);
        } else {
            // 既無認證使用者也無租戶標頭
            return ApiResponse::error(
                message: 'Tenant context required.',
                status: 403
            );
        }

        return $next($request);
    }
}
