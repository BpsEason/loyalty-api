<?php

namespace App\Http\Resources\Api\V1\Coupon;

use App\Models\CouponTemplate;
use Illuminate\Http\Resources\Json\JsonResource;

class UserCouponResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\UserCoupon $this */
        return [
            'id' => $this->id,
            'status' => $this->status,
            'issued_at' => $this->issued_at?->toISOString(),
            'used_at' => $this->used_at?->toISOString(),
            'expired_at' => $this->expired_at?->toISOString(),
            'template' => new CouponTemplateResource($this->whenLoaded('couponTemplate')),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
