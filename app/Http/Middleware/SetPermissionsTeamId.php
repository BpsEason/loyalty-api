<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // 如果使用者屬於某個租戶，自動設定 Spatie Team ID
            if ($user->tenant_id) {
                setPermissionsTeamId($user->tenant_id);
            } else {
                // 若為 Super Admin (tenant_id === null)，設定為全域 Team ID (0)
                setPermissionsTeamId(config('permission.default_team_id', 0));
            }

            // 清除已在舊 team_id 下快取的角色關聯
            $user->unsetRelation('roles');

            \Illuminate\Support\Facades\Log::info('[Middleware] SetPermissionsTeamId executed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'permissions_team_id' => getPermissionsTeamId(),
            ]);
        }

        return $next($request);
    }
}
