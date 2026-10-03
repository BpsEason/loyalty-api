<?php

namespace App\Services\Point\Strategies;

use App\Models\Customer;

interface PointEarnStrategy
{
    /**
     * 計算應該獲得的點數
     * 
     * @param Customer $customer 客戶實例
     * @param int|float $baseAmount 基礎金額或點數，依策略而定
     * @param array $context 額外的上下文資訊
     * @return int 計算後的點數
     */
    public function calculate(Customer $customer, int|float $baseAmount, array $context = []): int;
}
