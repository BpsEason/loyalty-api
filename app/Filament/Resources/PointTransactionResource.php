<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\PointTransaction;
use App\Filament\Resources\PointTransactionResource\Pages;
use App\Filament\Resources\PointTransactionResource\Schemas\PointTransactionForm;
use App\Filament\Resources\PointTransactionResource\Tables\PointTransactionTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class PointTransactionResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = PointTransaction::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = '點數交易';
    protected static ?string $pluralModelLabel = '點數交易';
    protected static ?string $navigationLabel = '點數交易';

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
            // 對嵌套關聯 pointAccount.customer 移除 Filament 原生租戶範圍
            // 同時也移除 Model 層自己的 tenant 全域範圍，雙重保險
            $withRelations['pointAccount'] = fn($q) => $q->withoutGlobalScope($scopeName)->withoutGlobalScope('tenant');
            $withRelations['pointAccount.customer'] = fn($q) => $q->withoutGlobalScope($scopeName)->withoutGlobalScope('tenant');
        } else {
            // 一般使用者直接載入，由 Model 全域範圍自動處理
            $withRelations[] = 'pointAccount.customer';
        }

        // 處理 eager loading
        $query = static::applyTenantScoping($query, $withRelations);

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return PointTransactionForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return PointTransactionTable::table($table);
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
            'index' => Pages\ListPointTransactions::route('/'),
            'create' => Pages\CreatePointTransaction::route('/create'),
            'edit' => Pages\EditPointTransaction::route('/{record}/edit'),
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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
