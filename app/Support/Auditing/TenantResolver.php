<?php

namespace App\Support\Auditing;

use App\Support\Tenancy\TenantContext;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Audit;
use OwenIt\Auditing\Contracts\Resolver;

class TenantResolver implements Resolver
{
    public static function resolve(Auditable $auditable): mixed
    {
        $tenantContext = app(TenantContext::class);

        // 優先使用目前租戶上下文的tenant_id
        if ($tenantContext->hasTenant()) {
            return $tenantContext->getTenant()->id;
        }

        // 如果沒有租戶上下文，但有目前登入使用者，嘗試從使用者取得tenant_id
        $user = auth()->user();
        if ($user && method_exists($user, 'isSuperAdmin') && !$user->isSuperAdmin()) {
            return $user->tenant_id;
        }

        // 如果是超級管理員且沒有設定租戶上下文，返回null
        // 這樣超級管理員在執行全域操作時，tenant_id會是null
        return null;
    }
}
