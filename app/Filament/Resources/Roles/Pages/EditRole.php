<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Override;

class EditRole extends EditRecord
{
    public Collection $permissions;

    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $record = $this->record;

        // 非super_admin的使用者禁止修改super_admin角色
        if (!$user->hasRole('super_admin') && $record->name === 'super_admin') {
            abort(403, 'You are not allowed to modify the super_admin role.');
        }

        // 非super_admin的使用者禁止修改其他租戶的角色
        if (!$user->hasRole('super_admin') && $record->team_id !== $user->tenant_id) {
            abort(403, 'You are not allowed to modify roles from other tenants.');
        }

        // 非super_admin的使用者無法修改角色的team_id
        if (!$user->hasRole('super_admin') && $user->hasRole('tenant_admin')) {
            // 強制保持原有的team_id
            $data[Utils::getTenantModelForeignKey()] = $record->team_id;
        }

        $this->permissions = collect($data)
            ->filter(fn(mixed $permission, string $key): bool => ! in_array($key, ['name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()], true))
            ->values()
            ->flatten()
            ->unique();

        if (Utils::isTenancyEnabled() && Arr::has($data, Utils::getTenantModelForeignKey()) && filled($data[Utils::getTenantModelForeignKey()])) {
            return Arr::only($data, ['name', 'guard_name', Utils::getTenantModelForeignKey()]);
        }

        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $permissionModels = collect();
        $this->permissions->each(function (string $permission) use ($permissionModels): void {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->data['guard_name'],
            ]));
        });

        // @phpstan-ignore-next-line
        $this->record->syncPermissions($permissionModels);
    }
}
