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

        // 1. 若 Header 帶有 X-Tenant-ID
        if ($request->hasHeader('X-Tenant-ID')) {
            $tenantId = $request->header('X-Tenant-ID');

            try {
                $tenant = Tenant::find($tenantId);
            } catch (\Throwable $e) {
                $tenant = null;
            }

            if (!$tenant) {
                return ApiResponse::error(
                    message: 'Invalid tenant identifier.',
                    status: 403
                );
            }

            // 若有登入使用者，驗證使用者是否有權存取該 Tenant
            if ($user && (int)$user->tenant_id !== (int)$tenant->id) {
                if (!(method_exists($user, 'hasRole') && $user->hasRole('super_admin'))) {
                    return ApiResponse::error(
                        message: 'User not authorized to access this tenant.',
                        status: 403
                    );
                }
            }

            $this->tenantContext->setTenant($tenant);
            return $next($request);
        }

        // 2. 若無 Header，從 User 綁定的 Tenant 解析
        if ($user) {
            $tenant = $this->tenantResolver->resolveForUser($user);

            if (!$tenant && !(method_exists($user, 'hasRole') && $user->hasRole('super_admin'))) {
                return ApiResponse::error(
                    message: 'No tenant associated with this user.',
                    status: 403
                );
            }

            if ($tenant) {
                $this->tenantContext->setTenant($tenant);
            }

            return $next($request);
        }

        // 3. 既無 Header 也無 User
        return ApiResponse::error(
            message: 'Tenant context required.',
            status: 403
        );
    }
}
