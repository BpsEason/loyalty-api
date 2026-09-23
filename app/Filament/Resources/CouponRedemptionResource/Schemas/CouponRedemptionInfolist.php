<?php

namespace App\Filament\Resources\CouponRedemptionResource\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Schemas\Schema;

class CouponRedemptionInfolist
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('優惠券核銷')
                    ->description('優惠券核銷的核心資訊摘要')
                    ->schema([
                        TextEntry::make('reference')
                            ->label('核銷參考編號')
                            ->copyable()
                            ->size('lg')
                            ->weight(FontWeight::Bold)
                            ->icon('heroicon-m-clipboard-document')
                            ->iconColor('primary')
                            ->columnSpanFull(),
                        Grid::make(3)->schema([
                            TextEntry::make('discount_amount')
                                ->label('實際折抵金額')
                                ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state))
                                ->size('xl')
                                ->weight(FontWeight::Bold)
                                ->color('success')
                                ->icon('heroicon-m-currency-dollar')
                                ->iconColor('success'),
                            TextEntry::make('customer.name')
                                ->label('會員')
                                ->size('lg')
                                ->weight(FontWeight::SemiBold)
                                ->icon('heroicon-m-user')
                                ->iconColor('gray'),
                            TextEntry::make('userCoupon.couponTemplate.name')
                                ->label('優惠券')
                                ->size('lg')
                                ->weight(FontWeight::SemiBold)
                                ->icon('heroicon-m-ticket')
                                ->iconColor('warning'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('使用資訊')
                    ->description('優惠券使用的詳細訂單資訊')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('userCoupon.reference')
                                ->label('會員優惠券代碼')
                                ->copyable()
                                ->icon('heroicon-o-hashtag')
                                ->helperText('可複製此優惠券代碼進行查詢'),
                            TextEntry::make('order_reference')
                                ->label('訂單編號')
                                ->copyable()
                                ->icon('heroicon-o-receipt-percent')
                                ->helperText('可複製此訂單編號進行核對'),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('核銷紀錄')
                    ->description('此核銷記錄的系統追蹤資訊')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('created_at')
                                ->label('建立紀錄')
                                ->dateTime()
                                ->icon('heroicon-m-calendar')
                                ->iconColor('gray')
                                ->helperText('系統記錄建立的時間'),
                            TextEntry::make('redeemed_at')
                                ->label('執行核銷')
                                ->dateTime()
                                ->icon('heroicon-m-check-circle')
                                ->iconColor('success')
                                ->helperText('優惠券實際核銷的時間'),
                            TextEntry::make('creator.name')
                                ->label('操作人員')
                                ->icon('heroicon-m-user-circle')
                                ->iconColor('gray')
                                ->helperText('執行此核銷的系統使用者'),
                            TextEntry::make('tenant.name')
                                ->label('租戶')
                                ->icon('heroicon-o-building-office')
                                ->iconColor('gray')
                                ->visible(fn() => auth()->user()?->isSuperAdmin())
                                ->helperText('此核銷所屬的租戶'),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
