<?php

namespace App\Filament\Resources\PointTransactionResource\Pages;

use App\Filament\Resources\PointTransactionResource;
use App\Models\PointAccount;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditPointTransaction extends EditRecord
{
    protected static string $resource = PointTransactionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();

        // Tenant Admin 永遠無法修改tenant_id
        if ($user->hasRole('tenant_admin')) {
            $data['tenant_id'] = $user->tenant_id;
        }

        // 永遠驗證PointAccount屬於同一租戶
        $pointAccount = PointAccount::findOrFail($data['point_account_id']);
        if ($pointAccount->tenant_id !== $data['tenant_id']) {
            throw ValidationException::withMessages([
                'point_account_id' => '選擇的點數帳戶不屬於所選租戶',
            ]);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
