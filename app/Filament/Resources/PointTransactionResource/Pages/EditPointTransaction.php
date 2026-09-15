<?php

namespace App\Filament\Resources\PointTransactionResource\Pages;

use App\Filament\Resources\PointTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPointTransaction extends EditRecord
{
    protected static string $resource = PointTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
