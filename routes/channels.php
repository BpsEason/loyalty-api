<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// 會員私人頻道 - 格式: private-tenant.{tenantId}.member.{memberId}
Broadcast::channel('tenant.{tenantId}.member.{memberId}', function ($user, $tenantId, $memberId) {
    // 🔑 1. 切換 Spatie Team ID 為當前廣播頻道的 tenantId
    $registrar = app(PermissionRegistrar::class);
    $originalTeamId = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId((int) $tenantId);

    try {
        // 2. 租戶匹配檢查
        if ((int) $user->tenant_id !== (int) $tenantId) {
            return false;
        }

        // 3. 檢查 Customer 是否存在
        $customer = \App\Models\Customer::where('id', $memberId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$customer) {
            return false;
        }

        // 4. 在正確的 Team Context 下檢查角色與權限
        $isAdmin = $user->hasRole('tenant_admin')
            || $user->can('ViewAny::Customer')
            || $user->can('ViewAny:Customer');

        return $isAdmin;
    } finally {
        // 🔑 5. 還原原始 Team ID
        $registrar->setPermissionsTeamId($originalTeamId);
    }
});