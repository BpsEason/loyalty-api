<?php

namespace App\Filament\Resources\PointAccountResource\Schemas;

use App\Forms\Components\TenantSelect;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class PointAccountForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('點數帳戶')
                    ->description('設定此點數帳戶所屬的租戶與客戶')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TenantSelect::make()
                                    ->reactive()
                                    ->columnSpan(1),
                                Forms\Components\Select::make('customer_id')
                                    ->label('客戶')
                                    ->relationship('customer', 'name', function ($query, $get) {
                                        $user = auth()->user();
                                        $panel = filament()->getCurrentOrDefaultPanel();
                                        $tenantId = $get('tenant_id');

                                        if ($user && $user->isSuperAdmin()) {
                                            // Super Admin 可以看到所有客戶（配合選取租戶過濾）
                                            if ($panel?->hasTenancy()) {
                                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                                            }
                                            if ($tenantId) {
                                                $query->where('tenant_id', $tenantId);
                                            }
                                        }
                                        // Tenant Admin 由Model全域範圍自動處理，無需手動過濾

                                        return $query;
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('點數餘額')
                    ->description('查看與管理此帳戶目前的點數狀態')
                    ->schema([
                        Forms\Components\TextInput::make('balance')
                            ->label('目前餘額')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->suffix('點')
                            ->helperText('此客戶當前可用的點數餘額')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('點數累積')
                    ->description('查看此帳戶的點數累積統計')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('total_earned')
                                    ->label('累積獲得')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('點')
                                    ->helperText('此帳戶累積獲得的總點數')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('total_redeemed')
                                    ->label('累積兌換')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('點')
                                    ->helperText('此帳戶累積兌換使用的總點數')
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
