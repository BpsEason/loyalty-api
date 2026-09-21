<?php

namespace App\Http\Resources\Api\V1\Coupon;

use Illuminate\Http\Resources\Json\JsonResource;

class CouponRedemptionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\CouponRedemption $this */
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'order_reference' => $this->order_reference,
            'discount_amount' => $this->discount_amount,
            'redeemed_at' => $this->redeemed_at->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
