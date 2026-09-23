<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\PointTransaction;
use Illuminate\Auth\Access\HandlesAuthorization;

class PointTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        // Super Admin 或有權查看點數交易的使用者都可以查看列表
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        return $authUser->can('View:PointTransaction') || $authUser->can('ViewAny:PointTransaction');
    }

    public function view(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        // Super Admin 可以查看所有點數交易
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能查看自己租戶的點數交易
        return $authUser->can('View:PointTransaction') && $authUser->tenant_id === $pointTransaction->tenant_id;
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PointTransaction');
    }

    public function update(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        return $authUser->can('Update:PointTransaction');
    }

    public function delete(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        return $authUser->can('Delete:PointTransaction');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PointTransaction');
    }

    public function restore(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        return $authUser->can('Restore:PointTransaction');
    }

    public function forceDelete(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        return $authUser->can('ForceDelete:PointTransaction');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PointTransaction');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PointTransaction');
    }

    public function replicate(AuthUser $authUser, PointTransaction $pointTransaction): bool
    {
        return $authUser->can('Replicate:PointTransaction');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PointTransaction');
    }
}
