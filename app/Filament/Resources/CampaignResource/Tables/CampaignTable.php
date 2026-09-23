<?php

namespace App\Filament\Resources\CampaignResource\Tables;

use App\Models\Campaign;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CampaignTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('活動名稱')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        Campaign::STATUS_DRAFT => 'gray',
                        Campaign::STATUS_ACTIVE => 'success',
                        Campaign::STATUS_INACTIVE => 'warning',
                        Campaign::STATUS_COMPLETED => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '啟用',
                        Campaign::STATUS_INACTIVE => '停用',
                        Campaign::STATUS_COMPLETED => '已完成',
                        default => '未知',
                    }),

                TextColumn::make('starts_at')
                    ->label('開始時間')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('結束時間')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()?->isSuperAdmin()),

                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('更新時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '啟用',
                        Campaign::STATUS_INACTIVE => '停用',
                        Campaign::STATUS_COMPLETED => '已完成',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
