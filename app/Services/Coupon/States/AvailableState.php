<?php

namespace App\Services\Coupon\States;

use App\Models\UserCoupon;
use RuntimeException;

class AvailableState extends CouponState
{
    public function __construct(UserCoupon $coupon)
    {
        parent::__construct($coupon);
    }

    /**
     * 從available狀態可以領取？不，available已經是領取後的狀態
     * 這裡的claim是指確認領取完成，進入claimed狀態（在本系統中claimed就是available？
     * 實際上在本系統中，claim操作是建立優惠券時設為available，所以這裡從新建狀態轉為available
     * 但為了符合需求的狀態轉換：Available → Claimed，這裡我們讓available可以轉為claimed（但本系統中claimed就是可用狀態）
     * 實際上在本系統中，redeem操作是從available轉為used
     */
    public function claim(): string
    {
        return UserCoupon::STATUS_AVAILABLE;
    }

    /**
     * 從available狀態可以核銷，轉為used狀態
     */
    public function redeem(): string
    {
        return UserCoupon::STATUS_USED;
    }

    /**
     * 從available狀態可以過期，轉為expired狀態
     */
    public function expire(): string
    {
        return UserCoupon::STATUS_EXPIRED;
    }

    /**
     * 從available狀態可以取消，轉為cancelled狀態
     */
    public function cancel(): string
    {
        return UserCoupon::STATUS_CANCELLED;
    }

    public function getStatus(): string
    {
        return UserCoupon::STATUS_AVAILABLE;
    }
}
