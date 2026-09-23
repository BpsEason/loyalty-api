<?php

namespace App\Support\Auditing;

use App\Support\Tenancy\TenantContext;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

class TenantResolver implements Resolver
{
    public static function resolve(Auditable $auditable): mixed
    {
        // 第一優先：從被操作的 Model 本身取得 tenant_id（符合專案需求：被操作資料所屬租戶）
        // 使用getAttribute方法來正確取得Eloquent模型的屬性，即使是動態屬性也能取得
        if ($auditable->getAttribute('tenant_id') !== null) {
            return $auditable->getAttribute('tenant_id');
        }

        // 備用方案：如果getAttribute失敗，嘗試直接存取屬性
        if (property_exists($auditable, 'tenant_id') && !is_null($auditable->tenant_id)) {
            return $auditable->tenant_id;
        }

        // 第二優先：使用目前租戶上下文的tenant_id
        $tenantContext = app(TenantContext::class);
        if ($tenantContext->hasTenant()) {
            return $tenantContext->getTenant()->id;
        }

        // 第三優先：如果沒有租戶上下文，但有目前登入使用者，嘗試從使用者取得tenant_id
        $user = auth()->user();
        if ($user && method_exists($user, 'isSuperAdmin') && !$user->isSuperAdmin()) {
            return $user->tenant_id;
        }

        // 最後的安全防線：如果是超級管理員且所有方法都失敗，記錄錯誤但不讓程式中斷
        // 不過根據專案設計，所有可審計的模型都應該有tenant_id
        logger()->error('TenantResolver: 無法解析tenant_id，auditable類型：' . get_class($auditable) . '，ID：' . $auditable->getKey());

        return null;
    }
}
