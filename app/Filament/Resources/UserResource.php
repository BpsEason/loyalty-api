<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\User;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\Schemas\UserForm;
use App\Filament\Resources\UserResource\Tables\UserTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class UserResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = User::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static string|UnitEnum|null $navigationGroup = '系統管理';
    protected static ?string $modelLabel = '使用者';
    protected static ?string $pluralModelLabel = '使用者';
    protected static ?string $navigationLabel = '使用者';
    protected static ?int $navigationSort = 2;

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 處理必要的eager loading
        $query = static::applyTenantScoping($query, ['tenant']);

        // 記錄Super Admin的查詢，保留原有的日誌
        $user = auth()->user();
        if ($user && $user->isSuperAdmin()) {
            logger()->debug('Super Admin User Query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return UserTable::schema($table);
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user && ($user->isSuperAdmin() || $user->hasRole('tenant_admin'));
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        return $user && ($user->isSuperAdmin() || $user->hasRole('tenant_admin'));
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();
        if ($user && $user->isSuperAdmin()) {
            return true;
        }
        // Tenant Admin 只能編輯自己租戶的使用者
        return $user && $user->hasRole('tenant_admin') && $record->tenant_id === $user->tenant_id;
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if ($user && $user->isSuperAdmin()) {
            return true;
        }
        // Tenant Admin 只能刪除自己租戶的使用者
        return $user && $user->hasRole('tenant_admin') && $record->tenant_id === $user->tenant_id;
    }
}
