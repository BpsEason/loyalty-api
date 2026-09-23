<?php

namespace App\Filament\Resources;

use App\Models\Tenant;
use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\Schemas\TenantForm;
use App\Filament\Resources\TenantResource\Tables\TenantTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';
    protected static string|UnitEnum|null $navigationGroup = '租戶管理';
    protected static ?string $modelLabel = '租戶';
    protected static ?string $pluralModelLabel = '租戶';
    protected static ?string $navigationLabel = '租戶';
    protected static ?int $navigationSort = 1;

    /**
     * 平台級資源，永遠不套用租戶範圍限制
     * 只有Super Admin可以存取此資源（由canViewAny等方法控制）
     */
    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return TenantForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantTable::table($table);
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
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->hasRole('super_admin');
    }
}
