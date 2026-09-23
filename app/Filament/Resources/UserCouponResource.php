<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\UserCouponResource\Pages;
use App\Filament\Resources\UserCouponResource\Schemas\UserCouponInfolist;
use App\Filament\Resources\UserCouponResource\Tables\UserCouponTable;
use App\Models\UserCoupon;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
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

    public static function infolist(Schema $schema): Schema
    {
        return UserCouponInfolist::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return UserCouponTable::table($table);
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
