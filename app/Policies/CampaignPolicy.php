<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Campaign;
use Illuminate\Auth\Access\HandlesAuthorization;

class CampaignPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Campaign');
    }

    public function view(AuthUser $authUser, Campaign $campaign): bool
    {
        return $authUser->can('View:Campaign');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Campaign');
    }

    public function update(AuthUser $authUser, Campaign $campaign): bool
    {
        // Super Admin 可以更新所有活動
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能更新自己租戶的活動
        return $authUser->can('Update:Campaign') && $authUser->tenant_id === $campaign->tenant_id;
    }

    public function delete(AuthUser $authUser, Campaign $campaign): bool
    {
        // Super Admin 可以刪除所有活動
        if ($authUser->isSuperAdmin()) {
            return true;
        }

        // Tenant Admin 只能刪除自己租戶的活動
        return $authUser->can('Delete:Campaign') && $authUser->tenant_id === $campaign->tenant_id;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Campaign');
    }

    public function restore(AuthUser $authUser, Campaign $campaign): bool
    {
        return $authUser->can('Restore:Campaign');
    }

    public function forceDelete(AuthUser $authUser, Campaign $campaign): bool
    {
        return $authUser->can('ForceDelete:Campaign');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Campaign');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Campaign');
    }

    public function replicate(AuthUser $authUser, Campaign $campaign): bool
    {
        return $authUser->can('Replicate:Campaign');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Campaign');
    }
}
