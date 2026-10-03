<?php

namespace App\Services\Point\Strategies;

use App\Models\Customer;

class CampaignEarnStrategy implements PointEarnStrategy
{
    /**
     * 根據活動規則計算可獲得的點數
     * 支援固定倍數加成或固定點數獎勵
     */
    public function calculate(Customer $customer, int|float $baseAmount, array $context = []): int
    {
        // 從上下文取得活動參數
        $campaignMultiplier = $context['campaign_multiplier'] ?? 1.0; // 活動倍數
        $campaignBonusPoints = $context['campaign_bonus_points'] ?? 0; // 固定獎勵點數
        $basePointsPerCurrency = $context['base_points_per_currency'] ?? 1.0; // 基礎兌換比例

        // 計算基礎點數
        $basePoints = $baseAmount * $basePointsPerCurrency;

        // 套用活動倍數
        $campaignPoints = $basePoints * $campaignMultiplier;

        // 加上固定獎勵點數
        $totalPoints = $campaignPoints + $campaignBonusPoints;

        // 如果客戶有會員等級，仍然套用會員等級的點數倍數
        if ($customer->membership_tier_id && $customer->membershipTier) {
            $tierMultiplier = $customer->membershipTier->points_multiplier ?? 1.0;
            $totalPoints *= $tierMultiplier;
        }

        return (int) round($totalPoints);
    }
}
