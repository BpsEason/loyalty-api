<?php

namespace App\Filament\Resources\CampaignRewardResource\Tables;

use App\Models\CampaignReward;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CampaignRewardTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('campaign.name')
                    ->label('活動')
                    ->searchable()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold),
                TextColumn::make('reward_type')
                    ->label('獎勵類型')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        CampaignReward::TYPE_POINTS => 'success',
                        CampaignReward::TYPE_BADGE => 'warning',
                        CampaignReward::TYPE_COUPON => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        CampaignReward::TYPE_POINTS => '點數',
                        CampaignReward::TYPE_BADGE => '徽章',
                        CampaignReward::TYPE_COUPON => '優惠券',
                        default => $state,
                    }),
                TextColumn::make('points')
                    ->label('點數數量')
                    ->numeric()
                    ->sortable()
                    ->alignRight()
                    ->weight(\Filament\Support\Enums\FontWeight::Medium),
                IconColumn::make('enabled')
                    ->label('狀態')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                TextColumn::make('campaign.tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()?->isSuperAdmin()),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('reward_type')
                    ->label('篩選獎勵類型')
                    ->placeholder('全部類型')
                    ->options([
                        CampaignReward::TYPE_POINTS => '點數',
                        CampaignReward::TYPE_BADGE => '徽章',
                        CampaignReward::TYPE_COUPON => '優惠券',
                    ]),
                TernaryFilter::make('enabled')
                    ->label('篩選啟用狀態')
                    ->placeholder('全部狀態')
                    ->trueLabel('已啟用')
                    ->falseLabel('已停用'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }
}
