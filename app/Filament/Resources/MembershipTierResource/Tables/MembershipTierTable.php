<?php

namespace App\Filament\Resources\MembershipTierResource\Tables;

use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MembershipTierTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('等級名稱')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('標識符')
                    ->searchable(),
                TextColumn::make('threshold_type')
                    ->label('門檻類型')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'spend' => '累積消費',
                        'points' => '累積點數',
                        default => $state,
                    }),
                TextColumn::make('upgrade_threshold')
                    ->label('升級門檻')
                    ->sortable(),
                IconColumn::make('status')
                    ->label('狀態')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('threshold_type')
                    ->label('門檻類型')
                    ->options([
                        'spend' => '累積消費',
                        'points' => '累積點數',
                    ]),
                TernaryFilter::make('status')
                    ->label('啟用狀態'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
