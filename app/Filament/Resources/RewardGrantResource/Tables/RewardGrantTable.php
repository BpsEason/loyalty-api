<?php

namespace App\Filament\Resources\RewardGrantResource\Tables;

use App\Models\RewardGrant;
use Filament\Tables;
use Filament\Tables\Table;

class RewardGrantTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('活動')
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('客戶')
                    ->searchable(),
                Tables\Columns\TextColumn::make('campaignReward.reward_type')
                    ->label('獎勵類型')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        RewardGrant::STATUS_PENDING => 'warning',
                        RewardGrant::STATUS_GRANTED => 'success',
                        RewardGrant::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('granted_at')
                    ->label('發放時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pointTransaction.id')
                    ->label('交易序號')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        RewardGrant::STATUS_PENDING => '待處理',
                        RewardGrant::STATUS_GRANTED => '已發放',
                        RewardGrant::STATUS_FAILED => '失敗',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
