<?php

namespace App\Http\Resources\Api\V1\PointTransaction;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "PointTransaction",
    title: "PointTransaction",
    description: "Point transaction model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "tenant_id", type: "integer", example: 1),
        new OA\Property(property: "customer_id", type: "integer", example: 1),
        new OA\Property(property: "point_account_id", type: "integer", example: 1),
        new OA\Property(property: "type", type: "string", example: "earn"),
        new OA\Property(property: "amount", type: "integer", example: 100),
        new OA\Property(property: "balance_before", type: "integer", example: 900),
        new OA\Property(property: "balance_after", type: "integer", example: 1000),
        new OA\Property(property: "reference_type", type: "string", nullable: true, example: null),
        new OA\Property(property: "reference_id", type: "integer", nullable: true, example: null),
        new OA\Property(property: "description", type: "string", nullable: true, example: "Purchase reward"),
        new OA\Property(property: "created_by", type: "integer", nullable: true, example: 1),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
    ]
)]
class PointTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'customer_id' => $this->customer_id,
            'point_account_id' => $this->point_account_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
