<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model) {
            $user = auth()->user();
            $tenantResolver = app(TenantResolver::class);

            // 如果沒有使用者，直接返回，避免呼叫null的方法
            if (!$user) {
                return;
            }

            // Super Admin 建立資料時不自動填入tenant_id，讓手動指定
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return;
            }

            // 一般使用者自動填入目前的租戶ID
            if (!$model->tenant_id && $tenantId = $tenantResolver->getCurrentTenantId()) {
                $model->tenant_id = $tenantId;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $user = auth()->user();

            // Super Admin 完全跳過所有租戶限制 - 最先判斷，確保不會誤套用
            if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return;
            }

            $tenantResolver = app(TenantResolver::class);
            $tenantId = $tenantResolver->getCurrentTenantId();

            // 只要有目前的租戶ID，無論使用者狀態，都必須套用租戶範圍，防止跨租戶存取
            if ($tenantId) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
