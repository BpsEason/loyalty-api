<?php

namespace App\Filament\Resources\UserCouponResource\Schemas;

use App\Models\UserCoupon;
use App\Models\CouponTemplate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

class UserCouponInfolist
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('優惠券資訊')
                    ->description('此會員優惠券的核心資訊')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('customer.name')
                                    ->label('會員')
                                    ->weight('bold')
                                    ->size('lg'),
                                TextEntry::make('couponTemplate.name')
                                    ->label('優惠券')
                                    ->weight('bold')
                                    ->size('lg'),
                                TextEntry::make('reference')
                                    ->label('券代碼')
                                    ->copyable()
                                    ->weight('medium'),
                                TextEntry::make('status')
                                    ->label('目前狀態')
                                    ->badge()
                                    ->size('lg')
                                    ->color(fn(string $state): string => match ($state) {
                                        UserCoupon::STATUS_AVAILABLE => 'success',
                                        UserCoupon::STATUS_USED => 'info',
                                        UserCoupon::STATUS_EXPIRED => 'warning',
                                        UserCoupon::STATUS_CANCELLED => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn(string $state): string => match ($state) {
                                        UserCoupon::STATUS_AVAILABLE => '可使用',
                                        UserCoupon::STATUS_USED => '已使用',
                                        UserCoupon::STATUS_EXPIRED => '已過期',
                                        UserCoupon::STATUS_CANCELLED => '已作廢',
                                        default => $state,
                                    }),
                                TextEntry::make('couponTemplate.type')
                                    ->label('優惠券類型')
                                    ->badge()
                                    ->color('gray')
                                    ->formatStateUsing(fn(string $state): string => match ($state) {
                                        CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額',
                                        CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                                        CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                                        CouponTemplate::TYPE_GIFT => '贈品',
                                        default => $state,
                                    }),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('使用期限')
                    ->description('優惠券的發放與有效時間')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('issued_at')
                                    ->label('發放時間')
                                    ->dateTime(),
                                TextEntry::make('expired_at')
                                    ->label('有效期限')
                                    ->dateTime(),
                                TextEntry::make('used_at')
                                    ->label('使用時間')
                                    ->dateTime()
                                    ->placeholder('尚未使用'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('核銷資訊')
                    ->description('此優惠券的核銷詳細資料')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('redemption.reference')
                                    ->label('核銷參考編號')
                                    ->placeholder('尚未核銷'),
                                TextEntry::make('redemption.order_reference')
                                    ->label('訂單編號')
                                    ->placeholder('-'),
                                TextEntry::make('redemption.discount_amount')
                                    ->label('實際折抵金額')
                                    ->placeholder('-')
                                    ->weight('bold')
                                    ->size('lg')
                                    ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-'),
                                TextEntry::make('redemption.redeemed_at')
                                    ->label('核銷時間')
                                    ->dateTime()
                                    ->placeholder('尚未核銷'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
