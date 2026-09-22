<?php

namespace App\Http\Resources\Api\V1\PointTransaction;

use Illuminate\Http\Resources\Json\JsonResource;

class PointLotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'original_points' => $this->original_points,
            'remaining_points' => $this->remaining_points,
            'earned_at' => $this->earned_at?->toISOString(),
            'expired_at' => $this->expired_at?->toISOString(),
            'days_until_expiry' => $this->expired_at ? now()->diffInDays($this->expired_at, false) : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
