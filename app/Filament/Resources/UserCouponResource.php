<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\UserCouponResource\Pages;
use App\Models\UserCoupon;
use App\Models\CouponTemplate;
use App\Models\Customer;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class UserCouponResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = UserCoupon::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = '會員優惠券';
    protected static ?string $pluralModelLabel = '會員優惠券';
    protected static ?string $navigationLabel = '會員優惠券';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query->with([
                'tenant' => fn($q) => $q->withoutGlobalScopes(),
                'customer' => fn($q) => $q->withoutGlobalScopes(),
                'couponTemplate' => fn($q) => $q->withoutGlobalScopes(),
                'redemption' => fn($q) => $q->withoutGlobalScopes(),
            ]);
        }

        return $query->with([
            'tenant',
            'customer',
            'couponTemplate',
            'redemption',
        ]);
    }

    public static function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('基本資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('customer.name')
                            ->label('會員'),
                        \Filament\Infolists\Components\TextEntry::make('couponTemplate.name')
                            ->label('優惠券'),
                        \Filament\Infolists\Components\TextEntry::make('couponTemplate.type')
                            ->label('優惠券類型')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額',
                                CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                                CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                                CouponTemplate::TYPE_GIFT => '贈品',
                                default => $state,
                            }),
                        \Filament\Infolists\Components\TextEntry::make('reference')
                            ->label('券代碼'),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('目前狀態')
                            ->badge()
                            ->formatStateUsing(fn(string $state): string => match ($state) {
                                UserCoupon::STATUS_AVAILABLE => '可使用',
                                UserCoupon::STATUS_USED => '已使用',
                                UserCoupon::STATUS_EXPIRED => '已過期',
                                UserCoupon::STATUS_CANCELLED => '已作廢',
                                default => $state,
                            }),
                    ])->columns(3),
                \Filament\Schemas\Components\Section::make('時間資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('issued_at')
                            ->label('發放時間')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('expired_at')
                            ->label('有效期限')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('used_at')
                            ->label('使用時間')
                            ->dateTime()
                            ->placeholder('尚未使用'),
                    ])->columns(3),
                \Filament\Schemas\Components\Section::make('核銷資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('redemption.reference')
                            ->label('核銷參考編號')
                            ->placeholder('尚未核銷'),
                        \Filament\Infolists\Components\TextEntry::make('redemption.order_reference')
                            ->label('訂單編號')
                            ->placeholder('-'),
                        \Filament\Infolists\Components\TextEntry::make('redemption.discount_amount')
                            ->label('實際折抵金額')
                            ->placeholder('-')
                            ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-'),
                        \Filament\Infolists\Components\TextEntry::make('redemption.redeemed_at')
                            ->label('核銷時間')
                            ->dateTime()
                            ->placeholder('尚未核銷'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('會員')
                    ->searchable(),
                Tables\Columns\TextColumn::make('couponTemplate.name')
                    ->label('優惠券')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reference')
                    ->label('券代碼')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
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
                Tables\Columns\TextColumn::make('issued_at')
                    ->label('發放時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('used_at')
                    ->label('使用時間')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('expired_at')
                    ->label('過期時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')
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
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('會員')
                    ->relationship('customer', 'name'),
                Tables\Filters\SelectFilter::make('coupon_template_id')
                    ->label('優惠券模板')
                    ->relationship('couponTemplate', 'name'),
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        UserCoupon::STATUS_AVAILABLE => '可使用',
                        UserCoupon::STATUS_USED => '已使用',
                        UserCoupon::STATUS_EXPIRED => '已過期',
                        UserCoupon::STATUS_CANCELLED => '已作廢',
                    ]),
                Tables\Filters\Filter::make('is_valid')
                    ->label('有效狀態')
                    ->query(fn(Builder $query) => $query->where('status', UserCoupon::STATUS_AVAILABLE)->where('expired_at', '>', now())),
                Tables\Filters\Filter::make('is_expired')
                    ->label('已過期')
                    ->query(fn(Builder $query) => $query->where(function ($q) {
                        $q->where('status', UserCoupon::STATUS_EXPIRED)
                            ->orWhere('expired_at', '<=', now());
                    })),
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
            'index' => Pages\ListUserCoupons::route('/'),
            'view' => Pages\ViewUserCoupon::route('/{record}'),
        ];
    }
}
