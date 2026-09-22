<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use BezhanSalleh\PluginEssentials\Concerns\Resource as Essentials;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Database\Eloquent\Builder;
use Override;
use UnitEnum;
use BackedEnum;

class RoleResource extends Resource
{
    use Essentials\BelongsToParent;
    use Essentials\HasGlobalSearch;
    use Essentials\HasLabels;
    use Essentials\HasNavigation;
    use HasShieldFormComponents;

    public static function getNavigationGroup(): ?string
    {
        return '系統管理';
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function getNavigationIcon(): BackedEnum|string|null
    {
        return 'heroicon-o-shield-check';
    }

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * 是否將資源範圍限制在目前的租戶
     * Role模型使用team_id而非tenant_id，因此需要手動處理租戶隔離
     */
    public static function isScopedToTenant(): bool
    {
        // 關閉Filament內建的自動租戶範圍，我們會手動處理team_id過濾
        return false;
    }

    /**
     * 覆蓋角色資源查詢，確保租戶管理員只能檢視與管理該租戶內的角色
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (!$user) {
            return $query;
        }

        // Super Admin (tenant_id為null) 可以檢視所有角色
        if ($user->isSuperAdmin() || is_null($user->tenant_id)) {
            return $query;
        }

        // 一般租戶管理員只能檢視所屬租戶 (team_id === tenant_id) 的角色，並自動排除 super_admin
        return $query->where('team_id', $user->tenant_id)
            ->where('name', '!=', 'super_admin');
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('角色基本資訊')
                    ->description('設定角色的基本識別資訊，用於系統權限管理')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('filament-shield::filament-shield.field.name'))
                                    ->unique(
                                        ignoreRecord: true,
                                        /** @phpstan-ignore-next-line */
                                        modifyRuleUsing: fn(Unique $rule): Unique => Utils::isTenancyEnabled() ? $rule->where(Utils::getTenantModelForeignKey(), Filament::getTenant()?->id) : $rule
                                    )
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('例如：tenant_admin、tenant_staff')
                                    ->helperText('角色名稱將用於識別此角色及其權限範圍，不可重複')
                                    ->columnSpan(1),

                                TextInput::make('guard_name')
                                    ->label(__('filament-shield::filament-shield.field.guard_name'))
                                    ->default(Utils::getFilamentAuthGuard())
                                    ->nullable()
                                    ->maxLength(255)
                                    ->placeholder('web')
                                    ->helperText('一般保持預設值即可')
                                    ->columnSpan(1),

                                Select::make(config('permission.column_names.team_foreign_key'))
                                    ->label('所屬租戶')
                                    ->placeholder('請選擇此角色所屬的租戶')
                                    /** @phpstan-ignore-next-line */
                                    ->default(Filament::getTenant()?->id)
                                    ->options(fn(): array => in_array(Utils::getTenantModel(), [null, '', '0'], true) ? [] : Utils::getTenantModel()::pluck('name', 'id')->toArray())
                                    ->visible(fn(): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled())
                                    ->dehydrated(fn(): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled())
                                    ->searchable()
                                    ->helperText('只有 Super Admin 可以修改租戶設定，確保角色僅能在正確租戶使用')
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('權限設定')
                    ->description('配置此角色擁有的系統權限，權限將套用給所有擁有此角色的使用者')
                    ->schema([
                        static::getSelectAllFormComponent(),
                        static::getShieldFormComponents(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::Bold)
                    ->label(__('filament-shield::filament-shield.column.name'))
                    ->formatStateUsing(fn(string $state): string => Str::headline($state))
                    ->searchable()
                    ->description(fn($record) => $record->guard_name)
                    ->color(fn(string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'tenant_admin' => 'warning',
                        'tenant_staff' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('team.name')
                    ->default('Global')
                    ->badge()
                    ->color(fn(mixed $state): string => str($state)->contains('Global') ? 'gray' : 'primary')
                    ->label('所屬租戶')
                    ->searchable()
                    ->visible(fn(): bool => (auth()->user()?->isSuperAdmin() || (static::shield()->isCentralApp() && Utils::isTenancyEnabled())) && Utils::isTenancyEnabled()),
                TextColumn::make('permissions_count')
                    ->badge()
                    ->label('權限數量')
                    ->counts('permissions')
                    ->formatStateUsing(fn(int $state): string => $state . ' 個權限')
                    ->color(fn(int $state): string => $state > 20 ? 'danger' : ($state > 10 ? 'warning' : 'success'))
                    ->alignCenter(),
                TextColumn::make('updated_at')
                    ->label(__('filament-shield::filament-shield.column.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->tooltip('編輯角色')
                    ->icon('heroicon-o-pencil'),
                DeleteAction::make()
                    ->tooltip('刪除角色')
                    ->icon('heroicon-o-trash'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->tooltip('批量刪除角色')
                    ->icon('heroicon-o-trash'),
            ]);
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getModel(): string
    {
        return Utils::getRoleModel();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return Utils::getResourceSlug();
    }

    public static function getCluster(): ?string
    {
        return Utils::getResourceCluster();
    }

    public static function getEssentialsPlugin(): ?FilamentShieldPlugin
    {
        return FilamentShieldPlugin::get();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $user = auth()->user();

        // super_admin 可以編輯所有角色
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // tenant_admin 只能編輯自己租戶的非super_admin角色
        if (
            $user->hasRole('tenant_admin') &&
            $record->name !== 'super_admin' &&
            $record->team_id === $user->tenant_id
        ) {
            return true;
        }

        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $user = auth()->user();

        // super_admin 可以刪除所有角色
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // tenant_admin 只能刪除自己租戶的非super_admin角色
        if (
            $user->hasRole('tenant_admin') &&
            $record->name !== 'super_admin' &&
            $record->team_id === $user->tenant_id
        ) {
            return true;
        }

        return false;
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $user = auth()->user();

        // super_admin 可以查看所有角色
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // tenant_admin 只能查看自己租戶的非super_admin角色
        if (
            $user->hasRole('tenant_admin') &&
            $record->name !== 'super_admin' &&
            $record->team_id === $user->tenant_id
        ) {
            return true;
        }

        return false;
    }

    /**
     * 覆蓋Shield的權限狀態設定方法，解決冒號不一致問題
     * 資料庫權限名稱使用::（雙冒號），但Shield表單使用:（單冒號）
     */
    public static function setPermissionStateForRecordPermissions(\Filament\Schemas\Components\Component $component, string $operation, array $permissions, ?\Illuminate\Database\Eloquent\Model $record): void
    {
        if (in_array($operation, ['edit', 'view'], true)) {
            if (blank($record)) {
                return;
            }

            if ($component->isVisible() && $permissions !== []) {
                $component->state(
                    collect($permissions)
                        ->filter(fn($value, $key) => $record->checkPermissionTo(str_replace(':', '::', $key)))
                        ->keys()
                        ->toArray()
                );
            }
        }
    }
}
