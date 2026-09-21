<?php

namespace App\Http\Resources\Api\V1\Coupon;

use App\Models\CouponTemplate;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "UserCoupon",
    title: "UserCoupon",
    description: "User coupon model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "status", type: "string", example: "available"),
        new OA\Property(property: "issued_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z", nullable: true),
        new OA\Property(property: "used_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z", nullable: true),
        new OA\Property(property: "expired_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z", nullable: true),
        new OA\Property(
            property: "template",
            ref: "#/components/schemas/CouponTemplate",
            nullable: true
        ),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
    ]
)]
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
