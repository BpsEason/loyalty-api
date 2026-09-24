<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CouponRedeemed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $customerId,
        public readonly int $couponRedemptionId,
        public readonly int $userCouponId,
        public readonly int $discountAmount,
        public readonly mixed $reference,
        public readonly string $occurredAt
    ) {}

    public function getEventId(): string
    {
        return sprintf('coupon_redeemed_%d', $this->couponRedemptionId);
    }

    public function getAggregateType(): string
    {
        return 'coupon_redemption';
    }

    public function getAggregateId(): string
    {
        return (string) $this->couponRedemptionId;
    }

    public function getEventType(): string
    {
        return 'CouponRedeemed';
    }

    public function toPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->customerId,
            'coupon_redemption_id' => $this->couponRedemptionId,
            'user_coupon_id' => $this->userCouponId,
            'discount_amount' => $this->discountAmount,
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
