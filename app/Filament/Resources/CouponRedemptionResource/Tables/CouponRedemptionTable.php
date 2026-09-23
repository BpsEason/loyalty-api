<?php

namespace App\Filament\Resources\CouponRedemptionResource\Tables;

use App\Models\CouponRedemption;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\ViewAction;

class CouponRedemptionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('核銷參考編號')
                    ->searchable()
                    ->copyable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
                    ->icon('heroicon-m-clipboard-document')
                    ->iconColor('primary')
                    ->wrap(),
                TextColumn::make('customer.name')
                    ->label('會員')
                    ->searchable()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium)
                    ->icon('heroicon-m-user')
                    ->iconColor('gray')
                    ->description(fn(CouponRedemption $record): string => $record->customer?->email ?? '')
                    ->wrap(),
                TextColumn::make('userCoupon.couponTemplate.name')
                    ->label('優惠券')
                    ->searchable()
                    ->icon('heroicon-m-ticket')
                    ->iconColor('warning')
                    ->wrap(),
                TextColumn::make('discount_amount')
                    ->label('折抵金額')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state))
                    ->weight(\Filament\Support\Enums\FontWeight::Bold)
                    ->color('success')
                    ->alignRight()
                    ->icon('heroicon-m-currency-dollar')
                    ->iconColor('success'),
                TextColumn::make('redeemed_at')
                    ->label('核銷時間')
                    ->dateTime()
                    ->sortable()
                    ->icon('heroicon-m-check-circle')
                    ->iconColor('success')
                    ->color('gray')
                    ->wrap(),
                TextColumn::make('order_reference')
                    ->label('訂單編號')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-m-receipt-percent')
                    ->iconColor('gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('creator.name')
                    ->label('操作人員')
                    ->searchable()
                    ->icon('heroicon-m-user-circle')
                    ->iconColor('gray')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()?->isSuperAdmin())
                    ->badge()
                    ->color('info')
                    ->icon('heroicon-m-building-office')
                    ->iconColor('info'),
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
                SelectFilter::make('tenant_id')
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
                    ->visible(fn() => auth()->user()?->isSuperAdmin()),
                SelectFilter::make('customer_id')
                    ->label('會員')
                    ->placeholder('選擇會員')
                    ->relationship('customer', 'name'),
                SelectFilter::make('userCoupon.couponTemplate_id')
                    ->label('優惠券模板')
                    ->placeholder('選擇優惠券模板')
                    ->relationship('userCoupon.couponTemplate', 'name'),
                Filter::make('order_reference')
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
                Filter::make('redeemed_at')
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
                            ->when($data['from'] ?? null, fn($q) => $q->whereDate('redeemed_at', '>=', $data['from']))
                            ->when($data['until'] ?? null, fn($q) => $q->whereDate('redeemed_at', '<=', $data['until']));
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
