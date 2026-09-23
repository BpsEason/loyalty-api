<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $isCurrentUser = $this->id === $request->user()?->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tenant_id' => $this->tenant_id,
            'roles' => $this->when($isCurrentUser, $this->roles->pluck('name')),
            'permissions' => $this->when($isCurrentUser, $this->getAllPermissions()->pluck('name')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
