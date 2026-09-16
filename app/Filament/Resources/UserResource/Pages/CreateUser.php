<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Tenant Admin 只能建立自己租戶的使用者
        if (auth()->user()->hasRole('tenant_admin')) {
            $data['tenant_id'] = auth()->user()->tenant_id;
        }

        // Hash 密碼
        if (isset($data['password']) && filled($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->record;
        $data = $this->form->getState();

        // 🎯 手動同步角色，永遠使用使用者自己的tenant_id
        if (isset($data['roles'])) {
            $originalTeamId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

            // 🛡️ 永遠禁止租戶使用者擁有super_admin角色
            $filteredRoles = collect($data['roles'])->filter(function ($roleId) {
                $role = \Spatie\Permission\Models\Role::find($roleId);
                return $role && $role->name !== 'super_admin';
            })->toArray();

            // 永遠切換到使用者自己的租戶ID來同步
            if ($record->tenant_id) {
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($record->tenant_id);
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
