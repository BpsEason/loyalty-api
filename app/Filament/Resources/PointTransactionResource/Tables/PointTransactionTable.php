<?php

namespace App\Filament\Resources\PointTransactionResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PointTransactionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->query(\App\Filament\Resources\PointTransactionResource::getEloquentQuery())
            ->columns([
                TextColumn::make('pointAccount.customer.name')
                    ->label('客戶')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('金額')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('類型')
                    ->badge(),
                TextColumn::make('description')
                    ->label('描述')
                    ->limit(50),
                TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('類型')
                    ->options([
                        'earn' => '獲得',
                        'redeem' => '兌換',
                        'expire' => '過期',
                        'adjust' => '調整',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
