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
        return $authUser->can('ViewAny:Customer');
    }

    public function view(AuthUser $authUser, Customer $customer): bool
    {
        // Super admin can view any customer
        if ($authUser->hasRole('super_admin')) {
            return true;
        }

        // Regular users can only view customers from their own tenant
        if ($authUser->tenant_id !== $customer->tenant_id) {
            return false;
        }

        return $authUser->can('View:Customer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Customer');
    }

    public function update(AuthUser $authUser, Customer $customer): bool
    {
        // Super admin can update any customer
        if ($authUser->hasRole('super_admin')) {
            return true;
        }

        // Regular users can only update customers from their own tenant
        if ($authUser->tenant_id !== $customer->tenant_id) {
            return false;
        }

        return $authUser->can('Update:Customer');
    }

    public function delete(AuthUser $authUser, Customer $customer): bool
    {
        // Super admin can delete any customer
        if ($authUser->hasRole('super_admin')) {
            return true;
        }

        // Regular users can only delete customers from their own tenant
        if ($authUser->tenant_id !== $customer->tenant_id) {
            return false;
        }

        return $authUser->can('Delete:Customer');
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
