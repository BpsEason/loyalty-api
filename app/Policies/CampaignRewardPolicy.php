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
        // Super Admin 可以更新所有活動獎勵
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能更新自己租戶的活動獎勵
        return $authUser->can('Update:CampaignReward') && $authUser->tenant_id === $campaignReward->campaign?->tenant_id;
    }

    public function delete(AuthUser $authUser, CampaignReward $campaignReward): bool
    {
        // Super Admin 可以刪除所有活動獎勵
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能刪除自己租戶的活動獎勵
        return $authUser->can('Delete:CampaignReward') && $authUser->tenant_id === $campaignReward->campaign?->tenant_id;
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
