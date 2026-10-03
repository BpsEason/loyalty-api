<?php

namespace App\Services\Coupon\States;

use App\Models\UserCoupon;
use RuntimeException;

class ExpiredState extends CouponState
{
    public function __construct(UserCoupon $coupon)
    {
        parent::__construct($coupon);
    }

    /**
     * 已過期的優惠券無法執行任何操作
     */
    public function claim(): string
    {
        throw new RuntimeException('已過期的優惠券無法領取');
    }

    public function redeem(): string
    {
        throw new RuntimeException('已過期的優惠券無法核銷');
    }

    public function expire(): string
    {
        throw new RuntimeException('優惠券已經是過期狀態');
    }

    public function cancel(): string
    {
        throw new RuntimeException('已過期的優惠券無法取消');
    }

    public function getStatus(): string
    {
        return UserCoupon::STATUS_EXPIRED;
    }
}
