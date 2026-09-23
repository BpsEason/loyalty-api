<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\PointAccount;
use Illuminate\Auth\Access\HandlesAuthorization;

class PointAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        // Super Admin 或有權查看點數帳戶的使用者都可以查看列表
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        return $authUser->can('View:PointAccount') || $authUser->can('ViewAny:PointAccount');
    }

    public function view(AuthUser $authUser, PointAccount $pointAccount): bool
    {
        // Super Admin 可以查看所有點數帳戶
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能查看自己租戶的點數帳戶
        return $authUser->can('View:PointAccount') && $authUser->tenant_id === $pointAccount->tenant_id;
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PointAccount');
    }

    public function update(AuthUser $authUser, PointAccount $pointAccount): bool
    {
        return $authUser->can('Update:PointAccount');
    }

    public function delete(AuthUser $authUser, PointAccount $pointAccount): bool
    {
        return $authUser->can('Delete:PointAccount');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PointAccount');
    }

    public function restore(AuthUser $authUser, PointAccount $pointAccount): bool
    {
        return $authUser->can('Restore:PointAccount');
    }

    public function forceDelete(AuthUser $authUser, PointAccount $pointAccount): bool
    {
        return $authUser->can('ForceDelete:PointAccount');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PointAccount');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PointAccount');
    }

    public function replicate(AuthUser $authUser, PointAccount $pointAccount): bool
    {
        return $authUser->can('Replicate:PointAccount');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PointAccount');
    }
}
