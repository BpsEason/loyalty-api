<?php

namespace App\Filament\Resources\UserCouponResource\Tables;

use App\Models\UserCoupon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\ViewAction;

class UserCouponTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('會員')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn(UserCoupon $record) => $record->couponTemplate->name ?? ''),
                TextColumn::make('reference')
                    ->label('券代碼')
                    ->searchable()
                    ->copyable()
                    ->weight('medium'),
                BadgeColumn::make('status')
                    ->label('狀態')
                    ->weight('bold')
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
                TextColumn::make('expired_at')
                    ->label('有效期限')
                    ->dateTime('m-d H:i')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('issued_at')
                    ->label('發放時間')
                    ->dateTime('m-d H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('used_at')
                    ->label('使用時間')
                    ->dateTime('m-d H:i')
                    ->placeholder('-')
                    ->toggleable(),
                BadgeColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->color('info')
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tenant_id')
                    ->label('租戶')
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
                SelectFilter::make('customer_id')
                    ->label('會員')
                    ->relationship('customer', 'name'),
                SelectFilter::make('coupon_template_id')
                    ->label('優惠券模板')
                    ->relationship('couponTemplate', 'name'),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        UserCoupon::STATUS_AVAILABLE => '可使用',
                        UserCoupon::STATUS_USED => '已使用',
                        UserCoupon::STATUS_EXPIRED => '已過期',
                        UserCoupon::STATUS_CANCELLED => '已作廢',
                    ]),
                Filter::make('is_valid')
                    ->label('有效狀態')
                    ->query(fn(Builder $query) => $query->where('status', UserCoupon::STATUS_AVAILABLE)->where('expired_at', '>', now())),
                Filter::make('is_expired')
                    ->label('已過期')
                    ->query(fn(Builder $query) => $query->where(function ($q) {
                        $q->where('status', UserCoupon::STATUS_EXPIRED)
                            ->orWhere('expired_at', '<=', now());
                    })),
            ])
            ->actions([
                ViewAction::make()
                    ->tooltip('查看優惠券詳情')
                    ->icon('heroicon-o-eye'),
            ])
            ->bulkActions([]);
    }
}
