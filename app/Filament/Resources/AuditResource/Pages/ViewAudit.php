<?php

namespace App\Filament\Resources\AuditResource\Pages;

use App\Filament\Resources\AuditResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\KeyValueEntry;

class ViewAudit extends ViewRecord
{
    protected static string $resource = AuditResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->record($this->record)
            ->components([
                Section::make('基本資訊')
                    ->description('審計日誌的核心識別資訊，記錄操作的基本背景')
                    ->schema([
                        TextEntry::make('id')
                            ->label('記錄ID')
                            ->copyable()
                            ->fontFamily('monospace')
                            ->weight(FontWeight::Bold),
                        Grid::make(4)->schema([
                            TextEntry::make('event')
                                ->label('操作類型')
                                ->badge()
                                ->color(fn(string $state): string => match ($state) {
                                    'created' => 'success',
                                    'updated' => 'warning',
                                    'deleted' => 'danger',
                                    'restored' => 'info',
                                    default => 'gray',
                                })
                                ->icon(fn(string $state): string => match ($state) {
                                    'created' => 'heroicon-o-plus-circle',
                                    'updated' => 'heroicon-o-pencil',
                                    'deleted' => 'heroicon-o-trash',
                                    'restored' => 'heroicon-o-arrow-path',
                                    default => 'heroicon-o-circle',
                                })
                                ->formatStateUsing(fn(string $state): string => match ($state) {
                                    'created' => '創建',
                                    'updated' => '更新',
                                    'deleted' => '刪除',
                                    'restored' => '恢復',
                                    default => $state,
                                }),
                            TextEntry::make('auditable_type')
                                ->label('操作對象')
                                ->badge()
                                ->color('gray')
                                ->icon('heroicon-o-cube')
                                ->formatStateUsing(fn(string $state): string => class_basename($state)),
                            TextEntry::make('auditable_id')
                                ->label('對象ID')
                                ->copyable()
                                ->fontFamily('monospace'),
                            TextEntry::make('created_at')
                                ->label('操作時間')
                                ->dateTime()
                                ->icon('heroicon-o-clock'),
                        ]),
                        Grid::make(3)->schema([
                            TextEntry::make('tenant.name')
                                ->label('租戶')
                                ->icon('heroicon-o-building-office')
                                ->placeholder('-'),
                            TextEntry::make('user.name')
                                ->label('操作人')
                                ->icon('heroicon-o-user')
                                ->placeholder('-'),
                            TextEntry::make('ip_address')
                                ->label('IP地址')
                                ->icon('heroicon-o-globe-alt')
                                ->placeholder('-'),
                        ]),
                        TextEntry::make('user_agent')
                            ->label('瀏覽器資訊')
                            ->columnSpanFull()
                            ->placeholder('-')
                            ->wrap(),
                        TextEntry::make('url')
                            ->label('訪問URL')
                            ->columnSpanFull()
                            ->placeholder('-')
                            ->copyable(),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('變更內容')
                    ->description('記錄此操作前後的屬性變更，僅有變更的欄位會顯示')
                    ->schema([
                        Grid::make(2)->schema([
                            KeyValueEntry::make('old_values')
                                ->label('修改前')
                                ->columnSpanFull()
                                ->placeholder('無變更記錄'),
                            KeyValueEntry::make('new_values')
                                ->label('修改後')
                                ->columnSpanFull()
                                ->placeholder('無變更記錄'),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }
}
