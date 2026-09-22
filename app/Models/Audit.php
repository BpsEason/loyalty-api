<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use OwenIt\Auditing\Models\Audit as BaseAudit;

class Audit extends BaseAudit
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_type',
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'url',
        'ip_address',
        'user_agent',
        'tags',
    ];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
