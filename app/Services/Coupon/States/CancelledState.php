<?php

namespace App\Services\Coupon\States;

use App\Models\UserCoupon;
use RuntimeException;

class CancelledState extends CouponState
{
    public function __construct(UserCoupon $coupon)
    {
        parent::__construct($coupon);
    }

    /**
     * 已取消的優惠券無法執行任何操作
     */
    public function claim(): string
    {
        throw new RuntimeException('已取消的優惠券無法領取');
    }

    public function redeem(): string
    {
        throw new RuntimeException('已取消的優惠券無法核銷');
    }

    public function expire(): string
    {
        throw new RuntimeException('已取消的優惠券無法標記為過期');
    }

    public function cancel(): string
    {
        throw new RuntimeException('優惠券已經是取消狀態');
    }

    public function getStatus(): string
    {
        return UserCoupon::STATUS_CANCELLED;
    }
}
