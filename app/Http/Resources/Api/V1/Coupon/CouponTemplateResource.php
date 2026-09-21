<?php

namespace App\Http\Resources\Api\V1\Coupon;

use Illuminate\Http\Resources\Json\JsonResource;

class CouponTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\CouponTemplate $this */
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'discount_amount' => $this->discount_amount,
            'discount_percentage' => $this->discount_percentage,
            'max_discount_amount' => $this->max_discount_amount,
            'minimum_order_amount' => $this->minimum_order_amount,
            'starts_at' => $this->starts_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'status' => $this->status,
        ];
    }
}
