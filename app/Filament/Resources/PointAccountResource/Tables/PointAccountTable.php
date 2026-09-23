<?php

namespace App\Filament\Resources\PointAccountResource\Tables;

use App\Models\PointAccount;
use Filament\Tables;
use Filament\Tables\Table;

class PointAccountTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->query(\App\Filament\Resources\PointAccountResource::getEloquentQuery())
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('客戶')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn(PointAccount $record) => $record->tenant?->name ?? ''),
                Tables\Columns\BadgeColumn::make('balance')
                    ->label('目前餘額')
                    ->numeric()
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->formatStateUsing(fn($state) => number_format($state) . ' 點')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('total_earned')
                    ->label('累積獲得')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn($state) => number_format($state) . ' 點')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('total_redeemed')
                    ->label('累積兌換')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn($state) => number_format($state) . ' 點')
                    ->alignRight(),
                Tables\Columns\BadgeColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin'))
                    ->color('info'),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->tooltip('編輯點數帳戶')
                    ->icon('heroicon-o-pencil'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }
}
