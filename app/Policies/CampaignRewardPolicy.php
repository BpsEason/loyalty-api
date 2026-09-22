<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CampaignReward;
use Illuminate\Auth\Access\HandlesAuthorization;

class CampaignRewardPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CampaignReward');
    }

    public function view(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        return $authUser->can('View:CampaignReward');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CampaignReward');
    }

    public function update(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        return $authUser->can('Update:CampaignReward');
    }

    public function delete(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        return $authUser->can('Delete:CampaignReward');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CampaignReward');
    }

    public function restore(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        return $authUser->can('Restore:CampaignReward');
    }

    public function forceDelete(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        return $authUser->can('ForceDelete:CampaignReward');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CampaignReward');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CampaignReward');
    }

    public function replicate(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        return $authUser->can('Replicate:CampaignReward');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CampaignReward');
    }

}