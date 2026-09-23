<?php

namespace App\Filament\Resources\CouponTemplateResource\Schemas;

use App\Models\CouponTemplate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class CouponTemplateInfolist
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('優惠券摘要')
                    ->description('這是什麼優惠券？核心識別資訊')
                    ->schema([
                        TextEntry::make('name')
                            ->label('優惠券名稱')
                            ->columnSpanFull()
                            ->weight(FontWeight::Bold),
                        Grid::make(4)->schema([
                            TextEntry::make('code')
                                ->label('優惠券代碼')
                                ->copyable()
                                ->fontFamily('monospace'),
                            TextEntry::make('type')
                                ->label('優惠券類型')
                                ->badge()
                                ->color(fn(string $state): string => match ($state) {
                                    CouponTemplate::TYPE_FIXED_AMOUNT => 'success',
                                    CouponTemplate::TYPE_PERCENTAGE => 'info',
                                    CouponTemplate::TYPE_FREE_SHIPPING => 'warning',
                                    CouponTemplate::TYPE_GIFT => 'purple',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn(string $state): string => match ($state) {
                                    CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額折抵',
                                    CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                                    CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                                    CouponTemplate::TYPE_GIFT => '贈品',
                                    default => $state,
                                }),
                            TextEntry::make('status')
                                ->label('狀態')
                                ->badge()
                                ->color(fn(string $state): string => match ($state) {
                                    CouponTemplate::STATUS_ACTIVE => 'success',
                                    CouponTemplate::STATUS_INACTIVE => 'danger',
                                    CouponTemplate::STATUS_DRAFT => 'gray',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn(string $state): string => match ($state) {
                                    CouponTemplate::STATUS_ACTIVE => '啟用',
                                    CouponTemplate::STATUS_INACTIVE => '停用',
                                    CouponTemplate::STATUS_DRAFT => '草稿',
                                    default => $state,
                                }),
                            TextEntry::make('tenant.name')
                                ->label('所屬租戶')
                                ->visible(fn() => auth()->user()?->isSuperAdmin()),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('折扣規則')
                    ->description('怎麼折？折扣計算方式與使用條件')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('discount_amount')
                                ->label('折抵金額')
                                ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-')
                                ->visible(fn($record) => $record->type === CouponTemplate::TYPE_FIXED_AMOUNT),
                            TextEntry::make('discount_percentage')
                                ->label('折扣比例')
                                ->formatStateUsing(fn($state) => $state ? $state . '%' : '-')
                                ->visible(fn($record) => $record->type === CouponTemplate::TYPE_PERCENTAGE),
                            TextEntry::make('max_discount_amount')
                                ->label('最高折抵金額')
                                ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-')
                                ->visible(fn($record) => $record->type === CouponTemplate::TYPE_PERCENTAGE),
                            TextEntry::make('minimum_order_amount')
                                ->label('最低消費金額')
                                ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state)),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('時間與發行限制')
                    ->description('什麼時候可以用？可以發多少？有效期限與發行數量規範')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('starts_at')
                                ->label('有效期開始')
                                ->dateTime(),
                            TextEntry::make('expires_at')
                                ->label('有效期結束')
                                ->dateTime(),
                        ]),
                        Grid::make(2)->schema([
                            TextEntry::make('issued_quantity')
                                ->label('已發行 / 發行總量')
                                ->formatStateUsing(fn($record) => "{$record->issued_quantity} / {$record->total_quantity}"),
                            TextEntry::make('per_customer_limit')
                                ->label('每人限領數量'),
                        ]),
                        Grid::make(2)->schema([
                            TextEntry::make('created_at')
                                ->label('系統建立時間')
                                ->dateTime(),
                            TextEntry::make('updated_at')
                                ->label('最後更新時間')
                                ->dateTime(),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }
}
