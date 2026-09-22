<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RememberFilamentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if ($tenant) {
            session(['filament_tenant_id' => $tenant->getKey()]);

            // 🔑 Super Admin 不應該被強制設定任何租戶context，保持全域存取權限
            if (!($user && $user->isSuperAdmin())) {
                // 只有非Super Admin才需要設定租戶作用域
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
                app(TenantContext::class)->setTenant($tenant);

                if ($user) {
                    $user->unsetRelation('roles');
                }
            }
        }

        return $next($request);
    }
}
