<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class Campaign extends Model implements Auditable
{
    use BelongsToTenant;
    use \OwenIt\Auditing\Auditable;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'status',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(CampaignReward::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(RewardGrant::class);
    }

    /**
     * 取得此活動的所有規則
     */
    public function rules(): HasMany
    {
        return $this->hasMany(\App\Models\CampaignRule::class);
    }
}
