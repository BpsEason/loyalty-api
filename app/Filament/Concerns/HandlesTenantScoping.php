<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HandlesTenantScoping
{
    /**
     * 是否將資源範圍限制在目前的租戶
     * Super Admin 可以存取所有租戶的資料
     * 利用 Filament 原生的 isScopedToTenant() 機制
     */
    public static function isScopedToTenant(): bool
    {
        $user = auth()->user();

        // Super Admin 不受租戶範圍限制
        if ($user && $user->isSuperAdmin()) {
            return false;
        }

        // 一般使用者維持租戶隔離
        return true;
    }

    /**
     * 簡化的租戶查詢處理，僅處理必要的 eager loading
     * 租戶隔離主要由 Model 層的全域範圍和 Filament 原生機制處理
     * 
     * @param Builder $query 原始查詢建構器
     * @param array $withRelations 需要預先載入的關聯陣列
     * @return Builder 處理過的查詢建構器
     */
    protected static function applyTenantScoping(Builder $query, array $withRelations = []): Builder
    {
        // 僅處理 eager loading，租戶範圍由 Filament 原生和 Model 全域範圍處理
        if (!empty($withRelations)) {
            $query->with($withRelations);
        }

        return $query;
    }
}
