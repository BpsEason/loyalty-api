<?php

namespace App\Filament\Resources\PointTransactionResource\Pages;

use App\Filament\Resources\PointTransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePointTransaction extends CreateRecord
{
    protected static string $resource = PointTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (auth()->user()->hasRole('tenant_admin')) {
            $data['tenant_id'] = auth()->user()->tenant_id;
        }

        return $data;
    }
}
