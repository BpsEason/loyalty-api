<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
Broadcast::channel('tenant.{tenantId}.member.{memberId}', function (User $user, int $tenantId, int $memberId) {
    // 超級管理員可以存取所有租戶的所有頻道
    if ($user->isSuperAdmin()) {
        Log::info('Channel access authorized: super admin', [
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
        ]);
        return true;
    }

    // 驗證使用者是否屬於該租戶（Client提供的tenantId不可信任，必須由authenticated user驗證）
    if ((int) $user->tenant_id !== $tenantId) {
        Log::warning('Unauthorized channel access attempt: wrong tenant', [
            'user_id' => $user->id,
            'user_tenant_id' => $user->tenant_id,
            'requested_tenant_id' => $tenantId,
            'requested_member_id' => $memberId,
        ]);
        return false;
    }

    // 驗證該memberId確實屬於此租戶的Customer
    $customer = \App\Models\Customer::where('id', $memberId)
        ->where('tenant_id', $user->tenant_id)
        ->first();

    if (!$customer) {
        Log::warning('Unauthorized channel access attempt: customer not found in tenant', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'requested_member_id' => $memberId,
        ]);
        return false;
    }

    // 驗證使用者是否有權存取該會員的資料
    // 管理員可以存取所有租戶內的會員資料
    $isAdmin = $user->hasRole('tenant_admin') || $user->can('ViewAny:Customer');

    if (!$isAdmin) {
        Log::warning('Unauthorized channel access attempt: insufficient permissions', [
            'user_id' => $user->id,
            'user_tenant_id' => $user->tenant_id,
            'requested_tenant_id' => $tenantId,
            'requested_member_id' => $memberId,
            'is_admin' => $isAdmin,
        ]);
        return false;
    }

    Log::info('Channel access authorized', [
        'user_id' => $user->id,
        'tenant_id' => $tenantId,
        'member_id' => $memberId,
    ]);

    return true;
});
