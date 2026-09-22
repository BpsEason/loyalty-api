<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\UserCoupon;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserCouponPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:UserCoupon');
    }

    public function view(AuthUser $authUser, UserCoupon $userCoupon): bool
    {
        return $authUser->can('View:UserCoupon');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:UserCoupon');
    }

    public function update(AuthUser $authUser, UserCoupon $userCoupon): bool
    {
        return $authUser->can('Update:UserCoupon');
    }

    public function delete(AuthUser $authUser, UserCoupon $userCoupon): bool
    {
        return $authUser->can('Delete:UserCoupon');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:UserCoupon');
    }

    public function restore(AuthUser $authUser, UserCoupon $userCoupon): bool
    {
        return $authUser->can('Restore:UserCoupon');
    }

    public function forceDelete(AuthUser $authUser, UserCoupon $userCoupon): bool
    {
        return $authUser->can('ForceDelete:UserCoupon');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:UserCoupon');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:UserCoupon');
    }

    public function replicate(AuthUser $authUser, UserCoupon $userCoupon): bool
    {
        return $authUser->can('Replicate:UserCoupon');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:UserCoupon');
    }

}