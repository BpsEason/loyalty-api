<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\CouponRedemptionResource\Pages;
use App\Filament\Resources\CouponRedemptionResource\Schemas\CouponRedemptionInfolist;
use App\Filament\Resources\CouponRedemptionResource\Tables\CouponRedemptionTable;
use App\Models\CouponRedemption;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
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

    /**
     * 覆蓋Filament的全域範圍查詢，確保Super Admin能看到所有租戶的核銷紀錄
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 套用共用的租戶範圍邏輯
        return static::applyTenantScoping($query, [
            'tenant',
            'customer',
            'userCoupon.couponTemplate',
            'creator'
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CouponRedemptionInfolist::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return CouponRedemptionTable::table($table);
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
