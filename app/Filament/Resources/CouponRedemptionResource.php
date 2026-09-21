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

    public static function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('核銷基本資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('reference')
                            ->label('核銷參考編號')
                            ->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('tenant.name')
                            ->label('租戶'),
                        \Filament\Infolists\Components\TextEntry::make('customer.name')
                            ->label('會員'),
                    ])->columns(3),
                \Filament\Schemas\Components\Section::make('優惠券資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('userCoupon.reference')
                            ->label('會員優惠券代碼'),
                        \Filament\Infolists\Components\TextEntry::make('userCoupon.couponTemplate.name')
                            ->label('優惠券模板'),
                        \Filament\Infolists\Components\TextEntry::make('order_reference')
                            ->label('訂單編號'),
                    ])->columns(3),
                \Filament\Schemas\Components\Section::make('交易細節')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('discount_amount')
                            ->label('實際折抵金額')
                            ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state)),
                        \Filament\Infolists\Components\TextEntry::make('redeemed_at')
                            ->label('核銷時間')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('creator.name')
                            ->label('操作人員'),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->label('建立時間')
                            ->dateTime(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('核銷參考編號')
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('會員')
                    ->searchable(),
                Tables\Columns\TextColumn::make('userCoupon.couponTemplate.name')
                    ->label('優惠券')
                    ->searchable(),
                Tables\Columns\TextColumn::make('order_reference')
                    ->label('訂單編號')
                    ->searchable(),
                Tables\Columns\TextColumn::make('discount_amount')
                    ->label('實際折抵金額')
                    ->sortable()
                    ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state)),
                Tables\Columns\TextColumn::make('redeemed_at')
                    ->label('核銷時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('操作人員')
                    ->searchable(),
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
                Tables\Filters\SelectFilter::make('userCoupon.couponTemplate_id')
                    ->label('優惠券模板')
                    ->relationship('userCoupon.couponTemplate', 'name'),
                Tables\Filters\Filter::make('order_reference')
                    ->label('訂單編號')
                    ->query(function (Builder $query, $data) {
                        if (!empty($data['value'])) {
                            $query->where('order_reference', 'like', "%{$data['value']}%");
                        }
                    }),
                Tables\Filters\Filter::make('redeemed_at')
                    ->label('核銷時間')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('開始日期'),
                        \Filament\Forms\Components\DatePicker::make('until')
                            ->label('結束日期'),
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
