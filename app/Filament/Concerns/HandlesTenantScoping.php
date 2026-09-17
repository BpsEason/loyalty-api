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
     * 套用租戶作用域與預先載入
     */
    public static function applyTenantScoping(Builder $query, array $eagerLoadWith = []): Builder
    {
        $user = auth()->user();

        if (!$user) {
            return $query;
        }

        // 若為 Super Admin，僅解除租戶相關的全域範疇限制，保留其他必要範疇
        if ($user->hasRole('super_admin')) {
            $withScopeCleared = [];
            $panel = filament()->getCurrentOrDefaultPanel();
            $filamentTenancyScopeName = $panel?->hasTenancy() ? $panel->getTenancyScopeName() : null;

            foreach ($eagerLoadWith as $key => $value) {
                // 處理數字索引的純關聯名稱
                if (is_int($key)) {
                    $relationString = $value;
                    // 拆分巢狀關聯，只對第一層關聯移除 tenant scope
                    $relations = explode('.', $relationString);
                    $firstRelation = $relations[0];

                    // 檢查是否已經處理過這個第一層關聯
                    if (!isset($withScopeCleared[$firstRelation])) {
                        $withScopeCleared[$firstRelation] = function ($q) use ($filamentTenancyScopeName) {
                            // 只移除租戶相關的全域範疇，保留其他範疇（如SoftDeletingScope）
                            if ($filamentTenancyScopeName) {
                                $q->withoutGlobalScope($filamentTenancyScopeName);
                            }
                            $q->withoutGlobalScope('tenant');
                        };
                    }

                    // 對於巢狀關聯的剩餘部分，保持原樣傳遞給Laravel處理
                    // 只需要添加第一層關聯，Laravel會自動處理巢狀關聯的載入
                    // 不需要額外添加巢狀字串，避免Laravel試圖將null當作Closure呼叫
                }
                // 處理字串索引且值為Closure或null的情況
                else {
                    $relationName = $key;
                    $relations = explode('.', $relationName);
                    $firstRelation = $relations[0];

                    // 處理第一層關聯的Closure
                    if (count($relations) === 1) {
                        if (is_callable($value)) {
                            $originalClosure = $value;
                            $withScopeCleared[$firstRelation] = function ($q) use ($originalClosure, $filamentTenancyScopeName) {
                                // 先執行原本的Closure邏輯
                                $originalClosure($q);
                                // 再追加移除租戶範疇的邏輯
                                if ($filamentTenancyScopeName) {
                                    $q->withoutGlobalScope($filamentTenancyScopeName);
                                }
                                $q->withoutGlobalScope('tenant');
                            };
                        } elseif (!isset($withScopeCleared[$firstRelation])) {
                            // 如果還沒有處理過這個關聯，添加基礎的scope移除
                            $withScopeCleared[$firstRelation] = function ($q) use ($filamentTenancyScopeName) {
                                if ($filamentTenancyScopeName) {
                                    $q->withoutGlobalScope($filamentTenancyScopeName);
                                }
                                $q->withoutGlobalScope('tenant');
                            };
                        }
                    } else {
                        // 巢狀關聯的子關聯，保持原樣
                        $withScopeCleared[$relationName] = $value;
                    }
                }
            }

            return $query->with($withScopeCleared);
        }

        // 一般情況直接採用預設 with 載入
        return $query->with($eagerLoadWith);
    }
}
