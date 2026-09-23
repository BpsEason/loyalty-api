<?php

namespace App\Http\Resources\Api\V1\PointAccount;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PointAccount",
    title: "PointAccount",
    description: "Point account model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "tenant_id", type: "integer", example: 1),
        new OA\Property(property: "customer_id", type: "integer", example: 1),
        new OA\Property(property: "balance", type: "integer", example: 1500),
        new OA\Property(property: "total_earned", type: "integer", example: 2000),
        new OA\Property(property: "total_redeemed", type: "integer", example: 500),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
    ]
)]
class PointAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'balance' => $this->balance,
            'total_earned' => $this->total_earned,
            'total_redeemed' => $this->total_redeemed,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
