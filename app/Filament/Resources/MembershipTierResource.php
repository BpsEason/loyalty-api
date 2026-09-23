<?php

namespace App\Filament\Resources;

use App\Models\MembershipTier;
use App\Filament\Resources\MembershipTierResource\Pages;
use App\Filament\Resources\MembershipTierResource\Schemas\MembershipTierForm;
use App\Filament\Resources\MembershipTierResource\Tables\MembershipTierTable;
use App\Filament\Concerns\HandlesTenantScoping;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class MembershipTierResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = MembershipTier::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = '會員等級';
    protected static ?string $pluralModelLabel = '會員等級';
    protected static ?string $navigationLabel = '會員等級';

    /**
     * 處理Eloquent查詢，應用租戶隔離
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $query = static::applyTenantScoping($query, ['tenant']);
        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return MembershipTierForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipTierTable::table($table);
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
            'index' => Pages\ListMembershipTiers::route('/'),
            'create' => Pages\CreateMembershipTier::route('/create'),
            'view' => Pages\ViewMembershipTier::route('/{record}'),
            'edit' => Pages\EditMembershipTier::route('/{record}/edit'),
        ];
    }
}
