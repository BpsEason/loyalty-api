<?php

namespace App\Services\Point\Strategies;

use App\Models\Customer;

class VipExclusiveEarnStrategy implements PointEarnStrategy
{
    /**
     * VIP專屬的額外點數倍數
     */
    protected float $vipExtraMultiplier = 1.5;

    /**
     * VIP專屬的點數計算，疊加會員等級的倍數再加上VIP專屬加成
     */
    public function calculate(Customer $customer, int|float $baseAmount, array $context = []): int
    {
        // 從上下文獲取基礎兌換比例
        $pointsPerCurrency = $context['points_per_currency'] ?? 1.0;

        // 計算基礎點數
        $basePoints = $baseAmount * $pointsPerCurrency;

        // 先套用會員等級本身的倍數
        if ($customer->membership_tier_id && $customer->membershipTier) {
            $tierMultiplier = $customer->membershipTier->points_multiplier ?? 1.0;
            $basePoints *= $tierMultiplier;
        }

        // 再疊加VIP專屬的額外倍數
        $vipPoints = $basePoints * $this->vipExtraMultiplier;

        // 從上下文可自訂VIP額外倍數
        if (isset($context['vip_extra_multiplier'])) {
            $vipPoints = $basePoints * $context['vip_extra_multiplier'];
        }

        return (int) round($vipPoints);
    }
}
