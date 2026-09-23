<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\PointAccount;
use App\Filament\Resources\PointAccountResource\Pages;
use App\Filament\Resources\PointAccountResource\Schemas\PointAccountForm;
use App\Filament\Resources\PointAccountResource\Tables\PointAccountTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class PointAccountResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = PointAccount::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '點數帳戶';
    protected static ?string $pluralModelLabel = '點數帳戶';
    protected static ?string $navigationLabel = '點數帳戶';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $panel = filament()->getCurrentOrDefaultPanel();

        // 永遠載入 tenant 關聯
        $withRelations = ['tenant'];

        if ($user && $user->isSuperAdmin() && $panel?->hasTenancy()) {
            $scopeName = $panel->getTenancyScopeName();
            // 正確格式：將 customer 關聯與其約束直接加入 with 陣列
            $withRelations['customer'] = fn($q) => $q->withoutGlobalScope($scopeName);
        } else {
            // 一般使用者直接載入 customer，由 Model 全域範圍自動處理
            $withRelations[] = 'customer';
        }

        // 處理 eager loading
        $query = static::applyTenantScoping($query, $withRelations);

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return PointAccountForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return PointAccountTable::table($table);
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
            'index' => Pages\ListPointAccounts::route('/'),
            'create' => Pages\CreatePointAccount::route('/create'),
            'edit' => Pages\EditPointAccount::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return $user->hasRole('tenant_admin') && $record->tenant_id === $user->tenant_id;
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return $user->hasRole('tenant_admin') && $record->tenant_id === $user->tenant_id;
    }
}
