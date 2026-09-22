<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipTier extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'sort_order',
        'upgrade_threshold',
        'threshold_type',
        'status',
        'points_multiplier',
        'discount_rate',
        'free_shipping',
        'metadata',
    ];

    protected $casts = [
        'upgrade_threshold' => 'decimal:2',
        'points_multiplier' => 'decimal:2',
        'discount_rate' => 'decimal:4',
        'free_shipping' => 'boolean',
        'status' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * 取得此等級的所有客戶
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * 取得所屬租戶
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 取得當前租戶的已啟用等級，依排序順序
     */
    public static function getActiveTiersForCurrentTenant()
    {
        return static::where('status', true)
            ->orderBy('sort_order', 'asc')
            ->orderBy('upgrade_threshold', 'asc')
            ->get();
    }

    /**
     * 根據累積值取得合適的等級
     */
    public static function getEligibleTier($totalValue, $thresholdType, $tenantId = null)
    {
        $query = static::where('status', true)
            ->where('threshold_type', $thresholdType);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $tiers = $query->orderBy('upgrade_threshold', 'asc')->get();

        $eligibleTier = null;
        foreach ($tiers as $tier) {
            if ($totalValue >= $tier->upgrade_threshold) {
                $eligibleTier = $tier;
            } else {
                break;
            }
        }

        return $eligibleTier;
    }

    /**
     * 取得下一個等級
     */
    public function getNextTier()
    {
        return static::where('tenant_id', $this->tenant_id)
            ->where('status', true)
            ->where('threshold_type', $this->threshold_type)
            ->where('upgrade_threshold', '>', $this->upgrade_threshold)
            ->orderBy('upgrade_threshold', 'asc')
            ->first();
    }
}
