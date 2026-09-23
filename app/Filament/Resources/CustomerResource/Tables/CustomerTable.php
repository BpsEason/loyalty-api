<?php

namespace App\Filament\Resources\CustomerResource\Tables;

use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('客戶')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->icon('heroicon-o-user')
                    ->description(fn(Customer $record): string => $record->email)
                    ->wrap(),

                TextColumn::make('metadata.tier')
                    ->label('會員等級')
                    ->alignCenter()
                    ->badge()
                    ->color(fn(string $state): string => match (strtolower($state)) {
                        'platinum' => 'warning',
                        'gold' => 'warning',
                        'silver' => 'info',
                        'bronze' => 'secondary',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn(string $state): string => match (strtolower($state)) {
                        'platinum' => '白金會員',
                        'gold' => '黃金會員',
                        'silver' => '白銀會員',
                        'bronze' => '青銅會員',
                        default => $state,
                    })
                    ->icon(fn(string $state): string => match (strtolower($state)) {
                        'platinum' => 'heroicon-o-trophy',
                        'gold' => 'heroicon-o-star',
                        'silver' => 'heroicon-o-academic-cap',
                        'bronze' => 'heroicon-o-user',
                        default => 'heroicon-o-user',
                    }),

                TextColumn::make('total_points')
                    ->label('目前點數')
                    ->getStateUsing(fn(Customer $record) => number_format($record->pointAccounts?->balance ?? 0))
                    ->sortable()
                    ->alignRight()
                    ->weight(FontWeight::Bold)
                    ->color('primary')
                    ->icon('heroicon-o-currency-dollar'),

                TextColumn::make('last_activity')
                    ->label('最後活動')
                    ->getStateUsing(function (Customer $record) {
                        if (!$record->pointAccounts) {
                            return '無活動記錄';
                        }
                        // 使用已eager load的pointTransactions，避免重複查詢
                        $lastTransaction = $record->pointAccounts->pointTransactions->sortByDesc('created_at')->first();
                        return $lastTransaction?->created_at?->diffForHumans() ?? '無活動記錄';
                    })
                    ->color('gray')
                    ->icon('heroicon-o-clock')
                    ->alignCenter(),

                TextColumn::make('tenant.name')
                    ->label('所屬租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()?->isSuperAdmin())
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-building-office'),

                TextColumn::make('created_at')
                    ->label('加入時間')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->color('gray')
                    ->icon('heroicon-o-calendar')
                    ->alignRight(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tier')
                    ->label('會員等級')
                    ->options([
                        'platinum' => '白金會員',
                        'gold' => '黃金會員',
                        'silver' => '白銀會員',
                        'bronze' => '青銅會員',
                    ])
                    ->query(function (Builder $query, $data) {
                        if (filled($data['value'])) {
                            $query->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.tier")) = ?', [$data['value']]);
                        }
                    }),
            ])
            ->actions([
                EditAction::make()->icon('heroicon-o-pencil'),
                DeleteAction::make()->icon('heroicon-o-trash'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
