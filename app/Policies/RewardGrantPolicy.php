<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\RewardGrant;
use Illuminate\Auth\Access\HandlesAuthorization;

class RewardGrantPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RewardGrant');
    }

    public function view(AuthUser $authUser, RewardGrant $rewardGrant): bool
    {
        return $authUser->can('View:RewardGrant');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RewardGrant');
    }

    public function update(AuthUser $authUser, RewardGrant $rewardGrant): bool
    {
        return $authUser->can('Update:RewardGrant');
    }

    public function delete(AuthUser $authUser, RewardGrant $rewardGrant): bool
    {
        return $authUser->can('Delete:RewardGrant');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:RewardGrant');
    }

    public function restore(AuthUser $authUser, RewardGrant $rewardGrant): bool
    {
        return $authUser->can('Restore:RewardGrant');
    }

    public function forceDelete(AuthUser $authUser, RewardGrant $rewardGrant): bool
    {
        return $authUser->can('ForceDelete:RewardGrant');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:RewardGrant');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:RewardGrant');
    }

    public function replicate(AuthUser $authUser, RewardGrant $rewardGrant): bool
    {
        return $authUser->can('Replicate:RewardGrant');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:RewardGrant');
    }

}