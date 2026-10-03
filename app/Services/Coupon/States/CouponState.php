<?php

namespace App\Services\Coupon\States;

use App\Models\UserCoupon;
use RuntimeException;

abstract class CouponState
{
    public function __construct(protected UserCoupon $coupon) {}

    /**
     * 領取優惠券（進入claimed狀態）
     */
    public function claim(): string
    {
        throw new RuntimeException('目前狀態無法執行領取操作');
    }

    /**
     * 核銷優惠券（進入redeemed狀態）
     */
    public function redeem(): string
    {
        throw new RuntimeException('目前狀態無法執行核銷操作');
    }

    /**
     * 過期優惠券（進入expired狀態）
     */
    public function expire(): string
    {
        throw new RuntimeException('目前狀態無法執行過期操作');
    }

    /**
     * 取消優惠券（進入cancelled狀態）
     */
    public function cancel(): string
    {
        throw new RuntimeException('目前狀態無法執行取消操作');
    }

    /**
     * 取得當前狀態名稱
     */
    abstract public function getStatus(): string;
}
