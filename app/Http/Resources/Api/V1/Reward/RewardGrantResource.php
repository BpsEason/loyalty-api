<?php

namespace App\Http\Resources\Api\V1\Reward;

use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "RewardGrant",
    title: "RewardGrant",
    description: "Reward grant model",
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "status", type: "string", example: "granted", enum: ["pending", "granted", "failed"]),
        new OA\Property(property: "campaign_id", type: "integer", example: 1),
        new OA\Property(property: "campaign_reward_id", type: "integer", example: 1),
        new OA\Property(property: "failure_reason", type: "string", nullable: true, example: null),
        new OA\Property(property: "granted_at", type: "string", format: "date-time", example: "2026-09-15T12:00:00Z"),
        new OA\Property(property: "created_at", type: "string", format: "date-time", example: "2026-09-15T12:00:00Z"),
    ]
)]
class RewardGrantResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\RewardGrant $this */
        return [
            'id' => $this->id,
            'status' => $this->status,
            'campaign_id' => $this->campaign_id,
            'campaign_reward_id' => $this->campaign_reward_id,
            'failure_reason' => $this->failure_reason,
            'granted_at' => $this->granted_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
