<?php

namespace App\Services\Point\Strategies;

use App\Models\Customer;

class PurchaseEarnStrategy implements PointEarnStrategy
{
    /**
     * 預設的點數兌換比例：每消費1元獲得1點
     */
    protected float $defaultPointsPerCurrency = 1.0;

    /**
     * 根據消費金額計算可獲得的點數
     * 如果客戶有會員等級，會套用會員等級的點數倍數
     */
    public function calculate(Customer $customer, int|float $baseAmount, array $context = []): int
    {
        // 從上下文獲取自訂的兌換比例，如果沒有則使用預設值
        $pointsPerCurrency = $context['points_per_currency'] ?? $this->defaultPointsPerCurrency;

        // 計算基礎點數
        $basePoints = $baseAmount * $pointsPerCurrency;

        // 如果客戶有會員等級，套用會員等級的點數倍數
        if ($customer->membership_tier_id && $customer->membershipTier) {
            $multiplier = $customer->membershipTier->points_multiplier ?? 1.0;
            $basePoints *= $multiplier;
        }

        // 確保回傳整數點數
        return (int) round($basePoints);
    }
}
