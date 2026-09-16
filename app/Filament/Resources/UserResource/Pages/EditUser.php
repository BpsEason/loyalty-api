<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['password']) && filled($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->record;
        $data = $this->form->getState();

        // 🎯 手動同步角色，永遠使用使用者目前的tenant_id（包含更新後的新值）
        if (isset($data['roles'])) {
            $originalTeamId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

            // 🛡️ 永遠禁止租戶使用者擁有super_admin角色
            $filteredRoles = collect($data['roles'])->filter(function ($roleId) {
                $role = \Spatie\Permission\Models\Role::find($roleId);
                return $role && $role->name !== 'super_admin';
            })->toArray();

            // 永遠切換到使用者目前的租戶ID來同步（使用表單中新的tenant_id，不是資料庫舊值）
            $currentTenantId = $data['tenant_id'] ?? $record->tenant_id;

            if ($currentTenantId) {
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($currentTenantId);
            } elseif ($currentTenant = filament()->getTenant()) {
                // 如果使用者沒有自己的租戶但在Filament租戶中，使用當前租戶
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($currentTenant->id);
            }

            $record->syncRoles($filteredRoles);

            // 還原原本的team_id
            app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($originalTeamId);
        }
    }
}
