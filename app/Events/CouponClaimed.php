<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CouponClaimed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $customerId,
        public readonly int $userCouponId,
        public readonly int $couponTemplateId,
        public readonly string $occurredAt
    ) {}

    public function getEventId(): string
    {
        return sprintf('coupon_claimed_%d', $this->userCouponId);
    }

    public function getAggregateType(): string
    {
        return 'user_coupon';
    }

    public function getAggregateId(): string
    {
        return (string) $this->userCouponId;
    }

    public function getEventType(): string
    {
        return 'CouponClaimed';
    }

    public function toPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->customerId,
            'user_coupon_id' => $this->userCouponId,
            'coupon_template_id' => $this->couponTemplateId,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
