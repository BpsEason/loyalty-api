<?php

namespace App\Services\Point\Strategies;

use App\Models\Customer;

class ReferralEarnStrategy implements PointEarnStrategy
{
    /**
     * 預設的推薦獎勵點數
     */
    protected int $defaultReferralBonus = 500;

    /**
     * 推薦成功時給予固定的推薦獎勵點數
     * baseAmount參數在此策略中可用來覆寫預設的獎勵點數
     */
    public function calculate(Customer $customer, int|float $baseAmount, array $context = []): int
    {
        // 如果傳入的baseAmount大於0，使用它作為獎勵點數，否則使用預設值
        $referralBonus = $baseAmount > 0 ? (int) $baseAmount : $this->defaultReferralBonus;

        // 從上下文取得是否有額外的推薦活動加成
        $referralMultiplier = $context['referral_multiplier'] ?? 1.0;

        // 計算最終的推薦獎勵點數
        $totalPoints = $referralBonus * $referralMultiplier;

        return (int) round($totalPoints);
    }
}
