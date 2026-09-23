<?php

namespace App\Filament\Resources\PointTransactionResource\Schemas;

use App\Forms\Components\TenantSelect;
use Filament\Forms;
use Filament\Schemas\Schema;

class PointTransactionForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TenantSelect::make(),
                Forms\Components\Select::make('point_account_id')
                    ->label('點數帳戶')
                    ->relationship(
                        'pointAccount',
                        'id',
                        function ($query, callable $get) {
                            $user = auth()->user();
                            $panel = filament()->getCurrentOrDefaultPanel();
                            $tenantId = $get('tenant_id');

                            if ($user && $user->isSuperAdmin()) {
                                if ($panel?->hasTenancy()) {
                                    $query->withoutGlobalScope($panel->getTenancyScopeName());
                                }
                                if ($tenantId) {
                                    $query->where('tenant_id', $tenantId);
                                }
                            }
                            // Tenant Admin 由Model全域範圍自動處理，無需手動過濾

                            return $query->select('id', 'customer_id');
                        }
                    )
                    ->getOptionLabelFromRecordUsing(fn($record) => "帳戶 #{$record->id} - {$record->customer->name}")
                    ->required()
                    ->searchable()
                    ->reactive(),
                Forms\Components\TextInput::make('amount')
                    ->label('金額')
                    ->required()
                    ->numeric()
                    ->disabled(fn($context) => $context !== 'create'),
                Forms\Components\TextInput::make('type')
                    ->label('類型')
                    ->required()
                    ->maxLength(50)
                    ->disabled(fn($context) => $context !== 'create'),
                Forms\Components\Textarea::make('description')
                    ->label('描述')
                    ->maxLength(65535)
                    ->columnSpanFull()
                    ->disabled(fn($context) => $context !== 'create'),
                Forms\Components\KeyValue::make('metadata')
                    ->label('中繼資料')
                    ->disabled(true),
            ]);
    }
}
