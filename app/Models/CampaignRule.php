<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRule extends Model
{
    use BelongsToTenant;

    public const RULE_TYPE_SPEND_THRESHOLD = 'spend_threshold';
    public const RULE_TYPE_PRODUCT = 'product';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'rule_type',
        'threshold',
        'product_ids',
        'points_reward',
        'priority',
        'status',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'product_ids' => 'array',
        'points_reward' => 'integer',
        'priority' => 'integer',
        'status' => 'boolean',
    ];

    /**
     * 取得所屬活動
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * 取得所屬租戶
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 檢查規則是否符合資格
     */
    public function isEligible($spendAmount = 0, $purchasedProducts = []): bool
    {
        if (!$this->status) {
            return false;
        }

        // 檢查活動狀態
        $campaign = $this->campaign;
        if ($campaign->status !== Campaign::STATUS_ACTIVE) {
            return false;
        }

        // 檢查活動時間
        $now = now();
        if ($campaign->starts_at && $now->lt($campaign->starts_at)) {
            return false;
        }
        if ($campaign->ends_at && $now->gt($campaign->ends_at)) {
            return false;
        }

        // 根據規則類型檢查
        return match ($this->rule_type) {
            self::RULE_TYPE_SPEND_THRESHOLD => $this->checkSpendThreshold($spendAmount),
            self::RULE_TYPE_PRODUCT => $this->checkProductRule($purchasedProducts),
            default => false,
        };
    }

    /**
     * 檢查消費門檻規則
     */
    protected function checkSpendThreshold($spendAmount): bool
    {
        return $spendAmount >= $this->threshold;
    }

    /**
     * 檢查商品規則
     */
    protected function checkProductRule($purchasedProducts): bool
    {
        if (empty($this->product_ids)) {
            return false;
        }

        // 檢查購買的商品是否包含規則中指定的任何商品
        foreach ($purchasedProducts as $productId) {
            if (in_array($productId, $this->product_ids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取得活動的所有啟用規則
     */
    public static function getActiveRulesForCampaign(Campaign $campaign)
    {
        return static::where('campaign_id', $campaign->id)
            ->where('status', true)
            ->orderBy('priority', 'desc')
            ->get();
    }
}
