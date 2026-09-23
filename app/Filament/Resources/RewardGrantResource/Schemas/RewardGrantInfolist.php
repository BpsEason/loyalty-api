<?php

namespace App\Filament\Resources\RewardGrantResource\Schemas;

use App\Models\RewardGrant;
use Filament\Infolists;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class RewardGrantInfolist
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('獎勵發放結果')
                    ->description('此筆獎勵的核心發放資訊摘要')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make()
                            ->schema([
                                Infolists\Components\TextEntry::make('status')
                                    ->label('發放狀態')
                                    ->badge()
                                    ->size('lg')
                                    ->alignCenter()
                                    ->color(fn(string $state): string => match ($state) {
                                        RewardGrant::STATUS_PENDING => 'warning',
                                        RewardGrant::STATUS_GRANTED => 'success',
                                        RewardGrant::STATUS_FAILED => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn(string $state): string => match ($state) {
                                        RewardGrant::STATUS_PENDING => '待處理',
                                        RewardGrant::STATUS_GRANTED => '已發放',
                                        RewardGrant::STATUS_FAILED => '失敗',
                                        default => $state,
                                    })
                                    ->columnSpanFull(),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('customer.name')
                                    ->label('客戶')
                                    ->icon('heroicon-m-user')
                                    ->iconColor('gray')
                                    ->size('xl')
                                    ->weight(FontWeight::Bold),
                                Infolists\Components\TextEntry::make('campaign.name')
                                    ->label('活動')
                                    ->icon('heroicon-m-megaphone')
                                    ->iconColor('gray')
                                    ->size('xl')
                                    ->weight(FontWeight::Bold),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('campaignReward.reward_type')
                                    ->label('獎勵類型')
                                    ->icon('heroicon-m-gift')
                                    ->iconColor('primary')
                                    ->badge()
                                    ->color(fn(string|null $state): string => match ($state) {
                                        'points' => 'info',
                                        'badge' => 'success',
                                        'coupon' => 'warning',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn(string|null $state): string => match ($state) {
                                        'points' => '點數',
                                        'badge' => '徽章',
                                        'coupon' => '優惠券',
                                        default => (string)$state,
                                    }),
                                Infolists\Components\TextEntry::make('granted_at')
                                    ->label('發放時間')
                                    ->icon('heroicon-m-clock')
                                    ->iconColor('gray')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsible(false),

                Section::make('發放內容')
                    ->description('獎勵關聯的項目資訊')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(1)
                            ->schema([
                                Infolists\Components\TextEntry::make('campaignReward.id')
                                    ->label('獎勵項目')
                                    ->icon('heroicon-m-tag')
                                    ->iconColor('gray')
                                    ->helperText('此獎勵來源的系統編號')
                                    ->formatStateUsing(fn(int $state): string => "#{$state}"),
                            ]),
                    ])
                    ->collapsible(false),

                Section::make('發放結果')
                    ->description('獎勵發放成功後產生的點數交易結果')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('pointTransaction.amount')
                                    ->label('點數異動')
                                    ->size('4xl')
                                    ->weight(FontWeight::Bold)
                                    ->color('success')
                                    ->alignCenter()
                                    ->formatStateUsing(function ($state, $record) {
                                        if ($record->status !== RewardGrant::STATUS_GRANTED || !$record->pointTransaction) {
                                            return '';
                                        }
                                        return "+{$state} 點";
                                    }),
                                Infolists\Components\TextEntry::make('pointTransaction.id')
                                    ->label('交易編號')
                                    ->size('lg')
                                    ->alignCenter()
                                    ->copyable()
                                    ->icon('heroicon-m-clipboard-document')
                                    ->iconColor('success')
                                    ->helperText('可複製此交易編號進行查詢')
                                    ->formatStateUsing(function ($state, $record) {
                                        if ($record->status !== RewardGrant::STATUS_GRANTED || !$record->pointTransaction) {
                                            return '';
                                        }
                                        return "交易 #{$state}";
                                    }),
                            ]),
                    ])
                    ->visible(fn($record) => $record->status === RewardGrant::STATUS_GRANTED && $record->pointTransaction)
                    ->collapsible(false)
                    ->extraAttributes(['class' => 'bg-success-50 dark:bg-success-950/20 border-success-500']),

                Section::make('發放失敗資訊')
                    ->description('此筆獎勵發放失敗的詳細原因')
                    ->columnSpanFull()
                    ->schema([
                        Infolists\Components\TextEntry::make('failure_reason')
                            ->label('失敗原因')
                            ->color('danger')
                            ->size('lg')
                            ->weight(FontWeight::Medium),
                    ])
                    ->visible(fn($record) => $record->status === RewardGrant::STATUS_FAILED)
                    ->collapsible(false)
                    ->extraAttributes(['class' => 'border-danger-500']),

                Section::make('系統資訊')
                    ->description('此筆記錄的系統追蹤資訊')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Infolists\Components\TextEntry::make('tenant.name')
                                    ->label('租戶')
                                    ->icon('heroicon-m-building-office')
                                    ->iconColor('gray')
                                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('建立時間')
                                    ->icon('heroicon-m-calendar')
                                    ->iconColor('gray')
                                    ->dateTime(),
                                Infolists\Components\TextEntry::make('updated_at')
                                    ->label('更新時間')
                                    ->icon('heroicon-m-arrow-path')
                                    ->iconColor('gray')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }
}
