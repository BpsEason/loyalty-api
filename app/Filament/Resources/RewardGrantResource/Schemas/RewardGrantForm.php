<?php

namespace App\Filament\Resources\RewardGrantResource\Schemas;

use App\Models\RewardGrant;
use Filament\Forms;
use Filament\Schemas\Schema;

class RewardGrantForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \App\Forms\Components\TenantSelect::make(),
                Forms\Components\Select::make('campaign_id')
                    ->label('活動')
                    ->relationship('campaign', 'name', function ($query, $get) {
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

                        return $query;
                    })
                    ->required()
                    ->disabled(fn($record) => $record !== null)
                    ->reactive(),
                Forms\Components\Select::make('campaign_reward_id')
                    ->label('獎勵')
                    ->relationship('campaignReward', 'id', function ($query, $get) {
                        $campaignId = $get('campaign_id');
                        if ($campaignId) {
                            $query->where('campaign_id', $campaignId);
                        }
                        return $query;
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => "{$record->id} - {$record->reward_type}")
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('customer_id')
                    ->label('客戶')
                    ->relationship('customer', 'name', function ($query, $get) {
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

                        return $query;
                    })
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('status')
                    ->label('狀態')
                    ->options([
                        RewardGrant::STATUS_PENDING => '待處理',
                        RewardGrant::STATUS_GRANTED => '已發放',
                        RewardGrant::STATUS_FAILED => '失敗',
                    ])
                    ->required()
                    ->disabled(),
                Forms\Components\DateTimePicker::make('granted_at')
                    ->label('發放時間')
                    ->disabled(),
                Forms\Components\Textarea::make('failure_reason')
                    ->label('失敗原因')
                    ->columnSpanFull()
                    ->disabled(),
                Forms\Components\Select::make('point_transaction_id')
                    ->label('點數交易')
                    ->relationship('pointTransaction', 'id', function ($query, $get) {
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

                        return $query;
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => "交易 #{$record->id} - {$record->amount}點")
                    ->disabled(),
            ]);
    }
}
