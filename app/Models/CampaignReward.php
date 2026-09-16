<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignReward extends Model
{
    public const TYPE_POINTS = 'points';
    public const TYPE_BADGE = 'badge';
    public const TYPE_COUPON = 'coupon';

    protected $fillable = [
        'campaign_id',
        'reward_type',
        'points',
        'enabled',
    ];

    protected $casts = [
        'points' => 'integer',
        'enabled' => 'boolean',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(RewardGrant::class);
    }
}
