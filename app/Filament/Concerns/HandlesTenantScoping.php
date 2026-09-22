<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HandlesTenantScoping
{
    /**
     * 是否將資源範圍限制在目前的租戶
     * Filament 5.8 生命週期說明：此靜態方法在面板註冊階段呼叫，早於所有認證中間件
     * 因此auth()->user()永遠為null，必須直接返回false避免第一次請求誤開租戶隔離
     * 後續Model層的BelongsToTenant trait會自行處理所有使用者的租戶隔離邏輯
     */
    public static function isScopedToTenant(): bool
    {
        return false;
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
        if ($user->isSuperAdmin()) {
            $withScopeCleared = [];

            foreach ($eagerLoadWith as $key => $value) {
                // 處理數字索引的純關聯名稱
                if (is_int($key)) {
                    $relationString = $value;
                    // 拆分巢狀關聯，只對第一層關聯移除 tenant scope
                    $relations = explode('.', $relationString);
                    $firstRelation = $relations[0];

                    // 如果只有一層關聯，直接處理
                    if (count($relations) === 1) {
                        // 每個第一層關聯都需要獨立處理，移除全域範疇以確保Super Admin可以讀取所有租戶的關聯數據
                        $withScopeCleared[$firstRelation] = function ($q) {
                            $q->withoutGlobalScopes();
                        };
                    } else {
                        // 巢狀關聯：第一層使用closure移除global scope，子關聯也同樣移除全域範疇
                        $nestedRelations = array_slice($relations, 1);
                        $nestedRelationString = implode('.', $nestedRelations);

                        // 第一層關聯需要移除全域範疇，子關聯也要同步移除
                        $withScopeCleared[$firstRelation] = function ($q) use ($nestedRelationString) {
                            $q->withoutGlobalScopes()->with([
                                $nestedRelationString => function ($subQuery) {
                                    $subQuery->withoutGlobalScopes();
                                }
                            ]);
                        };
                    }
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
                            $withScopeCleared[$firstRelation] = function ($q) use ($originalClosure) {
                                // 先執行原本的Closure邏輯
                                $originalClosure($q);
                                // 再追加移除所有全域範疇的邏輯，確保Super Admin可以讀取所有租戶的關聯數據
                                $q->withoutGlobalScopes();
                            };
                        } else {
                            // 每個第一層關聯都需要獨立處理，移除全域範疇以確保Super Admin可以讀取所有租戶的關聯數據
                            $withScopeCleared[$firstRelation] = function ($q) {
                                $q->withoutGlobalScopes();
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
