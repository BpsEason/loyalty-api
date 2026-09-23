<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Customer;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        // Super Admin 或有權查看客戶的使用者都可以查看列表
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        return $authUser->can('View:Customer') || $authUser->can('ViewAny:Customer');
    }

    public function view(AuthUser $authUser, Customer $customer): bool
    {
        // Super Admin 可以查看所有客戶
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能查看自己租戶的客戶
        return $authUser->can('View:Customer') && $authUser->tenant_id === $customer->tenant_id;
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Customer');
    }

    public function update(AuthUser $authUser, Customer $customer): bool
    {
        // Super Admin 可以更新所有客戶
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能更新自己租戶的客戶
        return $authUser->can('Update:Customer') && $authUser->tenant_id === $customer->tenant_id;
    }

    public function delete(AuthUser $authUser, Customer $customer): bool
    {
        // Super Admin 可以刪除所有客戶
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能刪除自己租戶的客戶
        return $authUser->can('Delete:Customer') && $authUser->tenant_id === $customer->tenant_id;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Customer');
    }

    public function restore(AuthUser $authUser, Customer $customer): bool
    {
        return $authUser->can('Restore:Customer');
    }

    public function forceDelete(AuthUser $authUser, Customer $customer): bool
    {
        return $authUser->can('ForceDelete:Customer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Customer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Customer');
    }

    public function replicate(AuthUser $authUser, Customer $customer): bool
    {
        return $authUser->can('Replicate:Customer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Customer');
    }
}
