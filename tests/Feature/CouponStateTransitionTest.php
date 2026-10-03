<?php

namespace Tests\Feature;

use App\Models\UserCoupon;
use Tests\TestCase;

class CouponStateTransitionTest extends TestCase
{
    /** @test */
    public function available_coupon_can_be_redeemed()
    {
        $userCoupon = new UserCoupon();
        $userCoupon->status = UserCoupon::STATUS_AVAILABLE;

        $newStatus = $userCoupon->state()->redeem();

        $this->assertEquals(UserCoupon::STATUS_USED, $newStatus);
    }

    /** @test */
    public function available_coupon_can_be_expired()
    {
        $userCoupon = new UserCoupon();
        $userCoupon->status = UserCoupon::STATUS_AVAILABLE;

        $newStatus = $userCoupon->state()->expire();

        $this->assertEquals(UserCoupon::STATUS_EXPIRED, $newStatus);
    }

    /** @test */
    public function used_coupon_cannot_be_redeemed_again()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已使用的優惠券無法重複核銷');

        $userCoupon = new UserCoupon();
        $userCoupon->status = UserCoupon::STATUS_USED;

        $userCoupon->state()->redeem();
    }

    /** @test */
    public function expired_coupon_cannot_be_redeemed()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已過期的優惠券無法核銷');

        $userCoupon = new UserCoupon();
        $userCoupon->status = UserCoupon::STATUS_EXPIRED;

        $userCoupon->state()->redeem();
    }

    /** @test */
    public function used_coupon_cannot_be_expired()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已使用的優惠券無法標記為過期');

        $userCoupon = new UserCoupon();
        $userCoupon->status = UserCoupon::STATUS_USED;

        $userCoupon->state()->expire();
    }

    /** @test */
    public function cancelled_coupon_cannot_be_redeemed()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('已取消的優惠券無法核銷');

        $userCoupon = new UserCoupon();
        $userCoupon->status = UserCoupon::STATUS_CANCELLED;

        $userCoupon->state()->redeem();
    }
}
