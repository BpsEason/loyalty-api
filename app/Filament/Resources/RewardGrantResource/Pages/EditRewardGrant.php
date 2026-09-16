<?php

namespace App\Filament\Resources\RewardGrantResource\Pages;

use App\Filament\Resources\RewardGrantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRewardGrant extends EditRecord
{
    protected static string $resource = RewardGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
