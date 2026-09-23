<?php

namespace App\Filament\Resources\TenantResource\Schemas;

use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('租戶基本資訊')
                    ->description('管理租戶名稱與主要識別資訊')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('租戶名稱')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('domain')
                                    ->label('網域')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('租戶狀態')
                    ->description('管理租戶目前是否可以正常使用')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('是否啟用')
                            ->required()
                            ->default(true)
                            ->helperText('停用後該租戶將無法正常使用平台功能'),
                    ])
                    ->columnSpanFull(),

                Section::make('系統設定')
                    ->description('設定此租戶的基本系統參數')
                    ->schema([
                        Forms\Components\KeyValue::make('settings')
                            ->label('系統參數')
                            ->default([
                                'currency' => 'TWD',
                                'timezone' => 'Asia/Taipei',
                            ])
                            ->helperText('管理此租戶的貨幣、時區等系統參數')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
