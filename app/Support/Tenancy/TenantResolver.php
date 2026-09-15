<?php

namespace App\Support\Tenancy;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Guard;

class TenantResolver
{
    public function __construct(protected TenantContext $tenantContext) {}

    public function resolveForUser(User $user): ?Tenant
    {
        $tenant = $user->tenant;

        if ($tenant) {
            $this->tenantContext->setTenant($tenant);
        }

        return $tenant;
    }

    public function clear(): void
    {
        $this->tenantContext->clear();
    }

    public function getCurrentTenant(): ?Tenant
    {
        return $this->tenantContext->getTenant();
    }

    public function getCurrentTenantId(): ?int
    {
        return $this->tenantContext->getTenantId();
    }
}
