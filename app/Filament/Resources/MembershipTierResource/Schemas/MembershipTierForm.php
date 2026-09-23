<?php

namespace App\Filament\Resources\MembershipTierResource\Schemas;

use App\Forms\Components\TenantSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class MembershipTierForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本設定')
                    ->description('會員等級的基本資訊')
                    ->schema([
                        TenantSelect::make()
                            ->label('所屬租戶')
                            ->helperText('請選擇此會員等級所屬的租戶')
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('等級名稱')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('例如：白金會員'),
                            TextInput::make('slug')
                                ->label('標識符')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('platinum'),
                        ]),
                        Grid::make(2)->schema([
                            Select::make('threshold_type')
                                ->label('門檻類型')
                                ->options([
                                    'spend' => '累積消費',
                                    'points' => '累積點數',
                                ])
                                ->required(),
                            TextInput::make('sort_order')
                                ->label('排序順序')
                                ->numeric()
                                ->default(0)
                                ->required(),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('upgrade_threshold')
                                ->label('升級門檻')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->required(),
                            Toggle::make('status')
                                ->label('啟用狀態')
                                ->default(true)
                                ->required(),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('權益設定')
                    ->description('此會員等級可享有的權益')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('points_multiplier')
                                ->label('點數倍增係數')
                                ->numeric()
                                ->step(0.01)
                                ->default(1.00)
                                ->required(),
                            TextInput::make('discount_rate')
                                ->label('折扣率')
                                ->numeric()
                                ->step(0.0001)
                                ->default(0.0000)
                                ->required(),
                        ]),
                        Toggle::make('free_shipping')
                            ->label('免運費')
                            ->default(false),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
