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

        if ($tenant) {
            session(['filament_tenant_id' => $tenant->getKey()]);
            // 🔑 設定Spatie Permission的team_id，確保角色查詢作用域正確
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
            // 🔑 設定應用程式的TenantContext，確保所有Model的全域範圍都能取得正確的目前租戶
            app(TenantContext::class)->setTenant($tenant);

            if ($user = auth()->user()) {
                $user->unsetRelation('roles');
            }
        }

        return $next($request);
    }
}
