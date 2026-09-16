<?php

namespace App\Forms\Components;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class TenantSelect
{
    /**
     * 建立一個共用的Tenant選擇欄位
     * 集中處理所有Resource的租戶選擇邏輯，避免重複程式碼
     */
    public static function make(string $name = 'tenant_id'): Select
    {
        return Select::make($name)
            ->label('租戶')
            ->relationship('tenant', 'name', function (Builder $query) {
                $user = auth()->user();
                $panel = filament()->getCurrentOrDefaultPanel();

                if ($user && is_null($user->tenant_id)) {
                    // Super Admin 可以看到所有租戶，需要移除Filament的租戶全域範圍
                    if ($panel?->hasTenancy()) {
                        $query->withoutGlobalScope($panel->getTenancyScopeName());
                    }
                } else {
                    // Tenant Admin 只能看到自己的租戶
                    $query->where('id', $user->tenant_id);
                }

                return $query;
            })
            ->required(fn($context) => $context !== 'create' || !auth()->user()->hasRole('tenant_admin'))
            ->default(fn() => auth()->user()->tenant_id)
            ->disabled(fn() => auth()->user()->hasRole('tenant_admin'));
    }
}
