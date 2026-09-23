<?php

namespace App\Filament\Resources;

use App\Models\Customer;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\Schemas\CustomerForm;
use App\Filament\Resources\CustomerResource\Tables\CustomerTable;
use App\Filament\Concerns\HandlesTenantScoping;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class CustomerResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = Customer::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = '客戶';
    protected static ?string $pluralModelLabel = '客戶';
    protected static ?string $navigationLabel = '客戶';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由HandlesTenantScoping處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 預先載入點數帳戶和交易記錄，避免N+1查詢
        $query->with('pointAccounts.pointTransactions');

        // 套用共用的租戶範圍邏輯
        return static::applyTenantScoping($query, ['tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerTable::table($table);
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
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    /**
     * 授權邏輯委託給 CustomerPolicy，由 Laravel 原生授權系統處理
     * Filament 會自動發現並使用註冊的 Policy 進行權限檢查
     */
}
