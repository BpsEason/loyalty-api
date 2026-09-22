<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\MembershipTier;
use Illuminate\Auth\Access\HandlesAuthorization;

class MembershipTierPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MembershipTier');
    }

    public function view(AuthUser $authUser, MembershipTier $membershipTier): bool
    {
        return $authUser->can('View:MembershipTier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MembershipTier');
    }

    public function update(AuthUser $authUser, MembershipTier $membershipTier): bool
    {
        return $authUser->can('Update:MembershipTier');
    }

    public function delete(AuthUser $authUser, MembershipTier $membershipTier): bool
    {
        return $authUser->can('Delete:MembershipTier');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MembershipTier');
    }

    public function restore(AuthUser $authUser, MembershipTier $membershipTier): bool
    {
        return $authUser->can('Restore:MembershipTier');
    }

    public function forceDelete(AuthUser $authUser, MembershipTier $membershipTier): bool
    {
        return $authUser->can('ForceDelete:MembershipTier');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MembershipTier');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MembershipTier');
    }

    public function replicate(AuthUser $authUser, MembershipTier $membershipTier): bool
    {
        return $authUser->can('Replicate:MembershipTier');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MembershipTier');
    }

}