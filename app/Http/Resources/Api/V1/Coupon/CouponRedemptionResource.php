<?php

namespace App\Http\Resources\Api\V1\Coupon;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "CouponRedemption",
    title: "CouponRedemption",
    description: "Coupon redemption model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "reference", type: "string", example: "RED-20260915-001", nullable: true),
        new OA\Property(property: "order_reference", type: "string", example: "ORD-20260915-001", nullable: true),
        new OA\Property(property: "discount_amount", type: "integer", example: 500),
        new OA\Property(property: "redeemed_at", type: "string", format: "date-time", example: "2026-09-15T12:00:00Z"),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-09-15T12:00:00Z"),
    ]
)]
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