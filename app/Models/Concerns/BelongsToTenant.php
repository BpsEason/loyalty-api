<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model) {
            $tenantResolver = app(TenantResolver::class);

            if (!$model->tenant_id && $tenantId = $tenantResolver->getCurrentTenantId()) {
                $model->tenant_id = $tenantId;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $user = auth()->user();
            $tenantResolver = app(TenantResolver::class);

            // Only apply tenant scope if user is not super_admin
            if ($user && !$user->hasRole('super_admin') && $tenantId = $tenantResolver->getCurrentTenantId()) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
