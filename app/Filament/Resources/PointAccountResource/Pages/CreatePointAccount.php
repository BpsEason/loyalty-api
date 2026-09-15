<?php

namespace App\Filament\Resources\PointAccountResource\Pages;

use App\Filament\Resources\PointAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePointAccount extends CreateRecord
{
    protected static string $resource = PointAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Tenant Admin 只能建立自己租戶的積分帳戶
        if (auth()->user()->hasRole('tenant_admin')) {
            $data['tenant_id'] = auth()->user()->tenant_id;
        }

        return $data;
    }
}
