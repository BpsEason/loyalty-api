<?php

namespace App\Http\Resources\Api\V1\Coupon;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "CouponTemplate",
    title: "CouponTemplate",
    description: "Coupon template model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "name", type: "string", example: "Summer Sale 10% Off"),
        new OA\Property(property: "code", type: "string", example: "SUMMER10"),
        new OA\Property(property: "type", type: "string", example: "percentage"),
        new OA\Property(property: "discount_amount", type: "integer", example: null, nullable: true),
        new OA\Property(property: "discount_percentage", type: "integer", example: 10, nullable: true),
        new OA\Property(property: "max_discount_amount", type: "integer", example: 500, nullable: true),
        new OA\Property(property: "minimum_order_amount", type: "integer", example: 1000),
        new OA\Property(property: "starts_at", type: "string", format: "date-time", example: "2026-09-01T00:00:00Z", nullable: true),
        new OA\Property(property: "expires_at", type: "string", format: "date-time", example: "2026-09-30T23:59:59Z", nullable: true),
        new OA\Property(property: "status", type: "string", example: "active"),
    ]
)]
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
