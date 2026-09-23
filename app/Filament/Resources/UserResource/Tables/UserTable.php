<?php

namespace App\Filament\Resources\UserResource\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\PermissionRegistrar;

class UserTable
{
    public static function schema(Table $table): Table
    {
        return $table
            ->query(function () {
                // 統一使用getEloquentQuery()，避免重複邏輯導致衝突
                $query = \App\Filament\Resources\UserResource::getEloquentQuery();

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
                TextColumn::make('name')
                    ->label('使用者')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn(User $record) => $record->email),
                TextColumn::make('tenant.name')
                    ->label('所屬租戶')
                    ->searchable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn($state) => $state ?? 'Global'),
                TextColumn::make('roles.name')
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
                        $originalTeamId = app(PermissionRegistrar::class)->getPermissionsTeamId();

                        if ($record->tenant_id) {
                            app(PermissionRegistrar::class)->setPermissionsTeamId($record->tenant_id);
                        }

                        $roles = $record->roles->pluck('name')->toArray();

                        // 除錯日誌，確認每個使用者的角色查詢是否正確
                        logger()->debug('=== User Roles Debug ===', [
                            'user_id' => $record->id,
                            'user_name' => $record->name,
                            'user_tenant_id' => $record->tenant_id,
                            'current_team_id' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
                            'roles_found' => $roles
                        ]);

                        // 還原原本的team_id
                        app(PermissionRegistrar::class)->setPermissionsTeamId($originalTeamId);

                        return $roles;
                    }),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('updated_at')
                    ->label('更新時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make()
                    ->tooltip('編輯使用者')
                    ->icon('heroicon-o-pencil'),
                DeleteAction::make()
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
}
