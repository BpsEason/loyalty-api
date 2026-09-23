<?php

namespace App\Filament\Resources\CouponTemplateResource\Schemas;

use App\Models\CouponTemplate;
use App\Forms\Components\TenantSelect;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;

class CouponTemplateForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                TenantSelect::make(),

                Section::make('優惠券基本資訊')
                    ->description('這張優惠券是什麼？設定核心識別資訊')
                    ->schema([
                        TextInput::make('name')
                            ->label('優惠券名稱')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->placeholder('請輸入優惠券名稱，例如：新會員專屬100元折抵券'),
                        Grid::make(3)->schema([
                            TextInput::make('code')
                                ->label('優惠券代碼')
                                ->required()
                                ->maxLength(100)
                                ->unique(ignoreRecord: true)
                                ->placeholder('系統唯一識別代碼'),
                            Select::make('type')
                                ->label('優惠券類型')
                                ->options([
                                    CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額折抵',
                                    CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                                    CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                                    CouponTemplate::TYPE_GIFT => '贈品',
                                ])
                                ->required()
                                ->reactive(),
                            Select::make('status')
                                ->label('狀態')
                                ->options([
                                    CouponTemplate::STATUS_ACTIVE => '啟用',
                                    CouponTemplate::STATUS_INACTIVE => '停用',
                                    CouponTemplate::STATUS_DRAFT => '草稿',
                                ])
                                ->required()
                                ->default(CouponTemplate::STATUS_DRAFT),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('折扣設定')
                    ->description('怎麼折？依優惠券類型自動顯示相關折扣規則')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('discount_amount')
                                ->label('折抵金額')
                                ->numeric()
                                ->minValue(1)
                                ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_FIXED_AMOUNT)
                                ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_FIXED_AMOUNT)
                                ->prefix('NT$'),
                            TextInput::make('discount_percentage')
                                ->label('折扣比例')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->suffix('%'),
                            TextInput::make('max_discount_amount')
                                ->label('最高折抵金額')
                                ->numeric()
                                ->minValue(1)
                                ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->prefix('NT$'),
                            TextInput::make('minimum_order_amount')
                                ->label('最低消費金額')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('NT$')
                                ->helperText('設定使用此優惠券需要達到的最低訂單金額，0表示無最低消費限制'),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('時間與數量限制')
                    ->description('什麼時候有效？可以發多少？設定有效期限與發行限制')
                    ->schema([
                        Grid::make(2)->schema([
                            DateTimePicker::make('starts_at')
                                ->label('有效期開始')
                                ->required()
                                ->helperText('優惠券開始生效的時間')
                                ->seconds(false),
                            DateTimePicker::make('expires_at')
                                ->label('有效期結束')
                                ->required()
                                ->after('starts_at')
                                ->helperText('優惠券失效的時間，必須晚於開始時間')
                                ->seconds(false),
                        ]),
                        Grid::make(2)->schema([
                            TextInput::make('total_quantity')
                                ->label('發行總量')
                                ->numeric()
                                ->minValue(fn($record) => $record?->issued_quantity ?? 1)
                                ->required()
                                ->helperText(fn($record) => $record ? "不可低於已發行數量 {$record->issued_quantity}" : '此優惠券總共可以發行多少張'),
                            TextInput::make('per_customer_limit')
                                ->label('每人限領數量')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->helperText('每位會員最多可以領取幾張此優惠券'),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }
}
