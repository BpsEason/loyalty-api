<?php

namespace App\Filament\Resources\TenantResource\Tables;

use App\Models\Tenant;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Table;

class TenantTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('租戶名稱')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn(Tenant $record) => $record->domain),
                TextColumn::make('domain')
                    ->label('網域')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                BadgeColumn::make('is_active')
                    ->label('狀態')
                    ->getStateUsing(fn(Tenant $record): string => $record->is_active ? '啟用' : '停用')
                    ->colors([
                        'success' => '啟用',
                        'danger' => '停用',
                    ])
                    ->sortable(),
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
                //
            ])
            ->actions([
                EditAction::make()
                    ->tooltip('編輯租戶')
                    ->icon('heroicon-o-pencil'),
                DeleteAction::make()
                    ->tooltip('刪除租戶')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }
}
