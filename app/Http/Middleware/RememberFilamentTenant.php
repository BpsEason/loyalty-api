<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
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

            if ($user = auth()->user()) {
                $user->unsetRelation('roles');
            }
        }

        return $next($request);
    }
}
