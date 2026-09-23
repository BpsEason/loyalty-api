<?php

namespace App\Filament\Resources\CustomerResource\Schemas;

use App\Forms\Components\TenantSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('客戶基本資料')
                    ->description('建立新會員的基本聯絡資訊')
                    ->schema([
                        TenantSelect::make()
                            ->label('所屬租戶')
                            ->helperText('請選擇此客戶所屬的會員方案租戶')
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('客戶名稱')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('請輸入客戶姓名')
                                ->helperText('客戶的真實姓名或暱稱'),
                            TextInput::make('email')
                                ->label('電子郵件')
                                ->email()
                                ->required()
                                ->maxLength(255)
                                ->placeholder('customer@example.com')
                                ->helperText('用於接收通知與驗證'),
                        ]),
                        TextInput::make('phone')
                            ->label('聯絡電話')
                            ->maxLength(20)
                            ->placeholder('09xx-xxx-xxx')
                            ->helperText('客戶的手機號碼')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('會員資訊')
                    ->description('管理會員的額外資訊與等級設定')
                    ->schema([
                        KeyValue::make('metadata')
                            ->label('額外會員資訊')
                            ->default([
                                'member_since' => now()->format('Y-m-d'),
                                'tier' => 'bronze',
                            ])
                            ->helperText('可新增額外的會員屬性，如會員加入日期、會員等級等')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
