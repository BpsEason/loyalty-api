<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Tenant Admin 只能建立自己租戶的客戶
        if (auth()->user()->hasRole('tenant_admin')) {
            $data['tenant_id'] = auth()->user()->tenant_id;
        }

        return $data;
    }
}
