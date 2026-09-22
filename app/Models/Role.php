<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'team_id',
    ];

    /**
     * Get the team that owns the role.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'team_id');
    }
}
