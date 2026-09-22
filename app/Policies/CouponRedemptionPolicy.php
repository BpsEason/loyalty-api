<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CouponRedemption;
use Illuminate\Auth\Access\HandlesAuthorization;

class CouponRedemptionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CouponRedemption');
    }

    public function view(AuthUser $authUser, CouponRedemption $couponRedemption): bool
    {
        return $authUser->can('View:CouponRedemption');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CouponRedemption');
    }

    public function update(AuthUser $authUser, CouponRedemption $couponRedemption): bool
    {
        return $authUser->can('Update:CouponRedemption');
    }

    public function delete(AuthUser $authUser, CouponRedemption $couponRedemption): bool
    {
        return $authUser->can('Delete:CouponRedemption');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CouponRedemption');
    }

    public function restore(AuthUser $authUser, CouponRedemption $couponRedemption): bool
    {
        return $authUser->can('Restore:CouponRedemption');
    }

    public function forceDelete(AuthUser $authUser, CouponRedemption $couponRedemption): bool
    {
        return $authUser->can('ForceDelete:CouponRedemption');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CouponRedemption');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CouponRedemption');
    }

    public function replicate(AuthUser $authUser, CouponRedemption $couponRedemption): bool
    {
        return $authUser->can('Replicate:CouponRedemption');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CouponRedemption');
    }

}