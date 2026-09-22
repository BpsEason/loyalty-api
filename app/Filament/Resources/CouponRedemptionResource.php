<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\CouponRedemptionResource\Pages;
use App\Models\CouponRedemption;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class CouponRedemptionResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = CouponRedemption::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 6;
    protected static ?string $modelLabel = '優惠券核銷紀錄';
    protected static ?string $pluralModelLabel = '優惠券核銷紀錄';
    protected static ?string $navigationLabel = '優惠券核銷紀錄';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query->with([
                'tenant' => fn($q) => $q->withoutGlobalScopes(),
                'customer' => fn($q) => $q->withoutGlobalScopes(),
                'userCoupon.couponTemplate' => fn($q) => $q->withoutGlobalScopes(),
                'creator' => fn($q) => $q->withoutGlobalScopes(),
            ]);
        }

        return $query->with([
            'tenant',
            'customer',
            'userCoupon.couponTemplate',
            'creator',
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Hero區：核銷摘要卡片 - 最醒目的首屏資訊（Primary Information）
                Section::make('優惠券核銷')
                    ->description('優惠券核銷的核心資訊摘要')
                    ->schema([
                        // 頂部：核銷編號
                        Infolists\Components\TextEntry::make('reference')
                            ->label('核銷參考編號')
                            ->copyable()
                            ->size('lg')
                            ->weight(FontWeight::Bold)
                            ->icon('heroicon-m-clipboard-document')
                            ->iconColor('primary')
                            ->columnSpanFull(),
                        // Grid 佈局：折抵金額、會員、優惠券
                        Grid::make(3)->schema([
                            // 主要欄位：折抵金額（整個頁面最醒目 - 最高視覺權重）
                            Infolists\Components\TextEntry::make('discount_amount')
                                ->label('實際折抵金額')
                                ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state))
                                ->size('xl')
                                ->weight(FontWeight::Bold)
                                ->color('success')
                                ->icon('heroicon-m-currency-dollar')
                                ->iconColor('success'),
                            // 會員資訊
                            Infolists\Components\TextEntry::make('customer.name')
                                ->label('會員')
                                ->size('lg')
                                ->weight(FontWeight::SemiBold)
                                ->icon('heroicon-m-user')
                                ->iconColor('gray'),
                            // 優惠券名稱
                            Infolists\Components\TextEntry::make('userCoupon.couponTemplate.name')
                                ->label('優惠券')
                                ->size('lg')
                                ->weight(FontWeight::SemiBold)
                                ->icon('heroicon-m-ticket')
                                ->iconColor('warning'),
                        ]),
                    ])
                    ->columnSpanFull(),

                // 第二區：使用資訊 - 完整的優惠券與訂單細節（Secondary Information）
                Section::make('使用資訊')
                    ->description('優惠券使用的詳細訂單資訊')
                    ->schema([
                        Grid::make(2)->schema([
                            Infolists\Components\TextEntry::make('userCoupon.reference')
                                ->label('會員優惠券代碼')
                                ->copyable()
                                ->icon('heroicon-o-hashtag')
                                ->helperText('可複製此優惠券代碼進行查詢'),
                            Infolists\Components\TextEntry::make('order_reference')
                                ->label('訂單編號')
                                ->copyable()
                                ->icon('heroicon-o-receipt-percent')
                                ->helperText('可複製此訂單編號進行核對'),
                        ]),
                    ])
                    ->columnSpanFull(),

                // 第三區：核銷紀錄與系統元數據（Metadata）
                Section::make('核銷紀錄')
                    ->description('此核銷記錄的系統追蹤資訊')
                    ->schema([
                        Grid::make(2)->schema([
                            // 時間相關欄位
                            Infolists\Components\TextEntry::make('created_at')
                                ->label('建立紀錄')
                                ->dateTime()
                                ->icon('heroicon-m-calendar')
                                ->iconColor('gray')
                                ->helperText('系統記錄建立的時間'),
                            Infolists\Components\TextEntry::make('redeemed_at')
                                ->label('執行核銷')
                                ->dateTime()
                                ->icon('heroicon-m-check-circle')
                                ->iconColor('success')
                                ->helperText('優惠券實際核銷的時間'),
                            // 操作人員
                            Infolists\Components\TextEntry::make('creator.name')
                                ->label('操作人員')
                                ->icon('heroicon-m-user-circle')
                                ->iconColor('gray')
                                ->helperText('執行此核銷的系統使用者'),
                            // 租戶資訊（僅Super Admin可見）
                            Infolists\Components\TextEntry::make('tenant.name')
                                ->label('租戶')
                                ->icon('heroicon-o-building-office')
                                ->iconColor('gray')
                                ->visible(fn() => auth()->user()->hasRole('super_admin'))
                                ->helperText('此核銷所屬的租戶'),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 核銷參考編號 - 第一個主要識別欄位
                TextColumn::make('reference')
                    ->label('核銷參考編號')
                    ->searchable()
                    ->copyable()
                    ->weight(FontWeight::SemiBold)
                    ->icon('heroicon-m-clipboard-document')
                    ->iconColor('primary')
                    ->wrap(),
                // 會員欄位
                TextColumn::make('customer.name')
                    ->label('會員')
                    ->searchable()
                    ->weight(FontWeight::Medium)
                    ->icon('heroicon-m-user')
                    ->iconColor('gray')
                    ->description(fn(CouponRedemption $record): string => $record->customer?->email ?? '')
                    ->wrap(),
                // 優惠券欄位
                TextColumn::make('userCoupon.couponTemplate.name')
                    ->label('優惠券')
                    ->searchable()
                    ->icon('heroicon-m-ticket')
                    ->iconColor('warning')
                    ->wrap(),
                // 折抵金額 - 重點數字欄位
                TextColumn::make('discount_amount')
                    ->label('折抵金額')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state))
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->alignRight()
                    ->icon('heroicon-m-currency-dollar')
                    ->iconColor('success'),
                // 核銷時間
                TextColumn::make('redeemed_at')
                    ->label('核銷時間')
                    ->dateTime()
                    ->sortable()
                    ->icon('heroicon-m-check-circle')
                    ->iconColor('success')
                    ->color('gray')
                    ->wrap(),
                // 訂單編號
                TextColumn::make('order_reference')
                    ->label('訂單編號')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-receipt-percent')
                    ->iconColor('gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                // 操作人員
                TextColumn::make('creator.name')
                    ->label('操作人員')
                    ->searchable()
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                // 租戶 - 僅Super Admin可見，改為Badge顯示
                TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin'))
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-m-building-office')
                    ->iconColor('info'),
                // 建立時間 - 預設隱藏
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->icon('heroicon-m-calendar')
                    ->iconColor('gray')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // 租戶過濾器 - 僅Super Admin可見
                Tables\Filters\SelectFilter::make('tenant_id')
                    ->label('租戶')
                    ->placeholder('選擇租戶')
                    ->relationship('tenant', 'name', function ($query) {
                        $user = auth()->user();
                        if ($user && is_null($user->tenant_id)) {
                            $panel = filament()->getCurrentOrDefaultPanel();
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                        } else {
                            $query->where('id', $user->tenant_id);
                        }
                        return $query;
                    })
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                // 會員過濾器
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('會員')
                    ->placeholder('選擇會員')
                    ->relationship('customer', 'name'),
                // 優惠券模板過濾器
                Tables\Filters\SelectFilter::make('userCoupon.couponTemplate_id')
                    ->label('優惠券模板')
                    ->placeholder('選擇優惠券模板')
                    ->relationship('userCoupon.couponTemplate', 'name'),
                // 訂單編號文字搜尋
                Tables\Filters\Filter::make('order_reference')
                    ->label('訂單編號')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('value')
                            ->label('輸入訂單編號')
                            ->placeholder('請輸入訂單編號關鍵字'),
                    ])
                    ->query(function (Builder $query, $data) {
                        if (!empty($data['value'])) {
                            $query->where('order_reference', 'like', "%{$data['value']}%");
                        }
                    }),
                // 核銷時間區間過濾
                Tables\Filters\Filter::make('redeemed_at')
                    ->label('核銷日期')
                    ->form([
                        \Filament\Schemas\Components\Grid::make(2)->schema([
                            \Filament\Forms\Components\DatePicker::make('from')
                                ->label('開始日期')
                                ->placeholder('選擇開始日期'),
                            \Filament\Forms\Components\DatePicker::make('until')
                                ->label('結束日期')
                                ->placeholder('選擇結束日期'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'], fn($q) => $q->whereDate('redeemed_at', '>=', $data['from']))
                            ->when($data['until'], fn($q) => $q->whereDate('redeemed_at', '<=', $data['until']));
                    }),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCouponRedemptions::route('/'),
            'view' => Pages\ViewCouponRedemption::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
