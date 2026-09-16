<?php

namespace App\Filament\Resources\CampaignRewardResource\Pages;

use App\Filament\Resources\CampaignRewardResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCampaignReward extends EditRecord
{
    protected static string $resource = CampaignRewardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
