<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\RewardGrant;
use App\Filament\Resources\RewardGrantResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class RewardGrantResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = RewardGrant::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '獎勵發放';
    protected static ?string $pluralModelLabel = '獎勵發放';
    protected static ?string $navigationLabel = '獎勵發放';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 完全跟CustomerResource保持一致的写法，使用applyTenantScoping统一处理
        return static::applyTenantScoping($query, [
            'tenant',
            'campaign',
            'campaignReward',
            'customer',
            'pointTransaction',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \App\Forms\Components\TenantSelect::make(),
                Forms\Components\Select::make('campaign_id')
                    ->label('活動')
                    ->relationship('campaign', 'name', function ($query, $get) {
                        $user = auth()->user();
                        $panel = filament()->getCurrentOrDefaultPanel();
                        $tenantId = $get('tenant_id');

                        if ($user && $user->isSuperAdmin()) {
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                            if ($tenantId) {
                                $query->where('tenant_id', $tenantId);
                            }
                        }

                        return $query;
                    })
                    ->required()
                    ->disabled(fn($record) => $record !== null)
                    ->reactive(),
                Forms\Components\Select::make('campaign_reward_id')
                    ->label('獎勵')
                    ->relationship('campaignReward', 'id', function ($query, $get) {
                        $campaignId = $get('campaign_id');
                        if ($campaignId) {
                            $query->where('campaign_id', $campaignId);
                        }
                        return $query;
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => "{$record->id} - {$record->reward_type}")
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('customer_id')
                    ->label('客戶')
                    ->relationship('customer', 'name', function ($query, $get) {
                        $user = auth()->user();
                        $panel = filament()->getCurrentOrDefaultPanel();
                        $tenantId = $get('tenant_id');

                        if ($user && $user->isSuperAdmin()) {
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                            if ($tenantId) {
                                $query->where('tenant_id', $tenantId);
                            }
                        }

                        return $query;
                    })
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('status')
                    ->label('狀態')
                    ->options([
                        RewardGrant::STATUS_PENDING => '待處理',
                        RewardGrant::STATUS_GRANTED => '已發放',
                        RewardGrant::STATUS_FAILED => '失敗',
                    ])
                    ->required()
                    ->disabled(),
                Forms\Components\DateTimePicker::make('granted_at')
                    ->label('發放時間')
                    ->disabled(),
                Forms\Components\Textarea::make('failure_reason')
                    ->label('失敗原因')
                    ->columnSpanFull()
                    ->disabled(),
                Forms\Components\Select::make('point_transaction_id')
                    ->label('點數交易')
                    ->relationship('pointTransaction', 'id', function ($query, $get) {
                        $user = auth()->user();
                        $panel = filament()->getCurrentOrDefaultPanel();
                        $tenantId = $get('tenant_id');

                        if ($user && $user->isSuperAdmin()) {
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                            if ($tenantId) {
                                $query->where('tenant_id', $tenantId);
                            }
                        }

                        return $query;
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => "交易 #{$record->id} - {$record->amount}點")
                    ->disabled(),
            ]);
    }

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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRewardGrants::route('/'),
            'view' => Pages\ViewRewardGrant::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canCreate(): bool
    {
        // 管理員不應該手動建立獎勵發放，應該由系統自動處理
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // RewardGrant是系統自動產生的記錄，禁止編輯
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // RewardGrant是系統自動產生的記錄，禁止刪除以保持歷史資料一致性
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('獎勵發放結果')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make()
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('status')
                                    ->label('狀態')
                                    ->badge()
                                    ->size('lg')
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
                                    }),
                            ]),
                        \Filament\Schemas\Components\Grid::make(2)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('customer.name')
                                    ->label('客戶')
                                    ->icon('heroicon-o-user')
                                    ->size('xl')
                                    ->weight('bold'),
                                \Filament\Infolists\Components\TextEntry::make('campaign.name')
                                    ->label('活動')
                                    ->icon('heroicon-o-megaphone')
                                    ->size('xl')
                                    ->weight('bold'),
                            ]),
                        \Filament\Schemas\Components\Grid::make(2)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('campaignReward.reward_type')
                                    ->label('獎勵類型')
                                    ->icon('heroicon-o-gift')
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
                                \Filament\Infolists\Components\TextEntry::make('granted_at')
                                    ->label('發放時間')
                                    ->icon('heroicon-o-clock')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsible(false),

                \Filament\Schemas\Components\Section::make('發放內容')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(2)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('campaignReward.id')
                                    ->label('獎勵項目')
                                    ->icon('heroicon-o-tag')
                                    ->formatStateUsing(fn(int $state): string => "#{$state}"),
                                \Filament\Infolists\Components\TextEntry::make('pointTransaction.id')
                                    ->label('點數交易')
                                    ->icon('heroicon-o-currency-dollar')
                                    ->copyable()
                                    ->placeholder('無點數交易記錄')
                                    ->formatStateUsing(fn(int|null $state): string => $state ? "交易 #{$state}" : ''),
                            ]),
                    ])
                    ->collapsible(false),

                \Filament\Schemas\Components\Section::make('發放結果')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('pointTransaction.amount')
                            ->label('')
                            ->size('3xl')
                            ->weight('bold')
                            ->color('success')
                            ->alignCenter()
                            ->placeholder('')
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->status !== RewardGrant::STATUS_GRANTED || !$record->pointTransaction) {
                                    return '';
                                }
                                return "+{$state} 點";
                            }),
                        \Filament\Infolists\Components\TextEntry::make('pointTransaction.id')
                            ->label('')
                            ->size('lg')
                            ->alignCenter()
                            ->placeholder('')
                            ->formatStateUsing(function ($state, $record) {
                                if ($record->status !== RewardGrant::STATUS_GRANTED || !$record->pointTransaction) {
                                    return '';
                                }
                                return '已建立點數交易';
                            }),
                    ])
                    ->visible(fn($record) => $record->status === RewardGrant::STATUS_GRANTED && $record->pointTransaction)
                    ->collapsible(false),

                \Filament\Schemas\Components\Section::make('發放失敗資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('failure_reason')
                            ->label('失敗原因')
                            ->color('danger')
                            ->size('lg'),
                    ])
                    ->visible(fn($record) => $record->status === RewardGrant::STATUS_FAILED)
                    ->collapsible(false)
                    ->extraAttributes(['class' => 'border-danger-500']),

                \Filament\Schemas\Components\Section::make('系統資訊')
                    ->schema([
                        \Filament\Schemas\Components\Grid::make(3)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('tenant.name')
                                    ->label('租戶')
                                    ->icon('heroicon-o-building-office')
                                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                                \Filament\Infolists\Components\TextEntry::make('created_at')
                                    ->label('建立時間')
                                    ->icon('heroicon-o-calendar')
                                    ->dateTime(),
                                \Filament\Infolists\Components\TextEntry::make('updated_at')
                                    ->label('更新時間')
                                    ->icon('heroicon-o-arrow-path')
                                    ->dateTime(),
                            ]),
                    ])
                    ->collapsible(),
            ]);
    }
}
