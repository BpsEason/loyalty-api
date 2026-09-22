<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\User;
use App\Filament\Resources\UserResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Facades\Hash;
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
        return $schema
            ->schema([
                Section::make('使用者資訊')
                    ->description('設定使用者的基本帳號資訊')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('名稱')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->label('電子郵件')
                                    ->email()
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('存取與角色')
                    ->description('設定使用者所屬租戶與系統角色')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                \App\Forms\Components\TenantSelect::make()
                                    ->required(fn($context) => $context !== 'create' || request()->user()->hasRole('tenant_admin'))
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, $component) {
                                        // 當 tenant_id 變更時，清空目前選擇的角色，確保只能選擇新租戶的角色
                                        $form = $component->getParentComponent();
                                        if ($form && $rolesComponent = $form->getComponent('roles')) {
                                            $rolesComponent->state(null);
                                        }
                                    }),
                                Forms\Components\Select::make('roles')
                                    ->multiple()
                                    ->relationship('roles', 'name', function ($query, $record) {
                                        // Filament 5.8 使用簡化的邏輯，優先使用記錄本身的tenant_id
                                        $currentUser = auth()->user();
                                        $userTenantId = $record ? $record->tenant_id : $currentUser->tenant_id;

                                        if ($userTenantId) {
                                            // 只顯示屬於該User所屬Tenant的角色，排除super_admin
                                            return $query->where('roles.team_id', $userTenantId)
                                                ->where('name', '!=', 'super_admin');
                                        }
                                        // 編輯或創建Super Admin（tenant_id=null）時，只顯示全域的super_admin
                                        return $query->where('roles.team_id', 0)
                                            ->where('name', 'super_admin');
                                    })
                                    ->preload()
                                    ->searchable()
                                    ->placeholder('請選擇使用者的系統角色')
                                    ->helperText('只能選擇所屬租戶的有效角色，Super Admin 僅限全域使用者')
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(fn($state, $component) => $component->validate())
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('帳號安全')
                    ->description('設定使用者登入密碼')
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->label('密碼')
                                    ->hint('編輯模式：留空以保留目前的密碼')
                                    ->dehydrated(fn($state) => filled($state))
                                    ->afterStateHydrated(function ($component, $state) {
                                        // 永遠不把現有密碼載入到表單，確保安全性
                                    })
                                    ->required(fn($context) => $context === 'create')
                                    ->nullable(fn($context) => $context === 'edit'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                // 統一使用getEloquentQuery()，避免重複邏輯導致衝突
                $query = static::getEloquentQuery();

                $user = auth()->user();
                $allUsersRaw = User::withoutGlobalScopes()->get(['id', 'name', 'tenant_id']);

                $debugData = [
                    'auth_user_id' => $user->id,
                    'auth_user_name' => $user->name,
                    'auth_user_tenant_id' => $user->tenant_id,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'filament_current_tenant_id' => filament()->getTenant()?->getKey(),
                    'filament_current_tenant_name' => filament()->getTenant()?->name,
                    'database_all_users' => $allUsersRaw->toArray(),
                    'total_users_in_db' => $allUsersRaw->count(),
                    'final_query_sql' => $query->toSql(),
                    'final_query_bindings' => $query->getBindings(),
                    'final_results_count' => $query->count(),
                    'final_results' => $query->get(['id', 'name', 'tenant_id'])->toArray(),
                ];

                logger()->debug('=== USER LIST DEBUG START ===', $debugData);
                return $query;
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('使用者')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn(User $record) => $record->email),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('所屬租戶')
                    ->searchable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn($state) => $state ?? 'Global'),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('角色')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'tenant_admin' => 'warning',
                        'tenant_staff' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'super_admin' => '超級管理員',
                        'tenant_admin' => '租戶管理員',
                        'tenant_staff' => '租戶工作人員',
                        default => $state,
                    })
                    ->getStateUsing(function ($record) {
                        // 🔑 關鍵修正：每次查詢前先unload roles關係，確保使用正確的team_id重新查詢
                        $record->unsetRelation('roles');

                        // 切換到使用者自己的租戶ID來查詢角色，避免Filament目前租戶的全域範圍影響
                        $originalTeamId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

                        if ($record->tenant_id) {
                            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($record->tenant_id);
                        }

                        $roles = $record->roles->pluck('name')->toArray();

                        // 除錯日誌，確認每個使用者的角色查詢是否正確
                        logger()->debug('=== User Roles Debug ===', [
                            'user_id' => $record->id,
                            'user_name' => $record->name,
                            'user_tenant_id' => $record->tenant_id,
                            'current_team_id' => app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId(),
                            'roles_found' => $roles
                        ]);

                        // 還原原本的team_id
                        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($originalTeamId);

                        return $roles;
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('更新時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->tooltip('編輯使用者')
                    ->icon('heroicon-o-pencil'),
                \Filament\Actions\DeleteAction::make()
                    ->tooltip('刪除使用者')
                    ->icon('heroicon-o-trash'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->tooltip('批量刪除使用者')
                        ->icon('heroicon-o-trash'),
                ]),
            ]);
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
