<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CouponTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;

class CouponTemplatePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CouponTemplate');
    }

    public function view(AuthUser $authUser, CouponTemplate $couponTemplate): bool
    {
        return $authUser->can('View:CouponTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CouponTemplate');
    }

    public function update(AuthUser $authUser, CouponTemplate $couponTemplate): bool
    {
        return $authUser->can('Update:CouponTemplate');
    }

    public function delete(AuthUser $authUser, CouponTemplate $couponTemplate): bool
    {
        return $authUser->can('Delete:CouponTemplate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CouponTemplate');
    }

    public function restore(AuthUser $authUser, CouponTemplate $couponTemplate): bool
    {
        return $authUser->can('Restore:CouponTemplate');
    }

    public function forceDelete(AuthUser $authUser, CouponTemplate $couponTemplate): bool
    {
        return $authUser->can('ForceDelete:CouponTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CouponTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CouponTemplate');
    }

    public function replicate(AuthUser $authUser, CouponTemplate $couponTemplate): bool
    {
        return $authUser->can('Replicate:CouponTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CouponTemplate');
    }

}