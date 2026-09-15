<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Tenant Admin 只能建立自己租戶的使用者
        if (auth()->user()->hasRole('tenant_admin')) {
            $data['tenant_id'] = auth()->user()->tenant_id;
        }

        return $data;
    }
}
