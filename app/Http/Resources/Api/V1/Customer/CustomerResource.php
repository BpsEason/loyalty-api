<?php

namespace App\Http\Resources\Api\V1\Customer;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Customer",
    title: "Customer",
    description: "Customer model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "tenant_id", type: "integer", example: 1, nullable: true),
        new OA\Property(property: "name", type: "string", example: "John Doe"),
        new OA\Property(property: "email", type: "string", format: "email", example: "john@example.com"),
        new OA\Property(property: "phone", type: "string", example: "+1234567890", nullable: true),
        new OA\Property(property: "metadata", type: "object", nullable: true),
        new OA\Property(
            property: "tenant",
            type: "object",
            nullable: true,
            properties: [
                new OA\Property(property: "id", type: "integer", example: 1),
                new OA\Property(property: "name", type: "string", example: "Acme Corp"),
                new OA\Property(property: "domain", type: "string", example: "acme.example.com", nullable: true),
            ]
        ),
        new OA\Property(property: "member_code", type: "string", example: "M001001", nullable: true),
        new OA\Property(property: "qr_token", type: "string", example: "abc123xyz...", nullable: true),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time", example: "2026-09-15T00:00:00Z"),
    ]
)]
class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'metadata' => $this->metadata,
            'member_code' => $this->member_code,
            'qr_token' => $this->qr_token,
            'tenant' => $this->when($this->relationLoaded('tenant'), [
                'id' => $this->tenant?->id,
                'name' => $this->tenant?->name,
                'domain' => $this->tenant?->domain,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
