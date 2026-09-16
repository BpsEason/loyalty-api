<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Role');
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        // 禁止租戶查看super_admin
        if ($role->name === 'super_admin' && !$authUser->hasRole('super_admin')) {
            return false;
        }

        // 禁止跨租戶查看角色
        if (!$authUser->hasRole('super_admin') && $role->team_id !== $authUser->tenant_id) {
            return false;
        }

        return $authUser->can('View:Role');
    }

    public function create(AuthUser $authUser): bool
    {
        // 只有super_admin可以建立super_admin角色
        if (request()->has('name') && request()->input('name') === 'super_admin' && !$authUser->hasRole('super_admin')) {
            return false;
        }

        return $authUser->can('Create:Role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        // 禁止租戶修改super_admin
        if ($role->name === 'super_admin' && !$authUser->hasRole('super_admin')) {
            return false;
        }

        // 禁止跨租戶修改角色
        if (!$authUser->hasRole('super_admin') && $role->team_id !== $authUser->tenant_id) {
            return false;
        }

        return $authUser->can('Update:Role');
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        // 禁止租戶刪除super_admin
        if ($role->name === 'super_admin' && !$authUser->hasRole('super_admin')) {
            return false;
        }

        // 禁止跨租戶刪除角色
        if (!$authUser->hasRole('super_admin') && $role->team_id !== $authUser->tenant_id) {
            return false;
        }

        return $authUser->can('Delete:Role');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Role');
    }

    public function restore(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Restore:Role');
    }

    public function forceDelete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('ForceDelete:Role');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Role');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Role');
    }

    public function replicate(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Replicate:Role');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Role');
    }
}
