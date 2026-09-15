<?php

namespace App\Filament\Resources\PointAccountResource\Pages;

use App\Filament\Resources\PointAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPointAccounts extends ListRecords
{
    protected static string $resource = PointAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
