<?php

namespace App\Filament\Resources\CouponTemplateResource\Pages;

use App\Filament\Resources\CouponTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCouponTemplate extends EditRecord
{
    protected static string $resource = CouponTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
