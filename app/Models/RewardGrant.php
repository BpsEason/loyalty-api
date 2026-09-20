<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardGrant extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_GRANTED = 'granted';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'campaign_reward_id',
        'customer_id',
        'status',
        'granted_at',
        'failure_reason',
        'point_transaction_id',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function campaignReward(): BelongsTo
    {
        return $this->belongsTo(CampaignReward::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pointTransaction(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class);
    }
}
